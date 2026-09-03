<?php

namespace Database\Factories;

use App\Enums\SubscriptionPlan;
use App\Models\Complex;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'complex_id' => Complex::factory(),
            'user_id' => User::factory(),
            'plan' => SubscriptionPlan::Pro->value,
            'status' => 'pending',
            'method' => 'receipt',
            'amount' => 500_000,
            'months' => 1,
        ];
    }

    /**
     * اشتراکِ فعال.
     *
     * ⚠️ `starts_at`/`ends_at` بدونِ `status = active` بی‌اثرند و برعکس؛
     * `PlanGate::activeSubscription` هر سه را با هم می‌خواهد.
     */
    public function active(int $months = 1): static
    {
        return $this->state(fn (): array => [
            'status' => 'active',
            'months' => $months,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonths($months),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => 'expired',
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);
    }
}
