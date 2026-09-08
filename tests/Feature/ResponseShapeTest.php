<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شکلِ پاسخِ مسیرهای حیاتی عوض نمی‌شود (فاز ۶).
 *
 * ─── 🔴 شکافی که اندازه‌گیری نشان داد ──────────────────────────────────────
 * قراردادِ OpenAPI ۱۲۶ مسیر را مستند می‌کند و بدنهٔ درخواست‌ها را هم دارد.
 * ولی شمارشِ فاز ۶ چیزِ دیگری گفت:
 *
 *     پاسخِ ۲۰۰ با شِما:    ۰
 *     پاسخِ ۲۰۰ بدونِ شِما: ۱۵۶
 *
 * ⚠️ یعنی قرارداد می‌گوید **چه مسیری هست**، نه **چه چیزی برمی‌گردد**. و
 * `ApiContractTest` هم سازگاری را در سطحِ مسیر می‌سنجد.
 *
 * 🔴 نتیجه‌اش این بود: تغییرِ نامِ یک فیلد در پاسخ — یا برداشتنش — هیچ تستی
 * را قرمز نمی‌کرد. فرانتِ تبِ بازِ کاربر `undefined` می‌گرفت و صفحه بی‌صدا
 * خالی می‌شد.
 *
 * ─── چرا اسنپ‌شات و نه شِمای کامل برای هر ۱۲۶ مسیر ─────────────────────────
 * ⚠️ نوشتنِ شِمای دستی برای همهٔ مسیرها کارِ هفته‌هاست و اولین چیزی است که
 * کهنه می‌شود. اصلاحیه هم می‌گوید «برای APIهای **Critical**».
 *
 * پس شکل از خودِ پاسخِ واقعی استخراج می‌شود و با فایلِ ثبت‌شده مقایسه
 * می‌گردد.
 *
 * ─── قاعدهٔ مقایسه: افزودن مجاز، برداشتن ممنوع ─────────────────────────────
 * 🔴 این عمدی است و همان تعریفِ سازگاریِ رو به عقب است:
 *
 *   • فیلدِ تازه   → مجاز. کلاینتِ قدیمی نادیده‌اش می‌گیرد.
 *   • فیلدِ حذف‌شده → **ممنوع**. کلاینتِ قدیمی `undefined` می‌گیرد.
 *   • نوعِ عوض‌شده → **ممنوع**. بدتر از حذف است، چون خطا هم نمی‌دهد.
 */
class ResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = 'tests/fixtures/api-response-shapes.json';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $complex = Complex::create([
            'type' => 'residential',
            'name' => 'مجتمع قرارداد',
            'slug' => 'shape-'.uniqid(),
            'currency' => 'toman',
            'charge_due_day' => 10,
            'payment_gateway' => 'none',
            'messenger_enabled' => true,
        ]);

        $this->admin = User::factory()->create([
            'complex_id' => $complex->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        /*
         * ⚠️ کاربر به واحد وصل می‌شود، وگرنه `/my-bills` فهرستِ خالی
         * برمی‌گرداند و شکلِ خودِ قبض اصلاً ثبت نمی‌شود — یعنی محافظ
         * دربارهٔ مهم‌ترین پاسخ ساکت می‌ماند.
         */
        $this->admin->units()->attach($unit->id, [
            'complex_id' => $complex->id, 'relation' => 'owner',
            'share_percent' => 100, 'is_current' => true,
        ]);

        $bill = Bill::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, // ⚠️ دورهٔ جاری، وگرنه فهرست خالی برمی‌گردد و شکلِ قبض ثبت نمی‌شود
            'period' => Jalali::currentPeriod(),
            'total_amount' => 500000, 'paid_amount' => 0,
            'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);

        Payment::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'bill_id' => $bill->id,
            'user_id' => $this->admin->id, 'amount' => 1000,
            'method' => PaymentMethod::Receipt, 'status' => PaymentStatus::Pending,
        ]);
    }

    /**
     * مسیرهایی که شکستِ بی‌صدای شکلشان بیشترین هزینه را دارد.
     *
     * ⚠️ همه خواندنی‌اند و همه چیزی برمی‌گردانند که فرانت مستقیم رندر
     * می‌کند.
     *
     * @return list<string>
     */
    protected function criticalEndpoints(): array
    {
        return [
            '/api/v1/me',
            '/api/v1/dashboard',
            '/api/v1/units',
            '/api/v1/bills',
            // 🔴 قبض‌های خودِ ساکن — از BillResource می‌آید و مستقیم رندر می‌شود
            '/api/v1/my-bills',
            '/api/v1/payments',
            '/api/v1/finance',
            '/api/v1/notifications',
            '/api/v1/residents',
        ];
    }

    /**
     * شکلِ یک مقدار: کلیدها و نوعشان، بازگشتی.
     *
     * ⚠️ **مقدارها اصلاً وارد نمی‌شوند** — فقط ساختار. وگرنه اسنپ‌شات با
     * هر تغییرِ دادهٔ آزمایشی می‌شکست و کسی جدی‌اش نمی‌گرفت.
     *
     * ⚠️ از آرایهٔ فهرستی فقط عنصرِ اول نمونه‌برداری می‌شود؛ همهٔ عناصر یک
     * شکل دارند و ثبتِ همه فقط فایل را بزرگ می‌کند.
     */
    protected function shapeOf(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($value === []) {
                return 'array:empty';
            }

            if (array_is_list($value)) {
                return [$this->shapeOf($value[0])];
            }

            $shape = [];

            foreach ($value as $key => $item) {
                $shape[$key] = $this->shapeOf($item);
            }

            ksort($shape);

            return $shape;
        }

        return get_debug_type($value);
    }

    /**
     * 🔴 هیچ فیلدی از پاسخ‌های حیاتی بی‌صدا حذف یا نوع‌عوض نمی‌شود.
     */
    public function test_no_critical_response_loses_a_field(): void
    {
        $path = base_path(self::SNAPSHOT);

        $this->assertFileExists(
            $path,
            'اسنپ‌شاتِ شکلِ پاسخ‌ها وجود ندارد. با `SHAPE_SNAPSHOT=1` تست را اجرا کنید، '
            .'خروجی را **بازبینی** کنید، و بعد کامیتش کنید.',
        );

        /** @var array<string, mixed> $expected */
        $expected = json_decode((string) file_get_contents($path), true) ?: [];

        $this->assertNotEmpty($expected, 'اسنپ‌شات خالی است؛ این محافظ پوچ می‌شود.');

        $broken = [];

        foreach ($this->criticalEndpoints() as $endpoint) {
            $response = $this->actingAs($this->admin)->getJson($endpoint);

            $this->assertSame(200, $response->status(), "مسیرِ «{$endpoint}» ۲۰۰ نداد.");

            $actual = $this->shapeOf($response->json());

            foreach ($this->missingFrom($expected[$endpoint] ?? [], $actual) as $problem) {
                $broken[] = "{$endpoint} → {$problem}";
            }
        }

        $this->assertSame(
            [],
            $broken,
            "شکلِ پاسخ عوض شده و کلاینتِ قدیمی می‌شکند:\n  ".implode("\n  ", $broken)
            ."\n⚠️ افزودنِ فیلد مجاز است؛ حذف و تغییرِ نوع نه.",
        );
    }

    /**
     * فیلدهایی که در اسنپ‌شات بودند و حالا نیستند یا نوعشان عوض شده.
     *
     * @return list<string>
     */
    private function missingFrom(mixed $expected, mixed $actual, string $trail = ''): array
    {
        if (is_array($expected) && array_is_list($expected)) {
            if (! is_array($actual) || ! array_is_list($actual)) {
                return ["{$trail}: فهرست بود، حالا نیست"];
            }

            // ⚠️ فهرستِ خالی در اجرای امروز چیزی را رد نمی‌کند
            return $actual === [] || $expected === []
                ? []
                : $this->missingFrom($expected[0], $actual[0], $trail.'[]');
        }

        if (is_array($expected)) {
            if (! is_array($actual)) {
                return ["{$trail}: شیء بود، حالا «".get_debug_type($actual).'»'];
            }

            $problems = [];

            foreach ($expected as $key => $sub) {
                $where = $trail === '' ? (string) $key : "{$trail}.{$key}";

                if (! array_key_exists($key, $actual)) {
                    $problems[] = "{$where}: حذف شده";

                    continue;
                }

                $problems = [...$problems, ...$this->missingFrom($sub, $actual[$key], $where)];
            }

            return $problems;
        }

        /*
         * ⚠️ `null` در هر دو جهت پذیرفته است.
         *
         * فیلدِ nullable در دادهٔ آزمایشی گاهی پر است و گاهی خالی؛ سخت‌گیری
         * رویش تست را بی‌ثبات می‌کند بی‌آنکه چیزی اثبات کند.
         */
        if ($expected === 'null' || $actual === 'null') {
            return [];
        }

        if ($expected === $actual) {
            return [];
        }

        /*
         * ⚠️ توصیف و نه درج‌سازیِ مستقیم.
         *
         * اگر یک طرف آرایه باشد و طرفِ دیگر رشته، درجِ مستقیم خطای
         * «Array to string conversion» می‌دهد — یعنی محافظ به‌جای گزارشِ
         * تفاوت، خودش می‌ترکد.
         */
        return ["{$trail}: «".$this->describe($expected).'» بود، حالا «'.$this->describe($actual).'»'];
    }

    /** توصیفِ کوتاهِ یک شکل، برای پیامِ خطا. */
    private function describe(mixed $shape): string
    {
        return is_array($shape) ? (array_is_list($shape) ? 'فهرست' : 'شیء') : (string) $shape;
    }

    /**
     * ⚠️ محافظِ ضدِ پوچی — خودِ مقایسه‌گر باید حذف را ببیند.
     *
     * 🔴 بدونِ این، مقایسه‌گری که همیشه آرایهٔ خالی برگرداند هم سبز می‌ماند
     * و کلِ این فایل تزئین می‌شد.
     */
    public function test_the_comparison_actually_detects_a_break(): void
    {
        $before = ['id' => 'int', 'name' => 'string', 'unit' => ['area' => 'int']];

        $this->assertSame([], $this->missingFrom($before, $before), 'شکلِ یکسان نباید مشکل بدهد.');

        $this->assertNotSame([], $this->missingFrom($before, ['id' => 'int', 'name' => 'string']));
        $this->assertNotSame([], $this->missingFrom($before, ['id' => 'string', 'name' => 'string', 'unit' => ['area' => 'int']]));
        $this->assertNotSame([], $this->missingFrom($before, ['id' => 'int', 'name' => 'string', 'unit' => ['floor' => 'int']]));

        // ⚠️ و افزودن نباید مشکل باشد
        $this->assertSame(
            [],
            $this->missingFrom($before, [...$before, 'extra' => 'string']),
            'افزودنِ فیلد نباید شکست باشد؛ کلاینتِ قدیمی نادیده‌اش می‌گیرد.',
        );
    }
}
