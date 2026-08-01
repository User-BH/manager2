<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * امنیت آپلود و ذخیره‌سازی (R19).
 *
 * ⚠️ همه‌ی تست‌های اینجا `Storage::fake` دارند. بدونِ آن، تست‌ها واقعاً در
 * `storage/app/private` می‌نویسند و پوشه‌ی پروژه را با فایلِ زباله پر می‌کنند —
 * که در جریانِ همین مرحله یک بار اتفاق افتاد.
 */
class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Unit $unit;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->complex = Complex::factory()->create();
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $this->resident = User::factory()->create([
            'role' => UserRole::Owner,
            'complex_id' => $this->complex->id,
            'is_active' => true,
        ]);
        $this->unit->residents()->attach($this->resident->id, [
            'relation' => 'owner',
            'complex_id' => $this->complex->id,
        ]);
    }

    /* ── نوعِ فایل: محتوا، نه پسوند ──────────────────────────────────────── */

    /**
     * قاعده‌ی `mimes` محتوا را می‌بیند نه پسوند را.
     *
     * این را فرض نکردیم و سنجیدیم — چون اگر فقط پسوند را می‌دید، کلِ
     * اعتبارسنجیِ آپلود نمایشی بود. `guessExtension()` از نوعِ محتوایی که
     * `finfo` تشخیص می‌دهد می‌آید، نه از نامِ فایل.
     */
    #[DataProvider('disguisedFiles')]
    public function test_a_disguised_file_is_rejected(string $name, string $content): void
    {
        $file = $this->fileWithContent($name, $content);

        $validator = Validator::make(
            ['receipt' => $file],
            ['receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf']],
        );

        $this->assertTrue($validator->fails(), $name.' با محتوای جعلی باید رد شود');
    }

    public static function disguisedFiles(): array
    {
        return [
            'php as jpg' => ['evil.jpg', '<?php system($_GET["c"]); ?>'],
            'html as png' => ['evil.png', '<html><script>alert(1)</script></html>'],
            // SVG کلاسیک‌ترین راهِ XSS از مسیر آپلود است
            'svg as jpg' => ['evil.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'text as pdf' => ['evil.pdf', 'not really a pdf'],
        ];
    }

    public function test_a_real_image_is_accepted(): void
    {
        // سخت‌گیری نباید فایلِ درست را رد کند
        $validator = Validator::make(
            ['receipt' => UploadedFile::fake()->image('ok.jpg', 40, 40)],
            ['receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf']],
        );

        $this->assertFalse($validator->fails());
    }

    /* ── فایلِ یتیم ──────────────────────────────────────────────────────── */

    /**
     * رسیدی که رد می‌شود نباید فایلش را روی دیسک جا بگذارد.
     *
     * سنجیده شد نه فرض: پیش از R19، ارسالِ دومِ رسید برای یک قبض ۴۲۲ می‌گرفت
     * ولی فایلِ ۴ مگابایتی‌اش می‌ماند و هیچ ردیفی به آن اشاره نمی‌کرد. با سقفِ
     * ۲۰ آپلود در ساعت، هر ساکن ساعتی ۸۰ مگابایت زباله می‌ساخت.
     */
    public function test_a_rejected_receipt_leaves_no_file_behind(): void
    {
        $bill = $this->makeBill();

        $this->actingAs($this->resident)
            ->postJson("/api/v1/pay/{$bill->id}/receipt", [
                'amount' => 100000,
                'receipt' => UploadedFile::fake()->image('a.jpg', 30, 30),
            ])
            ->assertStatus(201);

        $this->actingAs($this->resident)
            ->postJson("/api/v1/pay/{$bill->id}/receipt", [
                'amount' => 100000,
                'receipt' => UploadedFile::fake()->image('b.jpg', 30, 30),
            ])
            ->assertStatus(422);

        // یک پرداخت، یک فایل — نه دو تا
        $this->assertSame(1, Payment::count());
        $this->assertCount(1, Storage::disk('local')->allFiles('receipts'));
    }

    public function test_an_accepted_receipt_keeps_its_file(): void
    {
        $bill = $this->makeBill();

        $this->actingAs($this->resident)
            ->postJson("/api/v1/pay/{$bill->id}/receipt", [
                'amount' => 100000,
                'receipt' => UploadedFile::fake()->image('a.jpg', 30, 30),
            ])
            ->assertStatus(201);

        // محافظت نباید مسیرِ موفق را خراب کند
        $path = Payment::first()->receipt_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_keep_if_removes_the_file_when_the_callback_throws(): void
    {
        $file = UploadedFile::fake()->image('x.jpg', 20, 20);

        try {
            Uploads::keepIf($file, 'probe', function (string $path): void {
                Storage::disk('local')->assertExists($path);
                throw new \RuntimeException('boom');
            });
            $this->fail('استثنا باید بالا بیاید');
        } catch (\RuntimeException) {
            // انتظار همین بود
        }

        // استثنا بالا می‌آید **و** فایل پاک می‌شود — نه یکی از این دو
        $this->assertSame([], Storage::disk('local')->allFiles('probe'));
    }

    /* ── نامِ فایل ───────────────────────────────────────────────────────── */

    public function test_the_stored_name_is_random_not_the_client_name(): void
    {
        $bill = $this->makeBill();

        $this->actingAs($this->resident)->postJson("/api/v1/pay/{$bill->id}/receipt", [
            'amount' => 100000,
            'receipt' => UploadedFile::fake()->image('my-secret-receipt.jpg', 30, 30),
        ])->assertStatus(201);

        /*
         * نامِ کلاینت نباید مسیرِ ذخیره را تعیین کند — وگرنه هم قابل حدس‌زدن
         * می‌شود و هم می‌تواند مسیر داشته باشد.
         */
        $this->assertStringNotContainsString('my-secret-receipt', Payment::first()->receipt_path);
    }

    public function test_a_hostile_original_name_is_cleaned_before_storage(): void
    {
        /*
         * امروز این نام فقط ذخیره می‌شود، ولی روزی که کسی آن را در هدرِ
         * `Content-Disposition` بگذارد، `\r\n` داخلش یعنی تزریقِ هدر.
         */
        $file = $this->fileWithContent("bad\r\nX-Injected: 1\".jpg", 'x');

        $clean = Uploads::safeOriginalName($file);

        $this->assertStringNotContainsString("\r", $clean);
        $this->assertStringNotContainsString("\n", $clean);
        $this->assertStringNotContainsString('"', $clean);
    }

    public function test_a_path_in_the_original_name_is_stripped(): void
    {
        $file = $this->fileWithContent('../../etc/passwd.jpg', 'x');

        $this->assertSame('passwd.jpg', Uploads::safeOriginalName($file));
    }

    /* ── سرو کردن ───────────────────────────────────────────────────────── */

    public function test_a_receipt_is_served_with_an_explicit_content_type(): void
    {
        $manager = User::factory()->create([
            'role' => UserRole::ComplexAdmin,
            'complex_id' => $this->complex->id,
            'is_active' => true,
        ]);

        $payment = Payment::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'user_id' => $this->resident->id,
            'amount' => 1000,
            'method' => 'receipt',
            'status' => PaymentStatus::Pending,
            'receipt_path' => 'receipts/1/x.jpg',
        ]);
        Storage::disk('local')->put('receipts/1/x.jpg', 'not really a jpeg');

        $response = $this->actingAs($manager)->get("/api/v1/payments/{$payment->id}/receipt");

        /*
         * نوع از **پسوندِ ذخیره‌شده** می‌آید (که خودمان ساخته‌ایم)، نه از حدسِ
         * محتوا در لحظه‌ی سرو. محتوای فایل اینجا عمداً jpeg نیست تا معلوم شود
         * تضمین به حدس وابسته نیست.
         */
        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_an_unexpected_extension_is_never_served_as_html(): void
    {
        Storage::disk('local')->put('receipts/1/weird.html', '<script>alert(1)</script>');

        // هر چیزی بیرون از فهرستِ مجاز باید دانلود شود، نه رندر
        $this->assertSame(
            'application/octet-stream',
            Uploads::serve('receipts/1/weird.html')->headers->get('Content-Type'),
        );
    }

    /* ── پاک‌سازیِ یتیم‌ها ───────────────────────────────────────────────── */

    public function test_the_prune_command_reports_without_deleting_by_default(): void
    {
        Storage::disk('local')->put('receipts/1/orphan.jpg', 'x');
        $this->ageFile('receipts/1/orphan.jpg');

        $this->artisan('uploads:prune-orphans')->assertSuccessful();

        // حذف برگشت‌پذیر نیست، پس نباید پیش‌فرض باشد
        Storage::disk('local')->assertExists('receipts/1/orphan.jpg');
    }

    public function test_the_prune_command_deletes_orphans_when_asked(): void
    {
        Storage::disk('local')->put('receipts/1/orphan.jpg', 'x');
        $this->ageFile('receipts/1/orphan.jpg');

        $this->artisan('uploads:prune-orphans --delete')->assertSuccessful();

        Storage::disk('local')->assertMissing('receipts/1/orphan.jpg');
    }

    public function test_the_prune_command_keeps_files_a_row_points_at(): void
    {
        Payment::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'user_id' => $this->resident->id,
            'amount' => 1000,
            'method' => 'receipt',
            'status' => PaymentStatus::Pending,
            'receipt_path' => 'receipts/1/keep.jpg',
        ]);
        Storage::disk('local')->put('receipts/1/keep.jpg', 'x');
        $this->ageFile('receipts/1/keep.jpg');

        $this->artisan('uploads:prune-orphans --delete')->assertSuccessful();

        Storage::disk('local')->assertExists('receipts/1/keep.jpg');
    }

    public function test_the_prune_command_spares_very_recent_files(): void
    {
        /*
         * فایلی که همین حالا نوشته شده ممکن است متعلق به درخواستی باشد که
         * هنوز ردیفش را نساخته. حذفش یعنی خراب‌کردنِ یک آپلودِ سالم.
         */
        Storage::disk('local')->put('receipts/1/fresh.jpg', 'x');

        $this->artisan('uploads:prune-orphans --delete')->assertSuccessful();

        Storage::disk('local')->assertExists('receipts/1/fresh.jpg');
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function makeBill(): Bill
    {
        return Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => '1405-01',
            'total_amount' => 500000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(10),
        ]);
    }

    /** فایلِ واقعی با محتوای دلخواه — تا تشخیصِ نوع روی محتوا انجام شود. */
    private function fileWithContent(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid().'-upload';
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /** فایل را «قدیمی» می‌کند تا از محافظِ یک‌ساعته‌ی دستور رد شود. */
    private function ageFile(string $path): void
    {
        touch(Storage::disk('local')->path($path), now()->subDay()->getTimestamp());
    }
}
