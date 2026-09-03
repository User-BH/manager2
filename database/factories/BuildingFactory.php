<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Complex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    protected $model = Building::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'complex_id' => Complex::factory(),
            'name' => 'بلوک '.fake()->unique()->randomLetter(),
            'floors_count' => fake()->numberBetween(2, 12),
        ];
    }
}
