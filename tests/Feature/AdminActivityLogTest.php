<?php

namespace Tests\Feature;

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Complex;
use App\Models\Feedback;
use App\Models\User;
use App\Services\Auth\AdminTwoFactor;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * «فعالیتِ مدیران» (۲۰۲۶-۱۰-۲۹، بندِ ۳): پروژه دو مدیر دارد و هر کار باید با نامِ همان مدیر ثبت و فقط در پنلِ سوپر
 * ادمین دیده شود — کدام مدیر به کدام بازخورد پاسخ داد، کدام کاربر را حذف کرد، کی وارد شد، کی رمزش را عوض کرد.
 */
class AdminActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = '/api/v1/system/audit-logs';

    private User $maryam;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maryam = User::factory()->create(['name' => 'مریم کاظمی', 'role' => UserRole::SuperAdmin, 'is_active' => true, 'phone' => '09121110001', 'password' => Hash::make('first-pass-1')]);
        $this->ali = User::factory()->create(['name' => 'علی رضایی', 'role' => UserRole::SuperAdmin, 'is_active' => true, 'phone' => '09121110002', 'password' => Hash::make('second-pass-2')]);
        RateLimiter::clear('feedback-reply:'.$this->maryam->id);
    }

    public function test_each_admin_action_is_listed_under_the_admin_who_did_it(): void
    {
        $sender = User::factory()->create(['name' => 'نگین', 'phone' => '09123334444', 'role' => UserRole::Owner]);
        $feedback = Feedback::create([
            'category' => FeedbackCategory::Bug, 'message' => 'دکمه‌ی پرداخت کار نمی‌کند', 'name' => 'نگین', 'phone' => '09123334444',
            'status' => FeedbackStatus::New, 'message_hash' => hash('sha256', 'm'), 'sender_hash' => hash('sha256', 's'),
        ]);
        $victim = User::factory()->create(['name' => 'کاربرِ حذفی', 'phone' => '09125556666', 'role' => UserRole::Owner]);

        // مریم پاسخ می‌دهد؛ علی بازخورد را بایگانی و کاربری را حذف می‌کند
        $this->actingAs($this->maryam)->postJson("/api/v1/system/feedback/{$feedback->id}/replies", ['body' => 'برطرف شد.'])->assertCreated();
        $this->actingAs($this->ali)->patchJson("/api/v1/system/feedback/{$feedback->id}", ['status' => 'archived'])->assertOk();
        $this->actingAs($this->ali)->withSession([PasswordConfirmation::KEY => now()->timestamp])
            ->deleteJson("/api/v1/system/members/{$victim->id}")->assertOk();
        // کارِ کاربرِ عادی هم در لاگ هست، ولی نه در «فعالیتِ مدیران»
        AuditLog::create(['user_id' => $sender->id, 'action' => 'profile.avatar_changed', 'description' => 'تغییرِ تصویرِ پروفایل', 'created_at' => now()]);

        $rows = collect($this->actingAs($this->maryam)->getJson(self::INDEX.'?scope=admins')->assertOk()->json('data'));
        $this->assertSame(['مریم کاظمی', 'علی رضایی'], collect($this->actingAs($this->maryam)->getJson(self::INDEX)->json('admins'))->pluck('name')->all());

        $this->assertTrue($rows->every(fn (array $r) => $r['byAdmin'] === true));
        $this->assertNull($rows->firstWhere('action', 'profile.avatar_changed'));

        $reply = $rows->firstWhere('action', 'feedback.replied');
        $this->assertSame('مریم کاظمی', $reply['userName']);
        $this->assertSame('پاسخ به بازخوردِ «نگین»', $reply['description']);

        $archived = $rows->firstWhere('action', 'feedback.status_changed');
        $this->assertSame('علی رضایی', $archived['userName']);
        $this->assertStringContainsString('بایگانی', $archived['description']);

        $removed = $rows->firstWhere('action', 'member.deactivated');
        $this->assertSame('علی رضایی', $removed['userName']);
        $this->assertSame('حذف (غیرفعال‌سازیِ) کاربرِ «کاربرِ حذفی»', $removed['description']);

        // فیلترِ «کدام مدیر»
        $onlyAli = collect($this->actingAs($this->maryam)->getJson(self::INDEX.'?scope=admins&admin_id='.$this->ali->id)->json('data'));
        $this->assertSame(['علی رضایی'], $onlyAli->pluck('userName')->unique()->values()->all());
        $this->assertCount(2, $onlyAli);

        // شناسه‌ی کاربرِ غیرمدیر رویدادهای او را بیرون نمی‌کشد
        $this->assertSame([], $this->actingAs($this->maryam)->getJson(self::INDEX.'?scope=admins&admin_id='.$sender->id)->json('data'));
        // «همه‌ی رویدادها» همچنان همه را دارد
        $this->assertNotNull(collect($this->actingAs($this->maryam)->getJson(self::INDEX)->json('data'))->firstWhere('action', 'profile.avatar_changed'));
    }

    public function test_sign_ins_and_password_changes_are_recorded_by_name_and_never_the_password(): void
    {
        $service = app(AdminTwoFactor::class);
        $secret = $service->begin($this->ali)['secret'];
        $service->confirm($this->ali->fresh(), Totp::at($secret, Totp::step() - 1));

        $this->postJson('/api/v1/login', ['phone' => '09121110002', 'password' => 'second-pass-2'])->assertOk()->assertJson(['twoFactorRequired' => true]);
        $this->assertSame(0, AuditLog::where('action', 'admin.signed_in')->count(), 'ورود پیش از کدِ برنامه ثبت شد.');
        $this->postJson('/api/v1/login/two-factor', ['code' => Totp::at($secret, Totp::step())])->assertOk();

        $this->actingAs($this->ali->fresh())->putJson('/api/v1/profile/password', [
            'current_password' => 'second-pass-2', 'password' => 'brand-new-3', 'password_confirmation' => 'brand-new-3',
        ])->assertOk();

        $rows = collect($this->actingAs($this->maryam)->getJson(self::INDEX.'?scope=admins&admin_id='.$this->ali->id)->json('data'));
        $this->assertSame(['profile.password_changed', 'admin.signed_in'], $rows->pluck('action')->all());
        $this->assertSame(['علی رضایی'], $rows->pluck('userName')->unique()->values()->all());
        $this->assertStringNotContainsString('brand-new-3', (string) json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString('second-pass-2', (string) json_encode(AuditLog::all()->toArray()));
    }

    /** فقط پنلِ سوپر ادمین: مدیرِ مجتمع، ساکن و مهمان راهی به این فهرست ندارند */
    public function test_only_the_super_admin_panel_shows_the_admin_activity(): void
    {
        $complex = Complex::factory()->create();
        $manager = User::factory()->create(['role' => UserRole::ComplexAdmin, 'complex_id' => $complex->id, 'is_active' => true]);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'complex_id' => $complex->id, 'is_active' => true]);

        $this->actingAs($manager)->getJson(self::INDEX.'?scope=admins')->assertForbidden();
        $this->actingAs($owner)->getJson(self::INDEX.'?scope=admins')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::INDEX.'?scope=admins')->assertUnauthorized();
    }
}
