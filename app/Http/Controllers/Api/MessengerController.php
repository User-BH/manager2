<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Complex;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        $base = Message::where('complex_id', $complex->id)->visibleTo($user)->with('recipientUnits:id,unit_number');
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

        $message = DB::transaction(function () use ($complex, $user, $data, $audience, $senderUnitId) {
            $message = Message::create([
                'complex_id' => $complex->id,
                'user_id' => $user->id,
                'body' => $data['body'],
                'audience' => $audience,
                'unit_id' => $senderUnitId,
                'author_name' => $user->name,
                'author_role' => $user->role->value,
                'unit_label' => $this->unitLabel($user),
            ]);

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
        });

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
