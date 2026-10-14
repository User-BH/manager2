<?php

namespace App\Services\Search;

use App\Enums\UserRole;
use App\Models\ComplexMembership;
use App\Support\SearchTerm;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «واحدِ N»، «ساکنانِ فعلیِ واحدِ N» و «اعضایی که نامشان X است» — همه درونِ **یک** مجتمعِ صریح (مرحلهٔ ۱۰ ·
 * فاز ۸؛ بندهای ۲۰، ۴۱ و ۸۲ ِ فایلِ معماری).
 *
 * جست‌وجوی کلیِ مدیر و جست‌وجوی پیام‌رسان هر دو همین را می‌پرسند؛ اگر هر کدام نسخه‌ی خودش را داشت، روزی
 * یکی مرزِ مجتمع را جا می‌انداخت و دیگری نه.
 *
 * ⚠️ عمداً از `DB::table` با `complex_id` ِ صریح، نه از مدل‌های دامنه‌دار: مرز این‌جا شرطِ نوشته‌ی همین
 * پرسش است، نه دامنه‌ی سراسری‌ای که شاید روزی `withoutGlobalScopes` بگیرد. هر سه زیرپرسش‌اند
 * (`whereIn`)، نه فهرستِ شناسه در حافظه — شمارِ کوئری با اندازه‌ی مجتمع رشد نمی‌کند.
 *
 * نمایه‌ها: واحد با یکتاییِ `units(complex_id, unit_number)`؛ ساکنِ فعلی با `unit_user(unit_id, relation,
 * is_current)`؛ عضو با `complex_memberships(complex_id, status)`. جست‌وجوی نام `LIKE '%…%'` است و هیچ
 * نمایه‌ی B-tree آن را نمی‌گیرد — ولی دامنه‌اش اعضای فعالِ همین مجتمع است، نه کلِ جدولِ کاربران.
 */
final class CommunityLookup
{
    /** شناسه‌ی واحدهایی که عبارت به آن‌ها اشاره کرد؛ null اگر عبارت شماره‌ی واحد نبود. */
    public static function units(int $complexId, SearchTerm $term): ?Builder
    {
        if ($term->unit === null) {
            return null;
        }

        return DB::table('units')
            ->select('units.id')
            ->where('units.complex_id', $complexId)
            ->whereNull('units.deleted_at')
            ->when(
                $term->exactUnit,
                fn (Builder $q) => $q->where('units.unit_number', $term->unit),
                fn (Builder $q) => $q->where('units.unit_number', 'like', "%{$term->unit}%"),
            );
    }

    /**
     * کاربرانی که **الان** ساکنِ آن واحدها هستند — با همان نسبتی که عبارت گفت («مالک»، «مستأجر»).
     *
     * دوره‌ی بسته‌شده به حساب نمی‌آید: مستأجرِ رفته «مستأجرِ واحدِ ۲۵» نیست.
     */
    public static function currentResidents(int $complexId, SearchTerm $term): ?Builder
    {
        return self::currentTenures($complexId, $term)?->select('unit_user.user_id');
    }

    /**
     * همان واحدها، فقط آن‌هایی که الان چنین ساکنی دارند. «مالک واحد ۱۰» یعنی واحدِ ۱۰ اگر مالک دارد — نه
     * واحدِ دیگری که همان مالک هم دارد.
     */
    public static function occupiedUnits(int $complexId, SearchTerm $term): ?Builder
    {
        return self::currentTenures($complexId, $term)?->select('unit_user.unit_id');
    }

    private static function currentTenures(int $complexId, SearchTerm $term): ?Builder
    {
        $units = self::units($complexId, $term);
        if ($units === null) {
            return null;
        }

        return DB::table('unit_user')
            ->where('unit_user.complex_id', $complexId)
            ->where('unit_user.is_current', true)
            ->whereIn('unit_user.unit_id', $units)
            ->when($term->relation, fn (Builder $q, $relation) => $q->where('unit_user.relation', $relation->value));
    }

    /** واحدهایی که این کاربران **الان** در این مجتمع ساکنشان‌اند. */
    public static function unitsOf(int $complexId, Builder $userIds): Builder
    {
        return DB::table('unit_user')
            ->select('unit_user.unit_id')
            ->where('unit_user.complex_id', $complexId)
            ->where('unit_user.is_current', true)
            ->whereIn('unit_user.user_id', $userIds);
    }

    /**
     * اعضای فعالِ این مجتمع که نام، تلفن یا کدِ ملی‌شان با عبارت جور است.
     *
     * @param  list<UserRole>  $roles
     */
    public static function membersMatching(int $complexId, SearchTerm $term, array $roles): Builder
    {
        return DB::table('users')
            ->select('users.id')
            ->join('complex_memberships as cm', 'cm.user_id', '=', 'users.id')
            ->where('cm.complex_id', $complexId)
            ->where('cm.status', ComplexMembership::ACTIVE)
            ->whereIn('cm.role', array_map(fn (UserRole $role) => $role->value, $roles))
            ->where(fn (Builder $q) => $q
                ->where('users.name', 'like', "%{$term->text}%")
                ->orWhere('users.phone', 'like', "%{$term->digits}%")
                ->orWhere('users.national_id', 'like', "%{$term->digits}%"));
    }
}
