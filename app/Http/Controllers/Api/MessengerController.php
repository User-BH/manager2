<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageAudience;
use App\Enums\PollVoterScope;
use App\Enums\PollWeightMode;
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
use App\Services\Poll\PollService;
use App\Support\Notifications;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $base = Message::where('complex_id', $complex->id)->visibleTo($user)->with(['recipientUnits:id,unit_number', 'readers:id', 'poll.options', 'poll.votes']);
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
            'canSend' => $complex->messenger_enabled && $user->can_message,
            'reason' => $this->blockReason($complex, $user),
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    public function store(StoreMessageRequest $request): JsonResponse
    {
        $user = Auth::user();
        $complex = $this->messengerComplex();

        // همان پیش‌نیاز، ولی با کدِ ماشین‌خوان تا فرانت انتخابگرِ مجتمع را باز کند
        $complex = $this->requireComplex();

        if (! $complex->messenger_enabled || ! $user->can_message) {
            abort(403, 'امکان ارسال پیام برای شما فعال نیست.');
        }

        $data = $request->validated();

        $audience = $this->resolveAudience($user, $data);

        /*
         * پیامِ ساکن به رشته‌ی **واحدِ خودش** بسته می‌شود، نه به خودش.
         * بقیه‌ی سامانه هم واحد-محور است و مالک/مستاجرِ یک واحد باید یک
         * گفت‌وگوی مشترک با مدیریت داشته باشند، نه دو رشته‌ی جدا که مدیر
         * باید حدس بزند کدام را جواب بدهد.
         *
         * ⚠️ ساکنی که هنوز واحدی به او تخصیص نیافته `null` می‌گیرد و **باز
         * هم می‌تواند پیام بدهد**. اول جلویش گرفته شده بود، ولی تستِ موجودِ
         * R21 نشان داد کسی که با پذیرشِ دعوت عضو شده و هنوز واحد نگرفته،
         * اصلاً نمی‌تواند با مدیریت حرف بزند — یعنی همان کسی که بیشتر از همه
         * به تماس با مدیر نیاز دارد. پیامش به مدیریت می‌رسد و رشته‌ی واحد
         * ندارد.
         */
        $senderUnitId = $audience === MessageAudience::Management
            ? $user->units()->value('units.id')
            : null;

        $attachment = $request->file('attachment');

        $write = function (?string $path) use ($complex, $user, $data, $audience, $senderUnitId, $attachment, $request) {
            $message = Message::create([
                'complex_id' => $complex->id,
                'user_id' => $user->id,
                'body' => $data['body'] ?? '',
                'audience' => $audience,
                'unit_id' => $senderUnitId,
                'attachment_path' => $path,
                'attachment_name' => $attachment ? Uploads::safeOriginalName($attachment) : null,
                'attachment_kind' => $attachment ? $this->attachmentKind($attachment) : null,
                'author_name' => $user->name,
                'author_role' => $user->role->value,
                'unit_label' => $this->unitLabel($user),
            ]);

            /*
             * نظرسنجی خودش پیام است (R23b) — پس مخاطب‌دهی، مخفی‌کردن و رسیدِ
             * خواندنش همان مسیرِ پیام را می‌رود و دوباره نوشته نمی‌شود.
             */
            if ($request->filled('poll_question') && $user->role->isAdmin()) {
                $poll = $message->poll()->create([
                    'question' => $request->string('poll_question')->trim()->value(),
                    'voter_scope' => $request->input('poll_voter_scope', PollVoterScope::Residents->value),
                    'weight_mode' => $request->input('poll_weight_mode', PollWeightMode::PerPerson->value),
                    'quorum_percent' => $request->input('poll_quorum_percent'),
                    'allow_change' => $request->boolean('poll_allow_change', true),
                    'closes_at' => $request->input('poll_closes_at'),
                ]);

                foreach (array_values($request->input('poll_options', [])) as $index => $label) {
                    $poll->options()->create([
                        'label' => (string) $label,
                        'sort_order' => $index,
                    ]);
                }
            }

            return $message;
        };

        $message = DB::transaction(function () use ($write, $attachment, $audience, $data, $complex) {
            /*
             * `keepIf` تضمین می‌کند فایلِ پیوست بدونِ پیامِ متناظر روی دیسک
             * نماند (درسِ R19). دیسک خصوصی است و پیوست فقط از مسیرِ
             * کنترل‌شده سرو می‌شود — پیوستِ یک گفت‌وگوی خصوصی نباید مستقیم
             * از public خوانده شود.
             */
            $message = $attachment
                ? Uploads::keepIf($attachment, 'messages/'.$complex->id, $write)
                : $write(null);

            if ($audience === MessageAudience::Units) {
                /*
                 * شناسه‌ها از `Unit` خوانده می‌شوند که دامنه‌ی مستأجر دارد،
                 * پس واحدِ مجتمعِ دیگری حتی اگر در درخواست بیاید، اینجا پیدا
                 * نمی‌شود و ضمیمه نمی‌گردد.
                 */
                $units = Unit::whereIn('id', $data['unit_ids'])->pluck('id');
                $message->recipientUnits()->sync($units);
            }

            return $message;
        }, attempts: 3);

        return response()->json(['message' => $this->present($message, $user)], 201);
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

        $option = PollOption::whereKey($request->integer('option_id'))->first();

        if (! $option) {
            throw DomainException::invalid('گزینه‌ی انتخابی معتبر نیست.', 'poll.invalid_option');
        }

        /*
         * واجدِ شرایط بودن، قفل و وزن همه در سرویس‌اند (R24) — نه اینجا.
         * همان منطق را کارتِ داشبورد و `MessageResource` هم مصرف می‌کنند و
         * نباید سه نسخه‌ی جدا داشته باشد.
         */
        $this->polls->castVote($poll, $user, $option);

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
    public function attachment(Message $message): StreamedResponse
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

        return Uploads::serve($message->attachment_path);
    }

    /** `image` درون‌خطی نشان داده می‌شود؛ بقیه دانلود می‌شوند. */
    private function attachmentKind(UploadedFile $file): string
    {
        return str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'file';
    }

    /** مخفی/آشکار کردن پیام توسط مدیر. */
    public function toggleHide(Message $message): JsonResponse
    {
        $user = Auth::user();
        $this->authorize('hide', $message);

        $message->update([
            'is_hidden' => ! $message->is_hidden,
            'hidden_by' => $user->id,
        ]);

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

        if (! $user->can_message) {
            return 'ارسال پیام برای شما محدود شده است.';
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

    private function unitLabel(User $user): string
    {
        if ($user->isAdmin()) {
            return 'مدیریت';
        }

        return $user->currentUnits()->first()?->label() ?? '-';
    }
}
