<?php

namespace Database\Factories;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Unit;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 *
 * ⚠️ پرمصرف‌ترین کارخانه‌ی نبوده‌ی پروژه: تست‌ها ۲۴ بار `Bill::create([...])`
 * دستی داشتند و هر بار همان هفت فیلد را تکرار می‌کردند (R68).
 *
 * 🔴 و تکرارِ دستی فقط زشت نیست — خطرناک است: ستونِ اجباریِ تازه‌ای که
 * اضافه شود، باید در **همه‌ی** آن نقاط دستی به‌روز شود، وگرنه تست‌هایی
 * می‌شکنند که ربطی به آن تغییر ندارند.
 */
class BillFactory extends Factory
{
    protected $model = Bill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->numberBetween(100_000, 3_000_000);

        return [
            'complex_id' => Complex::factory(),
            'unit_id' => Unit::factory(),
            'period' => Jalali::currentPeriod(),
            'base_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => 0,
            'status' => BillStatus::Unpaid->value,
            'due_date' => now()->addDays(10),
        ];
    }

    /**
     * قبضِ سررسیدگذشته.
     *
     * ⚠️ فقط تاریخ عوض نمی‌شود: قبضی که سررسیدش گذشته ولی `unpaid` نیست،
     * حالتِ ناممکنی است که تستِ جریمه را بی‌معنی می‌کند.
     */
    public function overdue(int $days = 10): static
    {
        return $this->state(fn (): array => [
            'due_date' => now()->subDays($days),
            'status' => BillStatus::Unpaid->value,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'paid_amount' => $attributes['total_amount'],
            'status' => BillStatus::Paid->value,
            'paid_at' => now(),
        ]);
    }
}
