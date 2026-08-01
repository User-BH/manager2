<?php

namespace Database\Factories;

use App\Models\Complex;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // اگر مجتمعی داده نشود، یکی ساخته می‌شود؛ `complex_id` اجباری است
            'complex_id' => Complex::factory(),
            'unit_number' => (string) fake()->unique()->numberBetween(1, 9999),
            'floor' => fake()->numberBetween(1, 10),
            'area' => fake()->numberBetween(60, 160),
        ];
    }
}
