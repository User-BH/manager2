<?php

namespace Tests\Feature;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * پذیرشِ **واقعاً هم‌زمانِ** دعوت — مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶ (آزمونِ «دو
 * درخواستِ هم‌زمان برای پذیرشِ یک دعوت» ِ تصمیمِ کارفرما).
 *
 * پردازه‌های جدا (`race:check accept`) در یک لحظه‌ی مشترک دعوت را می‌پذیرند.
 * همان دلیلِ `WalletConcurrencyTest`: SQLite ِ درحافظه‌ی PHPUnit تک‌اتصال است و
 * هم‌زمانی را نمی‌سازد؛ این‌جا پایگاه‌داده‌ی فایلی (محلی) یا MySQL ِ CI.
 *
 * ناوردایی: حساب در **حداکثر یک** مجتمع، با **حداکثر یک** دوره‌ی سکونت، و
 * فقط **یک** دعوتِ پذیرفته — همان مجتمعی که حساب در آن است. هیچ دعوتی زنده
 * نمی‌ماند که بعداً حساب را بی‌صدا جابه‌جا کند.
 */
class InvitationConcurrencyTest extends TestCase
{
    private const WORKERS = 4;

    private ?string $file = null;

    /** @var array<string, string> */
    private array $env = [];

    /** @var array{user: int, complexes: list<int>, invitations: list<int>}|null */
    private ?array $data = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = ['CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'];

        if (config('database.default') === 'sqlite') {
            $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sakena-invite-race-'.bin2hex(random_bytes(6)).'.sqlite';
            touch($this->file);
            $this->env += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->file];
        }

        $migrate = $this->artisanProcess(['migrate', '--force']);
        $this->assertTrue($migrate->successful(), 'مهاجرتِ پایگاه‌داده‌ی آزمون نشد: '.$migrate->errorOutput());

        $setup = $this->artisanProcess(['race:check', 'invite-setup']);
        $this->assertTrue($setup->successful(), $setup->errorOutput());
        $this->data = json_decode(trim($setup->output()), true);
    }

    protected function tearDown(): void
    {
        if ($this->data !== null) {
            $this->artisanProcess([
                'race:check', 'invite-cleanup', '--user='.$this->data['user'], '--complex='.implode(',', $this->data['complexes']),
            ]);
        }
        if ($this->file !== null && is_file($this->file)) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    /** چند پذیرشِ هم‌زمانِ **یک** دعوت: یک عضویت در همان مجتمع، نه بیشتر. */
    public function test_one_invitation_accepted_concurrently_makes_one_membership(): void
    {
        $invitation = $this->data['invitations'][0];
        [$outputs, $state] = $this->race(array_fill(0, self::WORKERS, $invitation));

        $this->assertInvariants($outputs, $state, 1);
    }

    /**
     * دو دعوتِ دو مجتمع، هم‌زمان و هرکدام دو بار (مرحلهٔ ۱۰ · فاز ۴): هر دعوت **یک** بار
     * پذیرفته می‌شود و حساب در هر دو مجتمع یک عضویت می‌گیرد — چند عضویت مجاز است، عضویتِ
     * تکراری در یک مجتمع نه. (پیش از ۱۰/۴ حساب فقط در یکی می‌نشست و دیگری می‌مُرد.)
     */
    public function test_two_invitations_accepted_concurrently_make_one_membership_each(): void
    {
        [$a, $b] = $this->data['invitations'];
        [$outputs, $state] = $this->race([$a, $b, $a, $b]);

        $this->assertInvariants($outputs, $state, 2);
    }

    /**
     * @param  list<string>  $outputs
     * @param  array{complex: int|null, tenures: int, openTenures: int, tenureComplexes: list<int>, accepted: list<int>, live: int, activeMemberships: list<int>}  $state
     */
    private function assertInvariants(array $outputs, array $state, int $expectedOnMysql): void
    {
        $context = "\nخروجیِ پردازه‌ها:\n  ".implode("\n  ", $outputs)."\nوضعیت: ".json_encode($state);
        $ok = count(array_filter($outputs, fn (string $o) => str_contains($o, 'OK')));

        /*
         * ⚠️ SQLite نویسنده‌ی هم‌زمان را با «database is locked» رد می‌کند؛ پس محلی
         * ممکن است کمتر بنشیند. «دقیقاً همان تعداد» فقط با قفلِ ردیفِ واقعی (MySQL ِ CI)
         * ادعا می‌شود. «بیش از آن» در هر دو شکست است.
         */
        $expected = config('database.default') === 'sqlite' ? range(0, $expectedOnMysql) : [$expectedOnMysql];
        $this->assertContains($ok, $expected, 'تعدادِ پذیرش‌های موفق نادرست است.'.$context);

        $accepted = $state['accepted'];
        sort($accepted);
        $this->assertSame(count(array_unique($accepted)), count($accepted), 'یک دعوت دو بار پذیرفته شد.'.$context);
        $this->assertCount($ok, $accepted, 'هر پذیرشِ موفق دقیقاً یک دعوتِ پذیرفته نیست.'.$context);

        $memberships = $state['activeMemberships'];
        sort($memberships);
        $this->assertSame($accepted, $memberships, 'عضویتِ فعال با دعوت‌های پذیرفته نمی‌خواند (تکراری یا جاافتاده).'.$context);

        $tenureComplexes = $state['tenureComplexes'];
        sort($tenureComplexes);
        $this->assertSame([$ok, $ok], [$state['tenures'], $state['openTenures']], 'دوره‌ی سکونتِ تکراری یا جاافتاده.'.$context);
        $this->assertSame($accepted, $tenureComplexes, 'دوره‌ی سکونت در مجتمعی است که پذیرفته نشده.'.$context);

        // دعوتی که پذیرفته نشد زنده می‌ماند — مجتمعِ دیگر است و حساب را جابه‌جا نمی‌کند
        $this->assertSame(2 - $ok, $state['live'], 'دعوتِ پذیرفته‌شده زنده ماند یا دعوتِ دیگر بی‌دلیل مُرد.'.$context);

        if ($ok === 0) {
            $this->assertNull($state['complex'], 'بی پذیرشِ موفق، حساب عضو شد.'.$context);
        } else {
            $this->assertContains($state['complex'], $accepted, 'زمینه‌ی فعال در مجتمعی است که پذیرفته نشده.'.$context);
        }
    }

    /**
     * @param  list<int>  $invitations
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function race(array $invitations): array
    {
        $at = (int) floor(microtime(true) * 1000) + 4000;
        $pool = Process::pool(function ($pool) use ($invitations, $at) {
            foreach ($invitations as $invitation) {
                $pool->path(base_path())->env($this->env)->timeout(180)->command([
                    PHP_BINARY, 'artisan', 'race:check', 'accept',
                    '--invitation='.$invitation, '--user='.$this->data['user'], '--at='.$at,
                ]);
            }
        })->start();

        $outputs = collect($pool->wait()->collect())
            ->map(fn ($result) => trim($result->output().' '.$result->errorOutput()))
            ->values()
            ->all();

        $verify = $this->artisanProcess(['race:check', 'invite-verify', '--user='.$this->data['user']]);
        $this->assertTrue($verify->successful(), $verify->errorOutput());

        return [$outputs, json_decode(trim($verify->output()), true)];
    }

    /** @param  list<string>  $arguments */
    private function artisanProcess(array $arguments): ProcessResult
    {
        return Process::path(base_path())->env($this->env)->timeout(180)
            ->run([PHP_BINARY, 'artisan', ...$arguments]);
    }
}
