<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'complex_id' => Complex::factory(),
            'unit_id' => Unit::factory(),
            'amount' => fake()->numberBetween(100_000, 3_000_000),
            'method' => PaymentMethod::Online->value,
            'status' => PaymentStatus::Pending->value,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Success->value,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => PaymentStatus::Failed->value]);
    }
}
