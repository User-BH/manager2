<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReadConversationRequest;
use App\Http\Requests\SendConversationMessageRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Resources\MessageResource;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Messenger\MessageSender;
use App\Support\CursorPage;
use App\Support\Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * پیام‌رسانِ گفت‌وگومحور (مرحلهٔ ۶ · فاز ۱۲).
 *
 * ─── قاعده‌ی دسترسی ─────────────────────────────────────────────────────────
 * گفت‌وگو با بایندینگِ مسیر خوانده می‌شود که دامنه‌ی مستأجر دارد — گفت‌وگوی
 * مجتمعِ دیگر اصلاً پیدا نمی‌شود. سپس `Conversation::visibleTo`؛ نادیدنی
 * ۴۰۴ است نه ۴۰۳، تا وجودِ یک گفت‌وگوی خصوصی هم لو نرود.
 *
 * ⚠️ بی‌درنگ نیست (تصمیمِ ۲): کلاینت با `since` پیام‌های تازه را می‌پرسد.
 */
class ConversationController extends Controller
{
    /** پیام در هر صفحه‌ی تاریخچه. */
    private const HISTORY_PAGE = 40;

    /** سقفِ پیام‌های تازه در یک پرسش. */
    private const SINCE_LIMIT = 100;

    private const MESSAGE_RELATIONS = ['recipientUnits:id,unit_number', 'readers:id', 'poll.options', 'poll.votes'];

    public function __construct(private readonly ConversationDirectory $directory) {}

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $complex = $this->messengerComplex($user);

        if (! $complex) {
            return response()->json([
                'data' => [], 'hasMore' => false, 'nextCursor' => null,
                'canSend' => false, 'reason' => 'ابتدا یک مجتمع را انتخاب کنید.', 'isAdmin' => $user->isAdmin(),
                'unreadTotal' => 0, 'units' => [],
            ]);
        }

        /*
         * کانال همیشه هست؛ رشته‌ی خصوصیِ ساکن با مدیریت هم — پیش از اولین پیام،
         * تا جایی برای نوشتن داشته باشد. هر دو «یافتن یا ساختن»‌اند و تکرارشان
         * چیزی نمی‌سازد.
         */
        if ($request->query('cursor') === null) {
            $this->directory->channel($complex);

            if (! $user->role->isAdmin()) {
                $this->directory->ownThreads($complex, $user);
            }
        }

        return response()->json([
            ...$this->directory->page($user, $request->query('cursor') ? (string) $request->query('cursor') : null),
            'canSend' => $complex->messenger_enabled && $user->can_message,
            'reason' => $this->blockReason($complex, $user),
            'isAdmin' => $user->isAdmin(),
            'unreadTotal' => Notifications::messengerUnread($user),
            // فهرستِ واحدها فقط برای مدیر، برای شروعِ رشته یا ساختِ گروه
            'units' => $user->role->isAdmin()
                ? Unit::orderBy('unit_number')->get(['id', 'unit_number'])
                    ->map(fn (Unit $u) => ['id' => $u->id, 'label' => 'واحد '.$u->unit_number])->values()
                : [],
        ]);
    }

    /** رشته‌ی خصوصی با یک واحد (یافتن یا ساختن)، یا گروهِ تازه. فقط مدیریت. */
    public function store(StoreConversationRequest $request): JsonResponse
    {
        $user = Auth::user();
        $complex = $this->requireComplex();
        $data = $request->validated();

        $conversation = $data['type'] === Conversation::UNIT
            ? $this->directory->unitThread($complex, Unit::findOrFail($data['unit_id']))
            : $this->directory->createGroup(
                $complex,
                trim($data['title']),
                Unit::whereIn('id', $data['unit_ids'])->pluck('id')->map(fn ($id) => (int) $id)->all(),
                $request->boolean('members_can_post', true),
                $user,
            );

        return response()->json(['conversation' => $this->directory->present($conversation->fresh(), $user)], 201);
    }

    /**
     * پیام‌های یک گفت‌وگو.
     *
     * بدونِ پارامتر: تازه‌ترین صفحه. `before`: صفحه‌ی قدیمی‌ترِ پیش از آن شناسه
     * (نشانگر، نه OFFSET — پیامِ تازه صفحه‌ها را جابه‌جا نمی‌کند). `since`: فقط
     * پیام‌های تازه‌تر، برای پرسشِ دوره‌ای.
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();
        $this->ensureVisible($conversation, $user);

        $base = Message::query()->where('conversation_id', $conversation->id)->with(self::MESSAGE_RELATIONS);
        $present = fn (Message $m) => $this->present($m, $user);

        if (($since = $request->integer('since')) > 0) {
            $messages = (clone $base)->where('id', '>', $since)->orderBy('id')->limit(self::SINCE_LIMIT)->get();
            $page = ['data' => $messages->map($present)->values()->all(), 'hasMore' => false, 'nextCursor' => null];
        } else {
            $before = $request->integer('before');
            $page = CursorPage::descending(clone $base, $before > 0 ? $before : null, self::HISTORY_PAGE, $present);
            // برای نمایش قدیمی‌ترین بالا
            $page['data'] = array_reverse($page['data']);
        }

        return response()->json([
            'messages' => $page['data'],
            'hasOlder' => $page['hasMore'],
            'nextCursor' => $page['nextCursor'],
            // پیامی که پس از رسیدن به کلاینت پنهان شده، با پرسشِ `since` برنمی‌گشت
            'hiddenIds' => Message::where('conversation_id', $conversation->id)->where('is_hidden', true)->pluck('id')->all(),
            /*
             * 🔴 وضعیتِ «خوانده شد» برای پیام‌هایی که **از قبل** در کلاینت‌اند.
             *
             * پرسشِ `since` فقط پیامِ تازه می‌آورد؛ آزمونِ مرورگر نشان داد پیامِ
             * خودِ ساکن پس از خواندنِ مدیر برای همیشه «ارسال شد» می‌ماند. رسیدِ
             * خواندن «تا این پیام» است، پس بزرگ‌ترین پیامِ من که کسِ دیگری
             * خوانده کافی است: هر پیامِ من تا آن‌جا خوانده شده.
             */
            'readUpTo' => (int) DB::table('message_reads')
                ->join('messages', 'messages.id', '=', 'message_reads.message_id')
                ->where('messages.conversation_id', $conversation->id)
                ->where('messages.user_id', $user->id)
                ->where('message_reads.user_id', '!=', $user->id)
                ->max('message_reads.message_id'),
            'conversation' => $this->directory->present($conversation, $user),
            'canSend' => $this->canSend($conversation, $user),
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    public function send(SendConversationMessageRequest $request, Conversation $conversation, MessageSender $sender): JsonResponse
    {
        $user = Auth::user();
        $this->ensureVisible($conversation, $user);

        [$message, $created] = $sender->send(
            $this->requireComplex(),
            $conversation,
            $user,
            $request->validated(),
            $request->file('attachment'),
        );

        // تکراری ۲۰۰ است نه ۲۰۱: کلاینت می‌فهمد این همان پیامِ قبلی است
        return response()->json(
            ['message' => $this->present($message->fresh(self::MESSAGE_RELATIONS), $user)],
            $created ? 201 : 200,
        );
    }

    /**
     * «تا این پیام خواندم»: همه‌ی پیام‌های دیگرانِ همین گفت‌وگو تا `up_to`.
     *
     * ⚠️ فقط در گفت‌وگویی که کاربر می‌بیند، و فقط شناسه‌های **همان** گفت‌وگو —
     * شناسه‌ی پیامِ گفت‌وگوی دیگر در `up_to` چیزی را علامت نمی‌زند.
     */
    public function read(ReadConversationRequest $request, Conversation $conversation): JsonResponse
    {
        $user = Auth::user();
        $this->ensureVisible($conversation, $user);

        $ids = Message::where('conversation_id', $conversation->id)
            ->where('id', '<=', $request->integer('up_to'))
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->whereDoesntHave('readers', fn ($q) => $q->where('users.id', $user->id))
            ->pluck('id');

        foreach ($ids->chunk(500) as $chunk) {
            // یکتاییِ (message_id, user_id): دو تبِ هم‌زمان رسیدِ تکراری نمی‌سازند
            DB::table('message_reads')->insertOrIgnore($chunk->map(fn ($id) => [
                'message_id' => $id, 'user_id' => $user->id, 'read_at' => now(),
            ])->values()->all());
        }

        return response()->json(['marked' => $ids->count(), 'unreadTotal' => Notifications::messengerUnread($user)]);
    }

    private function ensureVisible(Conversation $conversation, User $user): void
    {
        abort_unless($conversation->isVisibleTo($user), 404);
    }

    private function canSend(Conversation $conversation, User $user): bool
    {
        $complex = Complex::find($conversation->complex_id);

        return (bool) $complex?->messenger_enabled && $user->can_message && $conversation->allowsPostingBy($user);
    }

    /** @return array<string, mixed> */
    private function present(Message $message, User $viewer): array
    {
        return (new MessageResource($message, $viewer))->toArray(request());
    }

    private function blockReason(Complex $complex, User $user): ?string
    {
        return match (true) {
            ! $complex->messenger_enabled => 'پیام‌رسان این مجتمع توسط مدیر بسته شده است.',
            ! $user->can_message => 'ارسال پیام برای شما محدود شده است.',
            default => null,
        };
    }

    private function messengerComplex(User $user): ?Complex
    {
        return $user->isSuperAdmin()
            ? (session('active_complex_id') ? Complex::find(session('active_complex_id')) : null)
            : $user->complex;
    }
}
