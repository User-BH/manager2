<?php

namespace Database\Factories;

use App\Models\Complex;
use App\Models\Expense;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'complex_id' => Complex::factory(),
            'title' => 'هزینه '.fake()->unique()->numerify('###'),
            'amount' => fake()->numberBetween(100_000, 5_000_000),
            'category' => 'tenant',
            'period' => Jalali::currentPeriod(),
            'is_distributed' => false,
        ];
    }

    /**
     * هزینه‌ای که در قبض‌ها پخش می‌شود.
     *
     * ⚠️ `split_method` بدونِ `is_distributed` بی‌اثر است و برعکس؛ این دو
     * همیشه با هم معنی دارند، پس یک state آن‌ها را جفت نگه می‌دارد.
     */
    public function distributed(string $method = 'equal'): static
    {
        return $this->state(fn (): array => [
            'is_distributed' => true,
            'split_method' => $method,
        ]);
    }
}
