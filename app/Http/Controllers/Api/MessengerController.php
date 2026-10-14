<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageAudience;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Complex;
use App\Models\Message;
use App\Models\MessagePoll;
use App\Models\MessageRead;
use App\Models\PollOption;
use App\Models\Unit;
use App\Models\User;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Messenger\MessageActions;
use App\Services\Messenger\MessageSender;
use App\Services\Messenger\MessagingAccess;
use App\Services\Poll\PollService;
use App\Support\Notifications;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * پیام‌رسان داخلی مجتمع.
 *
 * همان منطق کامپوننت Livewire، فقط JSON برمی‌گرداند. کلاینت هر چند ثانیه
 * `since` را می‌فرستد تا فقط پیام‌های جدید را بگیرد، نه کل تاریخچه را.
 */
class MessengerController extends Controller
{
    /** تعداد پیامی که در بارگذاری اول برمی‌گردد. */
    private const WINDOW = 200;

    public function __construct(private readonly PollService $polls) {}

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $complex = $this->messengerComplex();

        if (! $complex) {
            return response()->json([
                'messages' => [],
                'canSend' => false,
                'reason' => 'ابتدا یک مجتمع را انتخاب کنید.',
            ]);
        }

        /*
         * `visibleTo` تنها جایی است که تصمیم می‌گیرد چه کسی چه پیامی را
         * می‌بیند (R23). پیش از این پیام‌رسان یک کانالِ واحد بود و هر پیام
         * به همه می‌رسید.
         */
        $base = Message::where('complex_id', $complex->id)->visibleTo($user)->with(MessageResource::RELATIONS);
        $total = (clone $base)->count();

        if ($since = $request->integer('since')) {
            // واکشی افزایشی: فقط پیام‌های بعد از آخرین شناسه‌ای که کلاینت دارد
            $messages = (clone $base)->where('id', '>', $since)->orderBy('id')->get();
        } else {
            /*
             * تازه‌ترین ۲۰۰ پیام، نه قدیمی‌ترین.
             *
             * پیش از این مرتب‌سازی صعودی با `limit` ترکیب می‌شد، یعنی ۲۰۰ تای
             * *اول* برمی‌گشت. هر مجتمعی که از ۲۰۰ پیام می‌گذشت، کاربر تازه‌وارد
             * تاریخچه‌ی باستانی می‌دید و گفت‌وگوی جاری برایش نامرئی می‌شد.
             * اینجا نزولی می‌گیریم و بعد برای نمایش برمی‌گردانیم.
             */
            $messages = (clone $base)->orderByDesc('id')->limit(self::WINDOW)->get()->reverse()->values();
        }

        return response()->json([
            'messages' => $messages->map(fn (Message $m) => $this->present($m, $user))->values(),
            // آیا پیام قدیمی‌تری بیرون از این پنجره مانده؟ کلاینت با این، به‌جای
            // اینکه وانمود کند تاریخچه از اینجا شروع شده، به کاربر می‌گوید.
            'hasOlder' => $since ? false : $total > self::WINDOW,
            'unreadCount' => Notifications::messengerUnread($user),
            /*
             * شناسه‌ی پیام‌های مخفی‌شده، مستقل از پنجره‌ی واکشی.
             *
             * واکشی افزایشی فقط پیام‌های جدیدتر از `since` را می‌آورد، پس
             * کلاینتی که پیامی را پیش از مخفی‌شدنش گرفته، هرگز خبردار
             * نمی‌شد و متن را روی صفحه نگه می‌داشت. با این فهرست، نسخه‌ی
             * کهنه‌ی خودش را هم پاک می‌کند.
             */
            'hiddenIds' => Message::where('complex_id', $complex->id)
                ->visibleTo($user)
                ->where('is_hidden', true)->pluck('id')->all(),

            /*
             * فهرستِ واحدها فقط برای مدیر، تا بتواند گیرنده انتخاب کند.
             * ساکن این را نمی‌گیرد؛ نه لازمش دارد و نه باید فهرستِ واحدهای
             * مجتمع را ببیند.
             */
            'units' => $user->role->isAdmin()
                ? Unit::orderBy('unit_number')->get(['id', 'unit_number'])
                    ->map(fn (Unit $u) => ['id' => $u->id, 'label' => 'واحد '.$u->unit_number])
                    ->values()
                : [],
            'canSend' => MessagingAccess::allowed($user, $complex),
            'reason' => $this->blockReason($complex, $user),
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    /**
     * ارسال از API قدیم (پیش از فاز ۱۲) — ⚠️ فقط برای تب‌های بازی که هنوز
     * جاوااسکریپتِ قبل را دارند (`API_COMPATIBILITY.md`). با گامِ Contract حذف
     * می‌شود.
     *
     * مخاطبِ قدیم به گفت‌وگوی متناظرش نگاشت می‌شود و نوشتن از همان
     * `MessageSender`ِ API تازه می‌گذرد؛ پس پیامِ نسخه‌ی قدیم هم گفت‌وگو دارد و
     * در رابطِ تازه دیده می‌شود.
     */
    public function store(StoreMessageRequest $request, ConversationDirectory $directory, MessageSender $sender): JsonResponse
    {
        $user = Auth::user();

        // همان پیش‌نیاز، ولی با کدِ ماشین‌خوان تا فرانت انتخابگرِ مجتمع را باز کند
        $complex = $this->requireComplex();

        $data = $request->validated();
        $audience = $this->resolveAudience($user, $data);

        $conversation = match ($audience) {
            MessageAudience::All => $directory->channel($complex),
            MessageAudience::Units => $directory->groupForUnits(
                $complex,
                // دامنه‌ی مستأجر: واحدِ مجتمعِ دیگر اینجا پیدا نمی‌شود
                Unit::whereIn('id', $data['unit_ids'])->pluck('id')->map(fn ($id) => (int) $id)->all(),
                $user,
            ),
            // «به مدیریت»: گفت‌وگوی خصوصیِ خودش با مدیریت (۱۰/۹ — رشته‌ی مشترکِ واحد دیگر نوشته نمی‌شود)
            MessageAudience::Management => $directory->userThread($complex, $user),
        };

        [$message] = $sender->send($complex, $conversation, $user, $data, $request->file('attachment'));

        return response()->json(['message' => $this->present($message->fresh(['recipientUnits', 'readers', 'poll.options', 'poll.votes']), $user)], 201);
    }

    /**
     * مخاطبِ این پیام چیست؟ (R23)
     *
     * ساکن انتخابی ندارد: هر چه بفرستد به مدیریت می‌رود. حتی اگر `audience`
     * را در درخواست دست‌کاری کند، اینجا نادیده گرفته می‌شود — قاعده سمتِ
     * سرور اعمال می‌شود، نه با پنهان‌کردنِ گزینه در رابط.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveAudience(User $user, array $data): MessageAudience
    {
        if (! $user->role->isAdmin()) {
            return MessageAudience::Management;
        }

        $requested = MessageAudience::tryFrom((string) ($data['audience'] ?? '')) ?? MessageAudience::All;

        // «به واحدهای انتخابی» بدونِ انتخاب، عملاً یعنی «به همه» — که خطرناک است
        return $requested === MessageAudience::Units && empty($data['unit_ids'])
            ? MessageAudience::All
            : $requested;
    }

    /**
     * علامت‌زدنِ پیام‌ها به‌عنوان خوانده‌شده (R23b).
     *
     * فقط پیام‌هایی که کاربر **حق دیدنشان را دارد** علامت می‌خورند؛ وگرنه
     * می‌شد با فرستادنِ شناسه‌های دلخواه فهمید کدام شناسه‌ها پیامِ واقعی‌اند.
     */
    public function markRead(Request $request): JsonResponse
    {
        $user = Auth::user();

        $ids = Message::where('complex_id', $this->requireComplex()->id)
            ->visibleTo($user)
            ->whereIn('id', (array) $request->input('ids', []))
            ->pluck('id');

        foreach ($ids as $id) {
            /*
             * `firstOrCreate` و نه `create`: رسیدِ خواندن باید idempotent
             * باشد، چون کلاینت هنگام اسکرول ممکن است یک شناسه را چند بار
             * بفرستد.
             */
            MessageRead::firstOrCreate(
                ['message_id' => $id, 'user_id' => $user->id],
                ['read_at' => now()],
            );
        }

        return response()->json(['marked' => $ids->count()]);
    }

    /** ثبت یا تعویضِ رأی در نظرسنجی (R23b). */
    public function vote(Request $request, MessagePoll $poll): JsonResponse
    {
        $user = Auth::user();

        // نظرسنجی روی پیامی است که کاربر باید حقِ دیدنش را داشته باشد
        $visible = Message::where('complex_id', $this->requireComplex()->id)
            ->visibleTo($user)
            ->whereKey($poll->message_id)
            ->exists();

        abort_unless($visible, 404);

        // یک گزینه (`option_id`، کلاینتِ پیشین) یا چند (`option_ids`، چندگزینه‌ای — ۱۰/۱۱)
        $requested = $request->has('option_ids')
            ? array_map('intval', array_filter((array) $request->input('option_ids'), 'is_numeric'))
            : [$request->integer('option_id')];
        $options = PollOption::whereKey($requested)->get()->all();

        // گزینه‌ی تکراری خطاست، نه یکی‌شده: کلاینتی که دو بار یک گزینه می‌فرستد چیزی را اشتباه فهمیده
        if ($requested === [] || count(array_unique($requested)) !== count($requested) || count($options) !== count($requested)) {
            throw DomainException::invalid('گزینه‌ی انتخابی معتبر نیست.', 'poll.invalid_option');
        }

        /*
         * واجدِ شرایط بودن، قفل و وزن همه در سرویس‌اند (R24) — نه اینجا.
         * همان منطق را کارتِ داشبورد و `MessageResource` هم مصرف می‌کنند و
         * نباید سه نسخه‌ی جدا داشته باشد.
         */
        $this->polls->castVote($poll, $user, $options);

        return response()->json([
            'message' => 'رأی شما ثبت شد.',
            'poll' => $this->polls->results($poll->fresh(['options', 'votes']), $user),
        ]);
    }

    /**
     * بستنِ نظرسنجی توسط مدیر (R24).
     *
     * تا وقتی نظرسنجی باز است برنده اعلام نمی‌شود، پس بستن فقط «دیگر رأی
     * نگیر» نیست — همان لحظه‌ای است که نتیجه رسمی می‌شود.
     */
    public function closePoll(MessagePoll $poll): JsonResponse
    {
        $user = Auth::user();

        /*
         * ۴۰۴ پیش از مجوزدهی: پیامِ مجتمعِ دیگری اصلاً نباید وجودش تایید
         * شود، حتی برای کسی که در مجتمعِ خودش مدیر است.
         */
        $message = Message::where('complex_id', $this->requireComplex()->id)
            ->whereKey($poll->message_id)
            ->firstOrFail();

        $this->authorize('closePoll', $message);

        $poll->closeNow();

        return response()->json([
            'message' => 'نظرسنجی بسته شد.',
            'poll' => $this->polls->results($poll->fresh(['options', 'votes']), $user),
        ]);
    }

    /** سروِ پیوستِ پیام از دیسکِ خصوصی. */
    public function attachment(Message $message): Response
    {
        $user = Auth::user();

        /*
         * ۴۰۴ و نه ۴۰۳: وجودِ یک پیوست هم اطلاعات است. دامنه‌ی دید همان
         * `visibleTo` است، پس پیوستِ گفت‌وگوی واحدِ دیگری قابل دریافت نیست.
         */
        $visible = Message::where('complex_id', $this->requireComplex()->id)
            ->visibleTo($user)
            ->whereKey($message->id)
            ->exists();

        abort_unless($visible && $message->hasAttachment(), 404);

        // نوع، درون‌خطی یا دانلود، و Range از نوعِ پیوست (۱۰/۱۲)
        return Uploads::serveAttachment($message->attachment_path, (string) $message->attachment_kind, $message->attachment_name);
    }

    /** مخفی/آشکار کردن پیام توسط مدیر. */
    public function toggleHide(Message $message): JsonResponse
    {
        $user = Auth::user();
        // پیامِ گفت‌وگوی خصوصیِ دیگران برای مدیر «نیست»، نه «ممنوع» (۱۰/۹)
        abort_unless(Message::query()->visibleTo($user)->whereKey($message->id)->exists(), 404);
        $this->authorize('hide', $message);

        $message->update([
            'is_hidden' => ! $message->is_hidden,
            'hidden_by' => $user->id,
        ]);

        // نقلِ این پیام در پاسخ‌ها هم باید با پنهان‌شدنش عوض شود (فاز ۱۴)
        app(MessageActions::class)->touchReplies($message->id);

        return response()->json(['message' => $this->present($message->fresh(), $user)]);
    }

    /**
     * شکلِ خروجی حالا در `MessageResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Message $message, User $viewer): array
    {
        return (new MessageResource($message, $viewer))->toArray(request());
    }

    private function blockReason(Complex $complex, User $user): ?string
    {
        if (! $complex->messenger_enabled) {
            return 'پیام‌رسان این مجتمع توسط مدیر بسته شده است.';
        }

        if (MessagingAccess::blocked($user, $complex->id)) {
            return 'ارسال پیام برای شما در این مجتمع محدود شده است.';
        }

        return null;
    }

    private function messengerComplex(): ?Complex
    {
        $user = Auth::user();

        return $user->isSuperAdmin()
            ? (session('active_complex_id') ? Complex::find(session('active_complex_id')) : null)
            : $user->complex;
    }
}
