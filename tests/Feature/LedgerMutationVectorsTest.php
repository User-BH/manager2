<?php

namespace Tests\Feature;

use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * هشت راهِ عوض‌کردنِ دفترِ مالی — کدام بسته است و کدام عمداً باز (فاز ۱).
 *
 * ─── 🔴 چرا این تست وجود دارد ──────────────────────────────────────────────
 * `WalletLedgerTest` از قبل می‌سنجید که برنامه دفتر را ویرایش نمی‌کند، و
 * تریتِ `Immutable` رویدادهای مدل را می‌بست. هر دو درست بودند و هر دو یک
 * چیز را از قلم انداخته بودند:
 *
 * ⚠️ **به‌روزرسانیِ انبوهِ Eloquent رویدادِ مدل شلیک نمی‌کند.**
 *
 * کاوشِ فاز ۱ نشان داد این دو خط واقعاً کار می‌کردند — مبلغ از ۱۰۰۰ به
 * ۹۹۹۹ رفت و ردیف حذف شد، بدونِ هیچ استثنایی:
 *
 *     WalletTransaction::where('id', $id)->update(['amount' => 9999]);
 *     WalletTransaction::where('id', $id)->delete();
 *
 * 🔴 و نکته‌ی تلخش این است: هیچ‌کدام از دو محافظِ موجود دروغ نمی‌گفتند.
 * هرکدام دقیقاً همان چیزی را می‌سنجیدند که ادعا می‌کردند. شکاف **بینِ**
 * آن دو بود.
 *
 * پس این تست دیگر سراغِ «آیا کد چنین می‌کند» نمی‌رود؛ **خودِ حمله را
 * اجرا می‌کند** و می‌پرسد آیا دفتر عوض شد.
 */
class LedgerMutationVectorsTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create();
        $this->unit = Unit::factory()->for($this->complex)->create();
    }

    private function row(): WalletTransaction
    {
        return WalletTransaction::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            // کیف مالِ حساب است (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶)
            'user_id' => User::factory()->create(['complex_id' => $this->complex->id])->id,
            'direction' => WalletTransaction::CREDIT,
            'amount' => 1000,
            'balance_after' => 1000,
            'source' => WalletTransaction::SOURCE_ADJUSTMENT,
        ]);
    }

    /**
     * بردارهایی که **باید** بسته باشند.
     *
     * ⚠️ هر کدام یک راهِ واقعیِ لاراول است، نه سناریوی ساختگی. سه تای
     * آخر تا فاز ۱ باز بودند.
     *
     * @return array<string, array{0: \Closure}>
     */
    public static function blockedVectors(): array
    {
        return [
            'ویرایشِ نمونه' => [fn (WalletTransaction $r) => $r->update(['amount' => 9999])],
            'حذفِ نمونه' => [fn (WalletTransaction $r) => $r->delete()],
            'حذفِ قطعی' => [fn (WalletTransaction $r) => $r->forceDelete()],
            'پرکردن و ذخیره' => [fn (WalletTransaction $r) => $r->fill(['amount' => 9999])->save()],
            'به‌روزرسانیِ انبوه' => [fn (WalletTransaction $r) => WalletTransaction::withoutGlobalScopes()
                ->where('id', $r->id)->update(['amount' => 9999])],
            'حذفِ انبوه' => [fn (WalletTransaction $r) => WalletTransaction::withoutGlobalScopes()
                ->where('id', $r->id)->delete()],
            'افزایشِ ستون' => [fn (WalletTransaction $r) => WalletTransaction::withoutGlobalScopes()
                ->where('id', $r->id)->increment('amount', 5000)],
        ];
    }

    #[DataProvider('blockedVectors')]
    public function test_the_ledger_refuses_every_mutation_path(\Closure $attack): void
    {
        $row = $this->row();

        try {
            $attack($row);
            $this->fail('حمله استثنا نداد؛ این بردار باز است.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(
                AssertionFailedError::class,
                $e,
                'حمله استثنا نداد؛ این بردار باز است.',
            );
        }

        /*
         * 🔴 ادعای اصلی همین است، نه «استثنا داده شد».
         *
         * ⚠️ استثنا دادن کافی نیست: ممکن است پس از نوشتن پرتاب شود. تنها
         * سنجهٔ معتبر این است که ردیف **در دیتابیس** دست‌نخورده مانده باشد.
         */
        $fresh = DB::table('wallet_transactions')->where('id', $row->id)->first();

        $this->assertNotNull($fresh, 'ردیفِ دفتر حذف شد.');

        /*
         * ⚠️ مقایسه **عددی** است نه رشته‌ای، و این عمدی است: `DB::table`
         * مقدارِ خامِ موتور را می‌دهد. SQLite «1000» برمی‌گرداند و MySQL
         * «1000.00» — تستی که رشته بسنجد روی یکی از دو موتور می‌شکند
         * بی‌آنکه چیزی خراب شده باشد.
         */
        $this->assertSame(1000.0, (float) $fresh->amount, 'مبلغِ ردیفِ دفتر عوض شد.');
    }

    /**
     * 🔴 مفرِ عمدی — و اینکه واقعاً باز مانده باشد هم باید سنجیده شود.
     *
     * ─── چرا این تست «برعکس» است ───────────────────────────────────────────
     * ⚠️ `DB::table()` عمداً از محافظ رد می‌شود، چون بازیابیِ بکاپ و هرسِ
     * دوره‌ای از مسیرِ مدل نمی‌گذرند و بدونِ آن کار نمی‌کنند.
     *
     * اگر روزی کسی این مفر را هم ببندد، بازیابیِ بکاپ بی‌صدا خراب می‌شود —
     * جایی که هیچ‌کس تا روزِ حادثه سراغش نمی‌رود. پس همان‌قدر که بسته‌بودنِ
     * بردارهای بالا مهم است، **باز ماندنِ این یکی هم ادعایی است که باید
     * سنجیده شود.**
     */
    public function test_the_raw_query_escape_hatch_stays_open_on_purpose(): void
    {
        $row = $this->row();

        DB::table('wallet_transactions')->where('id', $row->id)->update(['amount' => 9999]);

        $this->assertSame(
            9999.0,
            (float) DB::table('wallet_transactions')->where('id', $row->id)->value('amount'),
            '⚠️ مفرِ `DB::table` بسته شده؛ بازیابیِ بکاپ و هرس دیگر کار نمی‌کنند.',
        );
    }

    /**
     * ⚠️ افزودنِ ردیفِ تازه باید همچنان کار کند.
     *
     * محافظی که نوشتن را هم ببندد، دفتر را از کار می‌اندازد — و چون
     * تست‌های دیگر با فکتوری ردیف می‌سازند، این خرابی دیر پیدا می‌شود.
     */
    public function test_appending_a_new_row_still_works(): void
    {
        $this->row();
        $this->row();

        $this->assertSame(
            2,
            WalletTransaction::withoutGlobalScopes()->where('unit_id', $this->unit->id)->count(),
        );
    }
}
