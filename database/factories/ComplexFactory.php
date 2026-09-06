<?php

namespace Database\Factories;

use App\Enums\ComplexType;
use App\Models\Complex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Complex>
 *
 * پیش از R14 فقط `UserFactory` وجود داشت و هر تست، مجتمع را دستی با
 * `Complex::create([...])` می‌ساخت — با تکرارِ همان شش فیلد در چندین فایل.
 * هر بار که ستونی اجباری اضافه می‌شد، همه‌ی آن نقاط باید دستی به‌روز می‌شدند.
 */
class ComplexFactory extends Factory
{
    protected $model = Complex::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'مجتمع '.fake()->unique()->numerify('###'),
            // یکتا و کوتاه؛ `slug` قیدِ یکتایی دارد
            'slug' => 'complex-'.fake()->unique()->numerify('######'),
            /*
             * ⚠️ نوع صریح است، نه متکی به پیش‌فرضِ دیتابیس (فاز ۱).
             *
             * پیش‌فرضِ ستون برای پرکردنِ ردیف‌های قدیمی گذاشته شده بود و
             * حالا برداشته شده؛ رکوردی که نوع نداشته باشد باید بلند خطا
             * بدهد، نه اینکه بی‌صدا مسکونی شود.
             */
            'type' => ComplexType::Residential->value,
            'currency' => 'toman',
            'charge_due_day' => 10,
            'payment_gateway' => 'none',
            'messenger_enabled' => true,
        ];
    }

    /** مجتمعِ تجاری — تعرفه‌ی اشتراکش با مسکونی فرق دارد. */
    public function commercial(): static
    {
        return $this->state(fn (): array => ['type' => ComplexType::Commercial->value]);
    }
}
