<?php

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Jobs\BuildBillsBundleJob;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Expense;
use App\Models\GeneratedDocument;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Units\TenureService;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * خروجی PDF و تولیدِ سنگین در صف (R28).
 *
 * ─── چرا «۲۰۰ گرفت» کافی نیست ──────────────────────────────────────────────
 * یک PDFِ خراب هم ۲۰۰ برمی‌گرداند. mPDF با قالبِ اشتباه یا داده‌ی `null`
 * می‌تواند فایلی بسازد که هیچ خواننده‌ای بازش نکند. پس هر آزمون **امضای
 * فایل** (`%PDF-`) و حجمِ معنادار را هم می‌سنجد.
 */
class DocumentExportTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->complex = Complex::factory()->create();
        $this->manager = $this->makeUser(UserRole::ComplexAdmin);
        $this->unit = Unit::factory()->create([
            'complex_id' => $this->complex->id,
            'unit_number' => '1',
        ]);
        $this->period = Jalali::currentPeriod();
    }

    private function makeUser(UserRole $role = UserRole::Owner): User
    {
        return User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function bill(float $total = 500000, float $paid = 0): Bill
    {
        return Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => $this->period,
            'base_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'due_date' => now()->addDays(10),
        ]);
    }

    private function payment(?Bill $bill = null): Payment
    {
        return Payment::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'bill_id' => $bill?->id,
            'user_id' => $this->manager->id,
            'amount' => 500000,
            'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Success,
            'period' => $this->period,
            'paid_at' => now(),
            'tracking_code' => 'TRK-99001',
        ]);
    }

    /** یک PDFِ سالم با `%PDF-` شروع می‌شود؛ هر چیز دیگری فایلِ خراب است. */
    private function assertIsPdf(string $body): void
    {
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertGreaterThan(1000, strlen($body), 'فایل PDF بیش از حد کوچک است.');
    }

    // ── رسید پرداخت ────────────────────────────────────────────────────────

    public function test_a_payment_receipt_renders_a_real_pdf(): void
    {
        $payment = $this->payment($this->bill());

        $response = $this->actingAs($this->manager)->get("/payments/{$payment->id}/receipt.pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertIsPdf($response->getContent());
    }

    public function test_a_receipt_without_a_bill_still_renders(): void
    {
        // پرداختِ آزاد (شارژِ کیف پول) قبض ندارد؛ قالب نباید بشکند
        $payment = $this->payment(null);

        $response = $this->actingAs($this->manager)->get("/payments/{$payment->id}/receipt.pdf");

        $response->assertOk();
        $this->assertIsPdf($response->getContent());
    }

    public function test_a_stranger_cannot_download_someone_elses_receipt(): void
    {
        $payment = $this->payment($this->bill());
        $stranger = $this->makeUser();

        $this->actingAs($stranger)->get("/payments/{$payment->id}/receipt.pdf")->assertForbidden();
    }

    public function test_the_payer_can_download_their_own_receipt(): void
    {
        $resident = $this->makeUser();
        $payment = $this->payment($this->bill());
        $payment->update(['user_id' => $resident->id]);

        $this->actingAs($resident)->get("/payments/{$payment->id}/receipt.pdf")->assertOk();
    }

    public function test_a_manager_of_another_complex_cannot_download_the_receipt(): void
    {
        $payment = $this->payment($this->bill());

        $outsider = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        /*
         * ۴۰۴ و نه ۴۰۳ — و این بهتر است: `BelongsToComplex` مدل را هنگامِ
         * bindingِ مسیر به مجتمعِ جاری محدود می‌کند، پس پرداخت اصلاً پیدا
         * نمی‌شود و وجودِ شناسه در مجتمعِ دیگر هم لو نمی‌رود.
         */
        $this->actingAs($outsider)->get("/payments/{$payment->id}/receipt.pdf")->assertNotFound();
    }

    // ── گزارش مالی ─────────────────────────────────────────────────────────

    public function test_the_financial_report_renders_with_data(): void
    {
        $this->bill();
        Expense::create([
            'complex_id' => $this->complex->id,
            'title' => 'تعمیر آسانسور',
            'amount' => 3000000,
            'category' => ExpenseCategory::cases()[0]->value,
            'period' => $this->period,
            'spend_date' => now(),
        ]);

        $response = $this->actingAs($this->manager)->get('/reports/financial.pdf');

        $response->assertOk();
        $this->assertIsPdf($response->getContent());
    }

    public function test_the_financial_report_renders_for_an_empty_period(): void
    {
        // دوره‌ای بدونِ هیچ هزینه و قبض — رایج‌ترین حالتِ ماهِ تازه
        $response = $this->actingAs($this->manager)->get('/reports/financial.pdf?period=1400-01');

        $response->assertOk();
        $this->assertIsPdf($response->getContent());
    }

    public function test_a_resident_cannot_download_the_financial_report(): void
    {
        $this->actingAs($this->makeUser())->get('/reports/financial.pdf')->assertForbidden();
    }

    // ── پرونده‌ی واحد ──────────────────────────────────────────────────────

    public function test_the_unit_dossier_includes_past_periods(): void
    {
        $tenures = app(TenureService::class);
        $past = $this->makeUser(UserRole::Tenant);

        $tenures->open($this->unit, $past, ResidentRelation::Tenant);
        $tenures->close($this->unit->tenures()->current()->firstOrFail());
        $tenures->open($this->unit, $this->makeUser(), ResidentRelation::Owner);

        $this->bill();

        $response = $this->actingAs($this->manager)->get("/units/{$this->unit->id}/dossier.pdf");

        $response->assertOk();
        $this->assertIsPdf($response->getContent());

        // هر دو دوره باید در سند باشند — نه فقط ساکنِ امروز
        $this->assertSame(2, $this->unit->tenures()->count());
    }

    public function test_the_dossier_renders_for_a_unit_with_no_history(): void
    {
        $response = $this->actingAs($this->manager)->get("/units/{$this->unit->id}/dossier.pdf");

        $response->assertOk();
        $this->assertIsPdf($response->getContent());
    }

    public function test_a_resident_cannot_download_a_unit_dossier(): void
    {
        $this->actingAs($this->makeUser())
            ->get("/units/{$this->unit->id}/dossier.pdf")
            ->assertForbidden();
    }

    // ── تولیدِ سنگین در صف ─────────────────────────────────────────────────

    public function test_requesting_the_bundle_queues_a_job_and_answers_immediately(): void
    {
        Bus::fake();
        $this->bill();

        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/documents/bills-bundle', ['period' => $this->period]);

        // ۲۰۲ و نه ۲۰۰: کار پذیرفته شده ولی هنوز تمام نشده
        $response->assertStatus(202)->assertJsonPath('document.status', GeneratedDocument::PENDING);

        Bus::assertDispatched(BuildBillsBundleJob::class);

        // ردیف پیش از صف‌شدن ساخته می‌شود تا کاربر بلافاصله ببیندش
        $this->assertSame(1, GeneratedDocument::count());
        $this->assertNull($response->json('document.url'));
    }

    public function test_the_job_builds_a_pdf_covering_every_unit(): void
    {
        $this->bill();

        $second = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '2']);
        Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $second->id,
            'period' => $this->period,
            'base_amount' => 700000,
            'total_amount' => 700000,
            'due_date' => now()->addDays(10),
        ]);

        $document = GeneratedDocument::create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->manager->id,
            'type' => 'bills_bundle',
            'title' => 'قبض‌ها',
            'params' => ['period' => $this->period],
            'status' => GeneratedDocument::PENDING,
        ]);

        (new BuildBillsBundleJob($document->id))->handle();

        $fresh = $document->fresh();
        $this->assertSame(GeneratedDocument::READY, $fresh->status);
        Storage::disk('local')->assertExists($fresh->path);
        $this->assertIsPdf(Storage::disk('local')->get($fresh->path));
    }

    public function test_a_pending_document_cannot_be_downloaded(): void
    {
        $document = GeneratedDocument::create([
            'complex_id' => $this->complex->id,
            'type' => 'bills_bundle',
            'title' => 'قبض‌ها',
            'params' => ['period' => $this->period],
            'status' => GeneratedDocument::PENDING,
        ]);

        // ۴۰۴ چون هنوز فایلی وجود ندارد که سرو شود
        $this->actingAs($this->manager)
            ->get("/reports/bills-bundle/{$document->id}.pdf")
            ->assertNotFound();
    }

    public function test_a_failed_job_marks_the_document_so_the_user_stops_waiting(): void
    {
        $document = GeneratedDocument::create([
            'complex_id' => $this->complex->id,
            'type' => 'bills_bundle',
            'title' => 'قبض‌ها',
            'params' => ['period' => $this->period],
            'status' => GeneratedDocument::PENDING,
        ]);

        (new BuildBillsBundleJob($document->id))->failed(new \RuntimeException('دیسک پر است'));

        $fresh = $document->fresh();
        $this->assertSame(GeneratedDocument::FAILED, $fresh->status);
        $this->assertStringContainsString('دیسک پر است', $fresh->error);
    }

    public function test_a_manager_of_another_complex_sees_no_documents(): void
    {
        GeneratedDocument::create([
            'complex_id' => $this->complex->id,
            'type' => 'bills_bundle',
            'title' => 'قبض‌ها',
            'status' => GeneratedDocument::READY,
            'path' => 'documents/x.pdf',
        ]);

        $outsider = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        $this->assertSame(
            [],
            $this->actingAs($outsider)->getJson('/api/v1/documents')->json('documents'),
        );
    }

    public function test_pruning_removes_old_documents_and_their_files(): void
    {
        Storage::disk('local')->put('documents/old.pdf', '%PDF-1.7 fake');

        $old = GeneratedDocument::create([
            'complex_id' => $this->complex->id,
            'type' => 'bills_bundle',
            'title' => 'قدیمی',
            'status' => GeneratedDocument::READY,
            'path' => 'documents/old.pdf',
        ]);
        // ⚠️ `created_at` در `$fillable` نیست؛ `update()` بی‌صدا نادیده‌اش
        // می‌گیرد و تست سبزِ توخالی می‌شد. `forceFill` از آن رد می‌شود.
        $old->forceFill(['created_at' => now()->subMonth()])->save();

        $fresh = GeneratedDocument::create([
            'complex_id' => $this->complex->id,
            'type' => 'bills_bundle',
            'title' => 'تازه',
            'status' => GeneratedDocument::PENDING,
        ]);

        $this->artisan('documents:prune --days=14')->assertSuccessful();

        $this->assertNull(GeneratedDocument::withoutGlobalScopes()->find($old->id));
        Storage::disk('local')->assertMissing('documents/old.pdf');

        // سندِ تازه دست‌نخورده می‌ماند
        $this->assertNotNull(GeneratedDocument::withoutGlobalScopes()->find($fresh->id));
    }

    public function test_pruning_survives_a_document_whose_file_is_already_gone(): void
    {
        $document = GeneratedDocument::create([
            'complex_id' => $this->complex->id,
            'type' => 'bills_bundle',
            'title' => 'بی‌فایل',
            'status' => GeneratedDocument::READY,
            'path' => 'documents/missing.pdf',
        ]);
        $document->forceFill(['created_at' => now()->subMonth()])->save();

        // فایل پیش‌تر دستی پاک شده؛ نبودنش نباید دستور را بشکند
        $this->artisan('documents:prune --days=14')->assertSuccessful();

        $this->assertNull(GeneratedDocument::withoutGlobalScopes()->find($document->id));
    }

    public function test_a_resident_cannot_request_a_bundle(): void
    {
        $this->actingAs($this->makeUser())
            ->postJson('/api/v1/documents/bills-bundle')
            ->assertForbidden();
    }
}
