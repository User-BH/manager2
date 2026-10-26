<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ساختارِ نوع × سطح × مدت (مرحلهٔ ۱۳ · فاز ۶؛ ایرادات ۱۰، بندهای ۶، ۱۱ و ۱۶).
 *
 * تا امروز هر ردیفِ `plans` «نام + نوع + یک قیمت + یک مدت» بود؛ نه سطحِ canonical داشت، نه قیمتِ جدا برای مدت‌های
 * ۱، ۳، ۶، ۱۲، ۱۸ و ۲۴ ماه. این مهاجرت **فقط می‌افزاید**:
 *
 *   plans.tier            basic | professional | advanced — برای سه پلنِ پیش‌فرض از slug پر می‌شود
 *   plans.description     توضیحِ کوتاهِ کارت (بندِ ۶)
 *   plan_prices           قیمتِ هر مدتِ هر پلن؛ ردیفِ موجود (مدت و قیمتِ فعلیِ هر پلن) همین‌جا کپی می‌شود
 *   subscriptions.purchase_kind   renewal | change — آنچه خریدار هنگامِ شروعِ پرداخت پذیرفت (بندِ ۲۲)
 *
 * ⚠️ `plans.price` و `plans.months` حذف نمی‌شوند: اشتراک‌ها و قصدهای خریدِ پیشین مبلغ و مدتِ خودشان را دارند و به این
 * دو ستون تکیه نمی‌کنند، ولی برداشتنشان گامِ Contract ِ جداست (`API_COMPATIBILITY.md`). از این پس خرید فقط از
 * `plan_prices` می‌خواند.
 *
 * پلنی که slug ِ پیش‌فرض ندارد (ساخته‌ی سوپر ادمین) سطح نمی‌گیرد — سطح حدس زده نمی‌شود؛ سوپر ادمین در «پکیج‌های اشتراک»
 * تعیینش می‌کند و تا آن روز پلن در کارت‌های خرید نمی‌آید (تمدیدِ همان پلن ممکن می‌ماند).
 */
return new class extends Migration
{
    /** slug ِ سه پلنِ پیش‌فرضِ `2026_01_07_000002_seed_default_plans` ← سطح */
    private const DEFAULT_TIERS = ['basic' => 'basic', 'plus' => 'professional', 'business' => 'advanced'];

    private const DURATIONS = [1, 3, 6, 12, 18, 24];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('tier', 20)->nullable()->after('complex_type')->index();
            $table->string('description', 300)->nullable()->after('name');
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            // قیمت پیکربندی است، نه سابقه: با پلنِ هرگز‌فروخته‌نشده می‌رود (پلنِ فروخته‌شده حذف نمی‌شود — `PlanController::destroy`)
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('months');
            $table->unsignedBigInteger('price'); // تومان
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['plan_id', 'months']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('purchase_kind', 10)->nullable()->after('method');
        });

        foreach (self::DEFAULT_TIERS as $slug => $tier) {
            DB::table('plans')->where('slug', $slug)->whereNull('tier')->update(['tier' => $tier]);
        }

        // مدت و قیمتِ فعلیِ هر پلن همان می‌ماند — فقط جایش عوض می‌شود. مدتِ بیرون از شش مدت کپی نمی‌شود (خریدنی هم نبود).
        $now = now();
        foreach (DB::table('plans')->get(['id', 'months', 'price']) as $plan) {
            if (in_array((int) $plan->months, self::DURATIONS, true)) {
                DB::table('plan_prices')->insert([
                    'plan_id' => $plan->id,
                    'months' => (int) $plan->months,
                    'price' => (int) $plan->price,
                    'is_active' => (int) $plan->price > 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('purchase_kind');
        });

        Schema::dropIfExists('plan_prices');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['tier']);
            $table->dropColumn(['tier', 'description']);
        });
    }
};
