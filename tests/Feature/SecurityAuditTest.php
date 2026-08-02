<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Backup;
use App\Models\Complex;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Backup\BackupBuilder;
use App\Services\Backup\BackupCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ممیزی امنیتی (R20).
 *
 * این‌ها نگهبانِ دو موردی‌اند که در ممیزی پیدا شدند و هر دو با اکسپلویتِ
 * اجراشده اثبات شدند.
 */
class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    /* ── تصاحبِ حساب فقط با شماره ────────────────────────────────────────── */

    /**
     * کدِ یک‌بارمصرف نباید در پاسخِ API برگردد.
     *
     * زنجیره‌ی اثبات‌شده روی پیکربندیِ **پیش‌فرض** (`sms_driver=log`):
     * `/password/forgot` کد را در پاسخ می‌داد، `/forgot/verify` تاییدش
     * می‌کرد و `/password/reset` رمز را عوض می‌کرد — یعنی تصاحبِ کاملِ حساب
     * فقط با دانستنِ شماره‌ی موبایل و بدونِ هیچ رمزی.
     */
    public function test_the_otp_never_leaves_the_server_outside_development(): void
    {
        /*
         * محیط روی `production` گذاشته می‌شود تا همان شرطی سنجیده شود که در
         * محصول برقرار است. خودِ سرویس صدا زده می‌شود نه مسیرِ HTTP، چون
         * عوض‌کردنِ محیط، CSRF و رندرِ خطا را هم عوض می‌کند و آن‌ها به این
         * موضوع ربطی ندارند.
         */
        $this->app->detectEnvironment(fn () => 'production');
        $this->makeVictim();

        $result = app(OtpService::class)->request('09121234567');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['dev_code'], 'کدِ ورود نباید از سرور بیرون برود');
    }

    public function test_the_takeover_chain_needs_a_code_the_attacker_no_longer_gets(): void
    {
        $victim = $this->makeVictim();

        // گامِ اول همچنان کار می‌کند (نباید وجودِ شماره را لو بدهد یا بشکند)
        $this->postJson('/api/v1/password/forgot', ['phone' => '09121234567'])->assertOk();

        /*
         * ولی بدونِ کدِ درست، گامِ دوم شکست می‌خورد و رمز دست‌نخورده می‌ماند.
         * در محصول مهاجم آن کد را دیگر از پاسخ نمی‌گیرد (تستِ بالا).
         */
        $this->postJson('/api/v1/password/forgot/verify', ['code' => '000000'])
            ->assertStatus(422);

        $this->postJson('/api/v1/password/reset', [
            'password' => 'attacker-owns-you-1',
            'password_confirmation' => 'attacker-owns-you-1',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('victim-strong-password', $victim->fresh()->password));
    }

    public function test_development_still_gets_the_code_so_local_work_is_not_blocked(): void
    {
        // محافظت نباید جریانِ توسعه و تست را بشکند
        $this->makeVictim();

        $result = app(OtpService::class)->request('09121234567');

        $this->assertIsString($result['dev_code']);
        $this->assertSame(6, strlen($result['dev_code']));
    }

    /* ── رمزگذاری بکاپ ──────────────────────────────────────────────────── */

    /**
     * فایلِ بکاپِ سیستم نباید متنِ ساده باشد.
     *
     * ممیزی نشان داد این فایل **همه‌ی هشِ رمزهای سامانه**، **رمزِ درگاه بانکی**
     * و **کلیدِ API پیامک** را در خود دارد. جالب اینکه بکاپِ سطحِ مجتمع
     * `makeHidden('password')` داشت، ولی بکاپِ سیستم با `DB::table()` ساخته
     * می‌شد که `$hidden` را دور می‌زند.
     */
    public function test_a_system_backup_is_encrypted_at_rest(): void
    {
        Storage::fake('local');

        $complex = Complex::factory()->create([
            'gateway_config' => ['terminal_id' => '1', 'username' => 'u', 'password' => 'BANK-SECRET'],
        ]);
        User::factory()->create([
            'complex_id' => $complex->id,
            'password' => Hash::make('some-password'),
        ]);
        Setting::create([
            'complex_id' => null,
            'key' => 'sms_config',
            'value' => json_encode(['apikey' => 'SMS-KEY-SECRET']),
        ]);

        $backup = Backup::create([
            'complex_id' => null, 'type' => 'full', 'status' => 'pending', 'created_by' => null,
        ]);
        app(BackupBuilder::class)->fill($backup);

        $raw = Storage::disk('local')->get($backup->fresh()->path);

        $this->assertStringNotContainsString('$2y$', $raw, 'هشِ رمز نباید در فایل دیده شود');
        $this->assertStringNotContainsString('BANK-SECRET', $raw);
        $this->assertStringNotContainsString('SMS-KEY-SECRET', $raw);
    }

    public function test_an_encrypted_backup_round_trips(): void
    {
        // رمزگذاری وقتی ارزش دارد که بازیابی هم کار کند
        $snapshot = ['meta' => ['type' => 'full'], 'tables' => ['users' => [['name' => 'علی']]]];

        $this->assertSame($snapshot, BackupCipher::open(BackupCipher::seal($snapshot)));
    }

    public function test_an_old_plain_backup_can_still_be_opened(): void
    {
        /*
         * سازگاریِ رو به عقب عمدی است: نصب‌هایی که از قبل بکاپ گرفته‌اند نباید
         * ناگهان ببینند هیچ فایلِ قدیمی‌ای قابل بازیابی نیست — آن هم دقیقاً در
         * روزی که به بکاپ نیاز دارند.
         */
        $legacy = json_encode(['meta' => ['type' => 'full'], 'tables' => []]);

        $this->assertSame(['meta' => ['type' => 'full'], 'tables' => []], BackupCipher::open($legacy));
    }

    public function test_a_backup_from_another_key_fails_with_a_clear_message(): void
    {
        /*
         * محتوایی که با این کلید باز نمی‌شود — همان چیزی که پس از تعویضِ
         * `APP_KEY` اتفاق می‌افتد. پیام باید صریح باشد، وگرنه ادمین فکر
         * می‌کند فایل خراب است و دنبالِ مشکلِ اشتباهی می‌گردد.
         */
        $foreign = (string) json_encode([
            'format' => BackupCipher::FORMAT,
            'version' => BackupCipher::VERSION,
            'encrypted' => true,
            'payload' => base64_encode('not-decryptable-with-this-key'),
        ]);

        $this->expectExceptionMessageMatches('/APP_KEY/');
        BackupCipher::open($foreign);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function makeVictim(): User
    {
        return User::factory()->create([
            'complex_id' => Complex::factory()->create()->id,
            'phone' => '09121234567',
            'password' => Hash::make('victim-strong-password'),
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);
    }
}
