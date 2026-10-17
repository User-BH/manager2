<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ResidentRelation;
use App\Enums\ServiceRequestCategory;
use App\Enums\ServiceRequestPriority;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Backup;
use App\Models\Bill;
use App\Models\ChargeRule;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Message;
use App\Models\MessagePoll;
use App\Models\MessageReport;
use App\Models\Payment;
use App\Models\PollOption;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Models\TrustedDevice;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Account\MembershipLedger;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * جاروی کاملِ BOLA/IDOR روی **همه‌ی** مسیرهای API (مرحلهٔ ۹ · فاز ۷).
 *
 * نمونه نیست: فهرستِ مسیرها از خودِ روتر خوانده می‌شود، پس مسیرِ تازه خودکار
 * زیرِ آزمون می‌رود. سه مهاجم — حسابِ اولیه (داشبوردِ اولیه)، ساکنِ مجتمعِ الف
 * (داشبوردِ واحد) و مدیرِ مجتمعِ الف (داشبوردِ مدیر) — هر مسیر را با شناسه‌های
 * **دیگران** صدا می‌زنند:
 *
 *   • مجموعه‌ی «ب»: منابعِ مجتمعِ دیگر (بینِ مجتمعی)؛
 *   • مجموعه‌ی «الف۲»: منابعِ خصوصیِ ساکنِ دیگرِ همان مجتمع و حسابِ او (بینِ حسابی).
 *
 * برای هر درخواست سه ادعا: (۱) هیچ ردیفی از قربانی عوض نمی‌شود — عکسِ هش‌شده‌ی
 * همه‌ی جدول‌ها روی ستون‌های مالکیت؛ (۲) هیچ نشانِ قربانی در پاسخ نمی‌آید — برای
 * فهرست‌ها هم؛ (۳) خطای ۵۰۰ نیست. پاسخِ ۲xx روی منبعِ قربانی فقط با دلیلِ صریح
 * در `ALLOWED_2XX` مجاز است. شناسه‌ی `community_id`/`unit_id`/`account_id` در
 * بدنه هم فرستاده می‌شود تا روشن شود هیچ‌کدام به‌تنهایی اختیار نمی‌سازد.
 *
 * پنلِ ادمینِ کل: هر مسیرِ `system/*` برای این سه مهاجم باید رد شود (همان جارو).
 *
 * ─── مهاجمِ چندمجتمعی (مرحلهٔ ۱۲ · فاز ۴؛ بندهای ۱۷، ۱۹ و ۲۰ ِ سندِ هسته) ──────────
 * از ۱۲/۱ زمینه مالِ مرورگر است و یک حساب می‌تواند در چند مجتمع با نقش‌های گوناگون باشد. دو مهاجمِ تازه
 * **عضوِ واقعیِ هر دو مجتمع** اند و فقط زمینه‌شان فرق دارد:
 *
 *   • `dualInA`: مدیرِ الف و مالکِ واحدی در ب، در زمینه‌ی الف ← کلِ مجموعه‌ی «ب» (حتی گروهِ عمومی و
 *     اطلاعیه‌ی ب که در زمینه‌ی ب حقِ اوست) باید از زمینه‌ی الف نادیدنی باشد؛
 *   • `dualInB`: مالکِ ب و مدیرِ الف، در زمینه‌ی ب ← «مدیر بودن در الف» در زمینه‌ی ب هیچ دسترسی‌ای به
 *     دادهٔ الف (مجموعه‌ی «الف۲») نمی‌دهد.
 */
class BolaSweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * مسیرهایی که با شناسه‌ی قربانی ۲xx می‌دهند **بی** اثر و بی نشت — با دلیل.
     *
     * @var array<string, string>
     */
    private const ALLOWED_2XX = [];

    private Complex $a;

    private Complex $b;

    /** @var array<string, User> */
    private array $actors = [];

    /** @var array<string, string> زمینه‌ای که هر مهاجمِ چندمجتمعی باید در آن بماند (`X-Sakena-Context`) */
    private array $contexts = [];

    /** واحدِ خودِ مهاجم، برای بدنه‌ی معتبرِ دورِ دوم */
    private int $attackerUnit;

    /** @var array<string, array<string, int|string>> شناسه‌ی هر پارامترِ مسیر، برای هر مجموعه */
    private array $targets = [];

    /** @var array<string, array<string, list<int|string>>> ستونِ مالکیت ← شناسه‌های قربانی */
    private array $ownership = [];

    /** @var array<string, list<string>> نشان‌های یکتا در داده‌ی قربانی */
    private array $markers = [];

    /** @var array<string, list<string>> */
    private array $columns = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->a = $this->complex('مجتمعِ مهاجم');
        $this->b = $this->complex('مجتمعِ قربانی');

        $managerA = $this->user(UserRole::ComplexAdmin, '09310000001', $this->a->id, 'مدیر الف');
        $residentA = $this->user(UserRole::Owner, '09310000002', $this->a->id, 'ساکن الف');
        $residentA2 = $this->user(UserRole::Owner, '09310000003', $this->a->id, 'همسایه الف');
        $registrant = $this->user(UserRole::Owner, '09310000004', null, 'حساب اولیه');
        $managerB = $this->user(UserRole::ComplexAdmin, '09310000005', $this->b->id, 'مدیر ب');
        $residentB = $this->user(UserRole::Owner, '09310000006', $this->b->id, 'ساکن ب');

        $this->actors = ['registrant' => $registrant, 'residentA' => $residentA, 'managerA' => $managerA];

        $unitA = $this->unit($this->a, '101', $residentA);
        $this->attackerUnit = $unitA->id;
        $unitA2 = $this->unit($this->a, '102', $residentA2);
        $unitB = $this->unit($this->b, '201', $residentB);

        /*
         * ساکنِ **رفته‌ی** همان واحدِ ب: دوره‌اش پیش از ساکنِ فعلی بسته شده و حالا
         * حسابِ اولیه است. «عضویتِ فعال» ندارد؛ نباید به دادهٔ ساکنِ فعلی برسد.
         */
        $formerB = $this->user(UserRole::Owner, '09310000007', $this->b->id, 'ساکنِ رفته‌ی ب');
        $old = app(TenureService::class)->open($unitB, $formerB, ResidentRelation::Tenant, 100, now()->subYears(3));
        app(TenureService::class)->close($old, now()->subYears(2));
        app(MembershipLedger::class)->end($formerB, ComplexMembership::END_REMOVED);
        $this->actors['formerB'] = $formerB;

        // ── مهاجمانِ چندمجتمعی: عضوِ فعالِ هر دو مجتمع، فقط با زمینه‌ی متفاوت ──
        $dualInA = $this->user(UserRole::ComplexAdmin, '09310000008', $this->a->id, 'مدیرِ الف و مالکِ ب');
        app(MembershipLedger::class)->start($dualInA, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT, activate: false);
        $this->unit($this->b, '202', $dualInA);
        $dualInB = $this->user(UserRole::Owner, '09310000009', $this->b->id, 'مالکِ ب و مدیرِ الف');
        $this->unit($this->b, '203', $dualInB);
        app(MembershipLedger::class)->start($dualInB, $this->a, UserRole::ComplexAdmin, ComplexMembership::SOURCE_DIRECT, activate: false);
        $this->actors['dualInA'] = $dualInA;
        $this->actors['dualInB'] = $dualInB;
        $this->contexts = ['dualInA' => $this->a->id.':complex_admin', 'dualInB' => $this->b->id.':owner'];

        $b = $this->recordsOf($this->b, $unitB, $residentB, $managerB, 'ZQB');
        $a2 = $this->recordsOf($this->a, $unitA2, $residentA2, $managerA, 'ZQA2');

        // نظرسنجیِ گروهِ عمومیِ الف مالِ همه‌ی اهالیِ الف است؛ در «الف۲» فقط دادهٔ خصوصی
        unset($a2['targets']['poll'], $a2['targets']['option'], $a2['targets']['announcement']);
        $a2['children']['message_id'] = array_slice($a2['children']['message_id'], 0, 1);
        unset($a2['children']['message_poll_id']);

        $this->targets = ['b' => $b['targets'], 'a2' => $a2['targets']];
        $this->markers = ['b' => $b['markers'], 'a2' => $a2['markers']];
        $this->ownership = [
            'b' => [
                'complex_id' => [$this->b->id],
                'user_id' => [$residentB->id, $managerB->id],
                'notifiable_id' => [$residentB->id, $managerB->id],
                'unit_id' => [$unitB->id],
            ] + $b['children'],
            // همسایه‌ی همان مجتمع: فقط داده‌ی خصوصیِ او، نه کلِ مجتمع (مدیرِ الف مدیرِ اوست)
            'a2' => [
                'user_id' => [$residentA2->id],
                'notifiable_id' => [$residentA2->id],
            ] + $a2['children'],
        ];

        foreach (Schema::getTables() as $table) {
            $this->columns[$table['name']] = Schema::getColumnListing($table['name']);
        }

        // کنترلِ مثبت: صاحبان به همین شناسه‌ها **می‌رسند** — جارو پوچ نیست
        $this->assertOwnersReach($residentB, $managerB, $b['targets']);

        /*
         * کنترلِ مثبتِ مهاجمانِ چندمجتمعی: هر کدام واقعاً در زمینه‌ای است که فرض شده، با نقشِ همان عضویت، و در
         * همان زمینه به دادهٔ خودش می‌رسد. بی این، «رد» شاید فقط از زمینه‌ی غلط یا ورودِ شکسته بود.
         */
        $this->as($dualInA)->getJson('/api/v1/units')->assertOk()
            ->assertHeader('X-Sakena-Context', $this->a->id.':complex_admin');
        $this->as($dualInB)->getJson('/api/v1/my-bills')->assertOk()
            ->assertHeader('X-Sakena-Context', $this->b->id.':owner');
        $this->as($dualInB)->getJson('/api/v1/units')->assertForbidden();
    }

    public function test_no_attacker_reaches_another_tenants_or_accounts_resources(): void
    {
        $violations = [];
        $succeeded = [];
        $probed = 0;
        $report = [];
        $retried = [];

        foreach ($this->apiRoutes() as $route) {
            $params = $route->parameterNames();

            foreach ($this->actors as $actorName => $actor) {
                foreach (['b', 'a2'] as $set) {
                    if (! $this->applies($route, $actorName, $set)) {
                        continue;
                    }

                    $uri = $this->fill($route, $set);
                    if ($uri === null) {
                        continue;
                    }

                    foreach ($this->methodsOf($route) as $method) {
                        $before = $this->snapshot($set);
                        $response = $this->hit($actor, $method, $uri, $set);
                        $probed++;

                        /*
                         * ⚠️ ۴۲۲ روی منبعِ قربانی می‌تواند بررسیِ مجوز را **پنهان** کند: بدنه با
                         * شناسه‌ی قربانی پیش از مجوز رد شده. پس دوباره با بدنه‌ای که از سمتِ
                         * مهاجم معتبر است (واحدِ خودش) — حالا باید به لایه‌ی مجوز برسد.
                         */
                        if ($params !== [] && $response->status() === 422) {
                            $response = $this->hit($actor, $method, $uri, $set, attackerSide: true);
                            $probed++;
                            $retried[] = $method.' '.$route->uri().' ['.$actorName.'→'.$set.'] → '.$response->status();
                        }

                        $label = "{$method} {$uri} [{$actorName}→{$set}]";
                        $status = $response->status();
                        $context = $response->headers->get('X-Sakena-Context');
                        if (isset($this->contexts[$actorName]) && $context !== null && $context !== $this->contexts[$actorName]) {
                            $violations[] = "{$label}: در زمینه‌ی «{$context}» اجرا شد، نه «{$this->contexts[$actorName]}»";
                        }
                        $report[] = ['route' => $method.' '.$route->uri(), 'actor' => $actorName, 'set' => $set, 'status' => $status, 'code' => $response->json('code')];
                        $body = (string) json_encode($response->json() ?? [], JSON_UNESCAPED_UNICODE).$response->getContent();

                        if ($status >= 500) {
                            $violations[] = "{$label}: خطای {$status}";
                        }
                        foreach ($this->markers[$set] as $marker) {
                            if (str_contains($body, $marker)) {
                                $violations[] = "{$label}: نشتِ «{$marker}» (وضعیت {$status})";
                            }
                        }
                        if ($this->snapshot($set) !== $before) {
                            $violations[] = "{$label}: دادهٔ قربانی عوض شد (وضعیت {$status})";
                        }
                        if ($params !== [] && $status >= 200 && $status < 300) {
                            $key = $method.' '.$route->uri();
                            if (! isset(self::ALLOWED_2XX[$key])) {
                                $succeeded[] = "{$label}: {$status}";
                            }
                        }
                    }
                }
            }
        }

        // گزارشِ کامل برای سیاهه‌ی مستند (`docs/ENDPOINT_AUTHZ_INVENTORY.md`)
        if ($path = getenv('BOLA_REPORT')) {
            file_put_contents($path, (string) json_encode(['probes' => $report, 'retried' => $retried], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $this->assertGreaterThan(500, $probed, 'جارو کمتر از انتظار درخواست زد؛ فهرستِ مسیرها خالی است؟');
        // ⚠️ ۴۰۱ یعنی خودِ ورودِ مهاجم شکسته و جارو پوچ است
        $this->assertSame([], array_values(array_filter($report, fn (array $r) => $r['status'] === 401)), 'مهاجم وارد نشده بود؛ جارو پوچ است.');
        $this->assertSame([], $violations, "نقضِ مرزِ دسترسی:\n".implode("\n", $violations));
        $this->assertSame([], $succeeded, "۲xx روی منبعِ دیگران (بی اثر، ولی باید رد شود یا با دلیل مجاز شود):\n".implode("\n", $succeeded));
    }

    /**
     * زمینه‌ی نامعتبر یعنی رد، نه «برگشت» به مجتمعی دیگر: ادمینِ کلی که مجتمعی
     * انتخاب نکرده، از هیچ فهرستِ مدیریتی دادهٔ هیچ مجتمعی نمی‌گیرد؛ و مدیری که
     * دیگر مدیر نیست (حسابِ اولیه) هم نه.
     */
    public function test_an_invalid_context_never_falls_back_to_another_complex(): void
    {
        $superAdmin = $this->user(UserRole::SuperAdmin, '09310000099', null, 'ادمین کل');
        $leaks = [];
        $statuses = [];

        foreach ($this->apiRoutes() as $route) {
            $roleMiddleware = array_filter($route->gatherMiddleware(), fn ($m) => str_starts_with($m, 'role:'));
            if ($route->parameterNames() !== [] || ! in_array('GET', $route->methods(), true)
                || ! in_array('role:super_admin,complex_admin', $roleMiddleware, true)) {
                continue;
            }

            $response = $this->as($superAdmin)->withConfirmedPassword()->getJson('/'.$route->uri());
            $statuses[$response->status()] = ($statuses[$response->status()] ?? 0) + 1;
            $body = (string) json_encode($response->json() ?? [], JSON_UNESCAPED_UNICODE).$response->getContent();
            foreach ([...$this->markers['b'], ...$this->markers['a2']] as $marker) {
                if (str_contains($body, $marker)) {
                    $leaks[] = "GET /{$route->uri()} → {$response->status()} با «{$marker}»";
                }
            }
            if ($response->status() >= 500) {
                $leaks[] = "GET /{$route->uri()} → {$response->status()}";
            }
        }

        $this->assertGreaterThan(10, array_sum($statuses), 'فهرستِ مسیرهای مدیریتی خالی است؛ آزمون پوچ است.');
        $this->assertSame([], $leaks, "ادمینِ کلِ بی‌زمینه به دادهٔ مجتمعی رسید:\n".implode("\n", $leaks));
    }

    /**
     * سیاهه‌ی مستند (`docs/ENDPOINT_AUTHZ_INVENTORY.md`) با روترِ واقعی می‌خواند:
     * مسیرِ تازه بی ردیف، یا ردیفِ مسیرِ حذف‌شده، قرمز است. تولیدِ دوباره: گزارشِ
     * `BOLA_REPORT` و اسکریپتِ سیاهه.
     */
    public function test_the_inventory_document_lists_every_route(): void
    {
        $document = (string) file_get_contents(base_path('docs/ENDPOINT_AUTHZ_INVENTORY.md'));
        preg_match_all('/^\| [^|]+ \| `([A-Z]+ [^`]+)`/mu', $document, $matches);
        $documented = $matches[1];
        sort($documented);

        $actual = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = $method.' '.substr($route->uri(), 7);
            }
        }
        sort($actual);

        $this->assertSame($actual, $documented, 'سیاهه‌ی مجوزِ مسیرها با روتر نمی‌خواند؛ دوباره تولیدش کنید.');
    }

    /** هر مسیرِ `/api` ِ قدیمی همان کنترلر و همان محافظ‌های `/api/v1` را دارد. */
    public function test_legacy_aliases_carry_the_same_guards(): void
    {
        $v1 = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                $v1[implode('|', $route->methods()).' '.substr($route->uri(), 7)] = [$route->getActionName(), $route->gatherMiddleware()];
            }
        }

        $mismatch = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $key = implode('|', $route->methods()).' '.substr($route->uri(), 4);
            if (isset($v1[$key]) && [$route->getActionName(), $route->gatherMiddleware()] !== $v1[$key]) {
                $mismatch[] = $key;
            }
        }

        $this->assertSame([], $mismatch, 'مسیرِ قدیمی با v1 محافظِ متفاوت دارد.');
    }

    /* ── جهان ───────────────────────────────────────────────────────────── */

    /**
     * @return array{targets: array<string, int|string>, markers: list<string>, children: array<string, list<int|string>>}
     */
    private function recordsOf(Complex $complex, Unit $unit, User $resident, User $manager, string $tag): array
    {
        $directory = app(ConversationDirectory::class);
        $thread = $directory->unitThread($complex, $unit);
        $channel = $directory->channel($complex);

        $bill = Bill::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'period' => '1405-04',
            'base_amount' => 500000, 'total_amount' => 500000, 'paid_amount' => 0,
            'status' => BillStatus::Unpaid, 'due_date' => now()->addDays(10),
        ]);
        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'user_id' => $resident->id, 'bill_id' => $bill->id,
            'amount' => 100000, 'method' => PaymentMethod::Receipt, 'status' => PaymentStatus::Pending,
            'receipt_path' => "receipts/{$tag}.jpg", 'period' => '1405-04',
        ]);
        Storage::disk('local')->put("receipts/{$tag}.jpg", "{$tag}-RECEIPT");
        $message = $this->message($complex, $thread->id, $resident, "{$tag}-PRIVATE-MESSAGE", "messages/{$tag}.jpg");
        Storage::disk('local')->put("messages/{$tag}.jpg", "{$tag}-ATTACHMENT");
        $channelMessage = $this->message($complex, $channel->id, $manager, 'اطلاعیه‌ی گروهِ عمومی', null);
        $poll = MessagePoll::create(['message_id' => $channelMessage->id, 'question' => 'نظرسنجی', 'voter_scope' => 'residents', 'weight_mode' => 'per_unit', 'allow_change' => true]);
        $option = PollOption::create(['message_poll_id' => $poll->id, 'label' => 'بله', 'sort_order' => 0]);
        $report = MessageReport::create(['complex_id' => $complex->id, 'message_id' => $message->id, 'user_id' => $resident->id, 'reason' => "{$tag}-REPORT-REASON"]);
        $request = ServiceRequest::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'user_id' => $resident->id,
            'category' => ServiceRequestCategory::Facilities, 'priority' => ServiceRequestPriority::Normal,
            'status' => ServiceRequestStatus::New, 'title' => "{$tag}-SERVICE-TITLE", 'description' => "{$tag}-SERVICE-BODY",
            'attachment_path' => "requests/{$tag}.jpg", 'attachment_name' => "{$tag}.jpg",
        ]);
        Storage::disk('local')->put("requests/{$tag}.jpg", "{$tag}-REQUEST-FILE");
        $invitation = ComplexInvitation::create([
            'complex_id' => $complex->id, 'phone' => '0939'.random_int(1000000, 9999999), 'unit_id' => $unit->id,
            'role' => 'tenant', 'direction' => 'invite', 'invited_by' => $manager->id, 'status' => 'pending',
            'token_hash' => hash('sha256', Str::random(43)), 'expires_at' => now()->addDays(7),
        ]);
        $applicant = $this->user(UserRole::Owner, '0938'.random_int(1000000, 9999999), null, "{$tag}-APPLICANT");
        $join = ComplexInvitation::create([
            'complex_id' => $complex->id, 'user_id' => $applicant->id, 'role' => 'owner',
            'direction' => 'request', 'status' => 'pending',
        ]);
        $device = TrustedDevice::create(['user_id' => $resident->id, 'token_hash' => hash('sha256', Str::random(40)), 'expires_at' => now()->addDays(5)]);
        $resident->notifications()->create([
            'id' => $notificationId = (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => "{$tag}-NOTICE"],
        ]);
        app(WalletService::class)->credit($resident, $unit, 77000, WalletTransaction::SOURCE_ADJUSTMENT, null, "{$tag}-WALLET-NOTE");

        $announcement = Announcement::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'title' => "{$tag}-ANNOUNCEMENT", 'body' => 'متن', 'audience' => 'all',
            'is_active' => true, 'published_at' => now(), 'created_by' => $manager->id,
        ]);
        $rule = ChargeRule::withoutGlobalScopes()->create(['complex_id' => $complex->id, 'name' => "{$tag}-RULE", 'type' => 'fixed', 'category' => 'owner', 'config' => ['amount' => 1000], 'is_active' => true]);
        $discount = Discount::withoutGlobalScopes()->create(['complex_id' => $complex->id, 'unit_id' => $unit->id, 'period' => '1405-04', 'amount' => 1000, 'reason' => "{$tag}-DISCOUNT", 'created_by' => $manager->id]);
        $expense = Expense::withoutGlobalScopes()->create(['complex_id' => $complex->id, 'title' => "{$tag}-EXPENSE", 'amount' => 1000, 'category' => 'owner', 'period' => '1405-04', 'spend_date' => now(), 'split_method' => 'fixed', 'is_distributed' => false, 'created_by' => $manager->id]);
        $income = Income::withoutGlobalScopes()->create(['complex_id' => $complex->id, 'title' => "{$tag}-INCOME", 'amount' => 1000, 'source' => 'other', 'period' => '1405-04', 'received_date' => now(), 'created_by' => $manager->id]);
        $subscription = Subscription::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'user_id' => $manager->id, 'plan' => 'pro', 'status' => 'active', 'method' => 'receipt',
            'amount' => 1000000, 'months' => 12, 'starts_at' => now(), 'ends_at' => now()->addYear(),
        ]);
        $backup = Backup::create(['complex_id' => $complex->id, 'type' => 'complex', 'status' => 'completed', 'disk' => 'local', 'path' => "backups/{$tag}.zip", 'size' => 10, 'created_by' => $manager->id]);
        Storage::disk('local')->put("backups/{$tag}.zip", "{$tag}-BACKUP");
        $tenure = UnitTenure::where('unit_id', $unit->id)->where('user_id', $resident->id)->firstOrFail();

        return [
            'targets' => [
                'announcement' => $announcement->id, 'backup' => $backup->id, 'charge_rule' => $rule->id,
                'discount' => $discount->id, 'expense' => $expense->id, 'income' => $income->id,
                'invitation' => $invitation->id, 'join' => $join->id, 'manager' => $manager->id,
                'conversation' => $thread->id, 'message' => $message->id, 'poll' => $poll->id,
                'report' => $report->id, 'id' => $notificationId, 'bill' => $bill->id, 'payment' => $payment->id,
                'device' => $device->id, 'session' => 'unknown-'.$tag, 'resident' => $resident->id,
                'serviceRequest' => $request->id, 'subscription' => $subscription->id, 'unit' => $unit->id,
                'tenure' => $tenure->id, 'member' => $resident->id, 'option' => $option->id,
            ],
            'markers' => [
                "{$tag}-PRIVATE-MESSAGE", "{$tag}-SERVICE-TITLE", "{$tag}-SERVICE-BODY", "{$tag}-REPORT-REASON",
                "{$tag}-NOTICE", "{$tag}-WALLET-NOTE", "{$tag}-RECEIPT", "{$tag}-ATTACHMENT", "{$tag}-REQUEST-FILE",
                "{$tag}-BACKUP", "{$tag}-APPLICANT",
                ...($tag === 'ZQB' ? ["{$tag}-ANNOUNCEMENT", "{$tag}-RULE", "{$tag}-DISCOUNT", "{$tag}-EXPENSE", "{$tag}-INCOME"] : []),
            ],
            'children' => [
                'bill_id' => [$bill->id], 'payment_id' => [$payment->id], 'message_id' => [$message->id, $channelMessage->id],
                'conversation_id' => [$thread->id], 'message_poll_id' => [$poll->id], 'service_request_id' => [$request->id],
                'invitation_id' => [$invitation->id, $join->id],
            ],
        ];
    }

    private function message(Complex $complex, int $conversationId, User $author, string $body, ?string $attachment): Message
    {
        return Message::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'conversation_id' => $conversationId, 'user_id' => $author->id,
            'body' => $body, 'client_id' => (string) Str::uuid(), 'author_name' => $author->name,
            'attachment_path' => $attachment, 'attachment_name' => $attachment ? 'file.jpg' : null,
            'attachment_kind' => $attachment ? 'image' : null,
        ]);
    }

    private function complex(string $name): Complex
    {
        return Complex::create([
            'type' => 'residential', 'name' => $name, 'slug' => 'bola-'.Str::random(8), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'fake', 'messenger_enabled' => true,
        ]);
    }

    private function user(UserRole $role, string $phone, ?int $complexId, string $name): User
    {
        return User::create([
            'complex_id' => $complexId, 'name' => $name, 'phone' => $phone, 'role' => $role,
            'password' => Hash::make('secret123'), 'is_active' => true, 'can_message' => true,
        ]);
    }

    private function unit(Complex $complex, string $number, User $resident): Unit
    {
        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_number' => $number, 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1, 'occupancy_status' => OccupancyStatus::OwnerOccupied,
        ]);
        app(TenureService::class)->open($unit, $resident, ResidentRelation::Owner, 100, now()->subYear());

        return $unit;
    }

    /** @param  array<string, int|string>  $targets */
    private function assertOwnersReach(User $resident, User $manager, array $targets): void
    {
        foreach ([
            [$resident, "/api/v1/my-bills/{$targets['bill']}"],
            [$resident, "/api/v1/service-requests/{$targets['serviceRequest']}"],
            [$resident, "/api/v1/messenger/conversations/{$targets['conversation']}/messages"],
            [$manager, "/api/v1/units/{$targets['unit']}"],
            [$manager, "/api/v1/wallet/members/{$targets['member']}"],
        ] as [$owner, $uri]) {
            $this->as($owner)->getJson($uri)->assertOk();
        }
    }

    /* ── جارو ───────────────────────────────────────────────────────────── */

    /** @return list<Route> */
    private function apiRoutes(): array
    {
        $routes = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            // مسیرهای مهمان در `EndpointInventoryTest` سیاهه دارند؛ این‌جا فقط مسیرهای واردشده
            if (! in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }
            $routes[] = $route;
        }

        return $routes;
    }

    /**
     * کدام مهاجم روی کدام مجموعه. مدیرِ الف مدیرِ همسایه‌ی خودش است؛ پس روی
     * «الف۲» فقط مسیرهای **حسابیِ** او (دستگاه، نشست، اعلانِ شخصی، دعوتِ او).
     */
    private function applies(Route $route, string $actor, string $set): bool
    {
        /*
         * مهاجمِ چندمجتمعی فقط روی مجتمعی که زمینه‌اش **نیست**: `dualInB` در ب ساکن است و گروه و اطلاعیه‌ی ب حقِ
         * اوست؛ `dualInA` مدیرِ الف است و دادهٔ الف حقِ او. روی مجتمعِ دیگر — با وجودِ عضویتِ واقعی — همه‌چیز.
         */
        if ($actor === 'dualInA' || $actor === 'dualInB') {
            /*
             * ⚠️ تعویضِ زمینه نه: او واقعاً عضوِ مجتمعِ دیگر است و رفتن به آن حقِ اوست (پیش‌فرضِ حسابش را عوض
             * می‌کند). دورِ اولِ این جارو همین را نشان داد — پس از تعویض، باقیِ درخواست‌ها در زمینه‌ی تازه رفتند و
             * «نقض» ساختگی بود. هر درخواستِ این دو مهاجم سربرگِ زمینه‌اش هم سنجیده می‌شود (`$contexts`).
             */
            return $set === ($actor === 'dualInA' ? 'b' : 'a2') && $route->uri() !== 'api/v1/account/context';
        }
        if ($set === 'b') {
            return true;
        }
        if ($actor === 'formerB') {
            return false;
        }

        $params = $route->parameterNames();
        if ($params === []) {
            // فهرست‌ها: ساکن و حسابِ اولیه نشانِ خصوصیِ همسایه را نبینند
            return $actor !== 'managerA' && in_array('GET', $route->methods(), true);
        }

        $personal = ['device', 'session', 'id', 'invitation'];
        if ($actor === 'managerA') {
            return array_diff($params, $personal) === [] && ! str_starts_with($route->uri(), 'api/v1/sent-invitations')
                && ! str_starts_with($route->uri(), 'api/v1/join-requests');
        }

        return true;
    }

    private function fill(Route $route, string $set): ?string
    {
        $uri = '/'.$route->uri();

        foreach ($route->parameterNames() as $name) {
            $key = $name === 'invitation' && str_starts_with($route->uri(), 'api/v1/join-requests') ? 'join' : $name;
            $key = $key === 'recovery' ? null : $key;

            $value = $key !== null ? ($this->targets[$set][$key] ?? null) : 999999;
            if ($value === null && str_contains($route->uri(), 'system/')) {
                $value = 999999;
            }
            if ($value === null) {
                // پارامترِ بی‌منبع (مثلِ `slug` ِ عمومی): شناسه‌ی ساختگی، باز هم نباید ۲xx شود
                $value = 999999;
            }

            $uri = preg_replace('/\{'.$name.'\??\}/', (string) $value, $uri);
        }

        return $uri;
    }

    /** @return list<string> */
    private function methodsOf(Route $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD']));
    }

    private function hit(User $actor, string $method, string $uri, string $set, bool $attackerSide = false): TestResponse
    {
        $t = $this->targets[$set];
        if ($attackerSide) {
            // همان بدنه، ولی شناسه‌ها از سمتِ خودِ مهاجم: معتبر برای اعتبارسنجی
            $t = ['unit' => $this->attackerUnit, 'member' => $actor->id, 'bill' => $t['bill'], 'message' => $t['message'], 'option' => $t['option'] ?? 999999];
        }
        // شناسه‌های قربانی در بدنه هم: هیچ‌کدام به‌تنهایی نباید اختیار بسازد
        $body = [
            'title' => 'آزمون', 'body' => 'آزمون', 'description' => 'آزمون', 'amount' => 1000,
            'reason' => 'دلیلِ آزمونِ دسترسی', 'note' => 'یادداشتِ آزمون', 'status' => 'resolved', 'priority' => 'high',
            'emoji' => '👍', 'code' => '123456', 'role' => 'tenant', 'name' => 'آزمون', 'comment' => 'نظر',
            'resolution' => 'dismissed', 'option_id' => $t['option'] ?? 999999, 'unit_id' => $t['unit'],
            'complex_id' => $set === 'b' && ! $attackerSide ? $this->b->id : $this->a->id,
            'community_id' => $set === 'b' && ! $attackerSide ? $this->b->id : $this->a->id,
            'account_id' => $t['member'], 'user_id' => $t['member'], 'bill_id' => $t['bill'], 'up_to' => $t['message'],
            'client_id' => (string) Str::uuid(), 'new_owner_id' => $t['member'], 'assigned_to' => $t['member'],
        ];

        return $this->as($actor)->withConfirmedPassword()->json($method, $uri, $body);
    }

    /** هشِ ردیف‌های قربانی در **هر** جدولی که ستونِ مالکیت دارد. */
    private function snapshot(string $set): string
    {
        $parts = [];
        foreach ($this->columns as $table => $columns) {
            if (in_array($table, ['audit_logs', 'login_events', 'sessions', 'cache', 'cache_locks', 'jobs', 'error_events'], true)) {
                continue;
            }
            foreach ($this->ownership[$set] as $column => $ids) {
                if (! in_array($column, $columns, true)) {
                    continue;
                }
                $rows = DB::table($table)->whereIn($column, $ids)->orderBy($columns[0])->get();
                $parts[] = $table.'.'.$column.':'.md5((string) json_encode($rows));
            }
        }

        return md5(implode('|', $parts));
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }
}
