<?php

namespace Tests\Feature;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Expense;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $complex = Complex::create(['name' => 'مجتمع مدیریت', 'slug' => 'mgmt-'.uniqid()]);

        return User::create([
            'complex_id' => $complex->id,
            'name' => 'مدیر', 'phone' => '09121239999', 'role' => UserRole::ComplexAdmin,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /**
     * split_method در مدل Expense به enum کست شده است. صدا زدن ::from() روی
     * آن یک TypeError می‌داد و کل صفحه‌ی مالی ۵۰۰ می‌شد.
     */
    public function test_finance_lists_expenses_that_have_a_split_method(): void
    {
        $admin = $this->admin();
        $period = Jalali::currentPeriod();

        Expense::create([
            'complex_id' => $admin->complex_id,
            'title' => 'قبض آب', 'amount' => 100000,
            'category' => ExpenseCategory::Tenant,
            'period' => $period,
            'spend_date' => now(),
            'split_method' => ChargeRuleType::UtilityByPerson,
            'is_distributed' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->getJson('/api/finance')
            ->assertOk()
            ->assertJsonPath('expenses.0.splitMethod', 'utility_by_person')
            ->assertJsonPath('expenses.0.isDistributed', true);
    }

    /** مجتمع نباید بدون مدیر بماند، وگرنه دیگر کسی به تنظیماتش دسترسی ندارد. */
    public function test_the_last_manager_cannot_be_removed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->deleteJson('/api/managers/'.$admin->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_a_discount_is_replaced_rather_than_duplicated(): void
    {
        $admin = $this->admin();
        $period = Jalali::currentPeriod();

        $unit = \App\Models\Unit::create([
            'complex_id' => $admin->complex_id,
            'unit_number' => '5', 'floor' => 1, 'area' => 80,
            'residents_count' => 2, 'coefficient' => 1,
        ]);

        $payload = ['unit_id' => $unit->id, 'period' => $period, 'amount' => 1000];

        $this->actingAs($admin)->postJson('/api/discounts', $payload)->assertCreated();

        // array_merge و نه عملگر +، چون + مقدار سمت چپ را نگه می‌دارد
        $this->actingAs($admin)
            ->postJson('/api/discounts', array_merge($payload, ['amount' => 2500]))
            ->assertCreated();

        $this->assertDatabaseCount('discounts', 1);
        $this->assertDatabaseHas('discounts', ['unit_id' => $unit->id, 'amount' => 2500]);
    }
}
