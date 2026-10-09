<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Requests\StoreFeedbackRequest;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use App\Support\PersianName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * نام و نام خانوادگیِ جدا روی حساب (مرحلهٔ ۹ · فاز ۳ · تصمیمِ ۱۲ ِ کارفرما).
 *
 * `first_name` و `last_name` منبعِ حقیقت‌اند و `name` ِ نمایشی از آن دو ساخته
 * می‌شود؛ نامِ حسابِ قبلی با حدس شکسته نمی‌شود و فقط درخواستِ تکمیل می‌گیرد.
 */
class PersonNameTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $overrides */
    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/register', [
            'first_name' => 'مریم',
            'last_name' => 'حسینی‌نژاد',
            'phone' => '09125550101',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
            'accept_terms' => true,
            ...$overrides,
        ]);
    }

    private function legacy(): User
    {
        return User::factory()->create([
            'name' => 'وحید حسنعلی پور مرادی',
            'first_name' => null,
            'last_name' => null,
            'role' => UserRole::Owner,
            'complex_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_registration_stores_both_parts_and_derives_the_display_name(): void
    {
        $code = $this->register()->assertOk()->json('dev_code');
        $this->postJson('/api/register/verify', ['code' => $code])->assertStatus(201);

        $user = User::where('phone', '09125550101')->sole();
        $this->assertSame(['مریم', 'حسینی‌نژاد', 'مریم حسینی‌نژاد'], [$user->first_name, $user->last_name, $user->name]);
        $this->assertFalse($user->needsNameCompletion());
    }

    public function test_registration_normalises_arabic_letters_spaces_and_direction_marks(): void
    {
        $code = $this->register([
            'first_name' => "  علي\u{202E}  ",
            'last_name' => 'كريمي   راد',
        ])->assertOk()->json('dev_code');
        $this->postJson('/api/register/verify', ['code' => $code])->assertStatus(201);

        $user = User::where('phone', '09125550101')->sole();
        $this->assertSame(['علی', 'کریمی راد'], [$user->first_name, $user->last_name]);
    }

    /** همان قاعده‌ی فرمِ بازخورد: فقط حرفِ فارسی، فاصله و نیم‌فاصله */
    public function test_registration_rejects_what_is_not_a_persian_name(): void
    {
        // هفت ورودیِ بد پشتِ هم از سقفِ ۵ در ساعتِ ثبت‌نام می‌گذرد؛ این تست درباره‌ی سقف نیست
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([
            ['first_name' => ''],
            ['last_name' => ''],
            ['first_name' => 'Ali'],
            ['last_name' => 'رضایی۲'],
            ['first_name' => 'م'],
            ['last_name' => '<b>حسینی</b>'],
            ['first_name' => str_repeat('ا', PersianName::PART_MAX + 1)],
        ] as $bad) {
            $field = array_key_first($bad);
            $this->flushSession();
            $this->register($bad)->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, User::count());
        $this->assertSame(PersianName::PATTERN, StoreFeedbackRequest::PERSIAN_NAME);
    }

    /** «وحید حسنعلی پور مرادی» کدام بخش است؟ حدس نمی‌زنیم. */
    public function test_a_legacy_name_is_kept_and_only_asked_to_be_completed(): void
    {
        $user = $this->legacy();

        $this->actingAs($user)->getJson('/api/me')->assertOk()
            ->assertJsonPath('user.name', 'وحید حسنعلی پور مرادی')
            ->assertJsonPath('user.firstName', null)
            ->assertJsonPath('user.needsName', true);

        $this->actingAs($user)->getJson('/api/profile')->assertOk()
            ->assertJsonPath('profile.needsName', true)
            ->assertJsonPath('profile.name', 'وحید حسنعلی پور مرادی');

        $this->assertSame('وحید حسنعلی پور مرادی', $user->fresh()->name);
    }

    public function test_completing_the_profile_sets_both_parts_and_the_name_follows(): void
    {
        $user = $this->legacy();

        $this->actingAs($user)->putJson('/api/profile', [
            'first_name' => 'وحید',
            'last_name' => 'حسنعلی‌پور مرادی',
        ])->assertOk()
            ->assertJsonPath('profile.name', 'وحید حسنعلی‌پور مرادی')
            ->assertJsonPath('profile.needsName', false);

        $this->assertFalse($user->fresh()->needsNameCompletion());
    }

    /** `name` دیگر از درخواست پذیرفته نمی‌شود؛ وگرنه دو منبعِ ناسازگار */
    public function test_the_display_name_cannot_drift_from_its_parts(): void
    {
        $user = $this->legacy();

        $this->actingAs($user)->putJson('/api/profile', [
            'first_name' => 'سارا',
            'last_name' => 'امینی',
            'name' => 'نامِ دلخواهِ دیگر',
        ])->assertOk()->assertJsonPath('profile.name', 'سارا امینی');

        // و نه از راهِ کدِ داخلی
        $fresh = $user->fresh();
        $fresh->update(['name' => 'باز هم دیگر']);
        $this->assertSame('سارا امینی', $fresh->fresh()->name);

        $fresh->update(['last_name' => 'امینی‌فر']);
        $this->assertSame('سارا امینی‌فر', $fresh->fresh()->name);
    }

    public function test_the_profile_requires_both_parts(): void
    {
        $user = $this->legacy();

        $this->actingAs($user)->putJson('/api/profile', ['name' => 'فقط نام'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name']);

        $this->assertSame('وحید حسنعلی پور مرادی', $user->fresh()->name);
    }

    /**
     * نامِ حسابی که خودش دو بخش را ثبت کرده مالِ خودِ کاربر است؛ ویرایشِ مدیر
     * پیش از این خطا بی‌صدا نادیده گرفته می‌شد. حسابِ ساخته‌ی مدیر مثلِ قبل.
     */
    public function test_a_manager_cannot_silently_rename_a_self_named_account(): void
    {
        $complex = Complex::factory()->create();
        $manager = User::factory()->create(['role' => UserRole::ComplexAdmin, 'complex_id' => $complex->id, 'is_active' => true]);
        $selfNamed = User::factory()->create([
            'first_name' => 'سارا', 'last_name' => 'امینی', 'phone' => '09125551111',
            'role' => UserRole::Owner, 'complex_id' => $complex->id, 'is_active' => true,
        ]);
        $legacy = User::factory()->create([
            'name' => 'ساکنِ قدیمی', 'first_name' => null, 'last_name' => null, 'phone' => '09125552222',
            'role' => UserRole::Owner, 'complex_id' => $complex->id, 'is_active' => true,
        ]);

        // مرحلهٔ ۹ · فاز ۶: ویرایش واحد می‌خواهد (F6) و شماره نمی‌پذیرد
        [$unitA, $unitB] = Unit::factory()->count(2)->create(['complex_id' => $complex->id])->all();

        $this->actingAs($manager)->putJson("/api/v1/residents/{$selfNamed->id}", [
            'name' => 'نامِ دیگر', 'role' => 'owner', 'unit_id' => $unitA->id,
        ])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame('سارا امینی', $selfNamed->fresh()->name);

        // همان نام: ویرایشِ بقیه‌ی فیلدها بی‌مانع
        $this->actingAs($manager)->putJson("/api/v1/residents/{$selfNamed->id}", [
            'name' => 'سارا امینی', 'role' => 'tenant', 'unit_id' => $unitA->id,
        ])->assertOk();
        $this->assertSame(UserRole::Tenant, $selfNamed->fresh()->role);

        $this->actingAs($manager)->putJson("/api/v1/residents/{$legacy->id}", [
            'name' => 'ساکنِ تازه‌نام', 'role' => 'owner', 'unit_id' => $unitB->id,
        ])->assertOk();
        $this->assertSame('ساکنِ تازه‌نام', $legacy->fresh()->name);
    }
}
