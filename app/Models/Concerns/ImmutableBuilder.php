<?php

namespace App\Models\Concerns;

use App\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * سازندهٔ کوئریِ مدلِ تغییرناپذیر — شکافی که رویدادهای مدل نمی‌بستند (فاز ۱).
 *
 * ─── 🔴 چه چیزی باز بود ────────────────────────────────────────────────────
 * تریتِ `Immutable` روی رویدادهای `updating` و `deleting` مدل می‌نشیند و
 * چهار بردار را می‌بندد: `update()`، `delete()`، `forceDelete()` و
 * `fill()->save()`.
 *
 * ⚠️ ولی **به‌روزرسانیِ انبوهِ Eloquent رویدادِ مدل شلیک نمی‌کند.** یعنی این
 * دو خط تا امروز کار می‌کردند و دفتر را عوض می‌کردند:
 *
 *     WalletTransaction::where('id', $id)->update(['amount' => 9999]);
 *     WalletTransaction::where('id', $id)->delete();
 *
 * این حدس نبود؛ با کاوشِ واقعی سنجیده شد: مبلغ از ۱۰۰۰ به ۹۹۹۹ رفت و
 * ردیف حذف شد، بی‌آنکه استثنایی داده شود.
 *
 * ─── چرا اینجا و نه با یک تستِ ساختاری ─────────────────────────────────────
 * ⚠️ تستی که سورس را بخواند و دنبالِ `->update(` بگردد، فقط جلوی کدِ
 * کامیت‌شده را می‌گیرد. دستورِ موقت، `tinker`، و کارِ صف از کنارش رد
 * می‌شوند. محافظت باید جایی باشد که **اجرا** می‌شود.
 *
 * ─── مفرِ عمدی که باز می‌ماند ──────────────────────────────────────────────
 * 🔴 `DB::table('wallet_transactions')->update(...)` همچنان کار می‌کند و
 * این **عمدی** است: بازیابیِ بکاپ و هرسِ دوره‌ای به آن نیاز دارند و
 * هیچ‌کدام از مسیرِ مدل نمی‌گذرند.
 *
 * تفاوتش با شکافِ بالا در **قصد** است: کسی که `DB::table` می‌نویسد
 * می‌داند دارد از مدل عبور می‌کند؛ کسی که `Model::where()->update()`
 * می‌نویسد گمان می‌کند محافظِ مدل هوایش را دارد.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class ImmutableBuilder extends Builder
{
    /** @param  array<string, mixed>  $values */
    public function update(array $values): int
    {
        throw $this->refuse('ویرایش');
    }

    public function delete(): mixed
    {
        throw $this->refuse('حذف');
    }

    /**
     * ⚠️ `increment` و `decrement` هم از مسیرِ کوئری می‌روند، نه از رویدادِ
     * مدل. بدونِ این دو، `->increment('amount')` همچنان باز می‌ماند.
     *
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw $this->refuse('افزایشِ ستون');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw $this->refuse('کاهشِ ستون');
    }

    /**
     * ⚠️ `upsert` در حالتِ برخورد **به‌روزرسانی** می‌کند، پس همان بردار است.
     *
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw $this->refuse('درج‌یا‌به‌روزرسانی');
    }

    private function refuse(string $action): DomainException
    {
        return DomainException::invalid(
            "«{$action}»ِ انبوه روی دفترِ مالی مجاز نیست. برای اصلاح، ردیفِ معکوس ثبت کنید.",
            'ledger.immutable',
        );
    }
}
