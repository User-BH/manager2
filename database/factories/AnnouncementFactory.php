<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Complex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'complex_id' => Complex::factory(),
            'title' => 'اطلاعیه '.fake()->unique()->numerify('###'),
            'body' => fake()->sentence(),
            'audience' => 'all',
            'is_active' => true,
            'is_pinned' => false,
            // ⚠️ منتشرنشده پیش‌فرض نیست: بیشترِ تست‌ها اطلاعیه‌ی دیده‌شدنی می‌خواهند
            'published_at' => now(),
        ];
    }
}
