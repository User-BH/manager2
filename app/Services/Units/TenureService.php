<?php

namespace App\Services\Units;

use App\Enums\ResidentRelation;
use App\Exceptions\DomainException;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * دوره‌های مالکیت و سکونت (R26).
 *
 * ─── قاعده‌ی محوری: ردیفِ گذشته هرگز بازنویسی نمی‌شود ────────────────────────
 * هر بار که کسی واحدی می‌گیرد، یک ردیفِ **تازه** ساخته می‌شود و ردیف‌های
 * قبلی‌اش با `end_date` بسته می‌شوند. پیش از این `syncWithoutDetaching`
 * استفاده می‌شد که ردیفِ همان (واحد، کاربر) را به‌روز می‌کرد — یعنی
 * مستاجری که واحد ۵ را ترک کرده و دو سال بعد برگشته، دوره‌ی اولش برای
 * همیشه پاک می‌شد.
 */
class TenureService
{
    /**
     * شروعِ یک دوره‌ی تازه برای این کاربر روی این واحد.
     *
     * دوره‌های جاریِ **خودِ کاربر** بسته می‌شوند (چون یک نفر هم‌زمان در دو
     * جا ساکن نیست)، ولی دوره‌های جاریِ **بقیه‌ی ساکنانِ واحد** دست‌نخورده
     * می‌مانند: یک واحد می‌تواند چند مالک و چند ساکن داشته باشد.
     */
    public function open(
        Unit $unit,
        User $user,
        ResidentRelation $relation,
        float $sharePercent = 100,
        ?Carbon $startDate = null,
    ): UnitTenure {
        $start = $startDate ?? now();

        return DB::transaction(function () use ($unit, $user, $relation, $sharePercent, $start) {
            $this->closeAllFor($user, $start);

            if ($relation === ResidentRelation::Owner) {
                $this->guardOwnershipShare($unit, $sharePercent);
            }

            return UnitTenure::create([
                /*
                 * `complex_id` ستونِ NOT NULL است و برخلافِ مدل‌ها،
                 * `BelongsToComplex` روی جدولِ واسط چیزی پر نمی‌کند. از خودِ
                 * واحد خوانده می‌شود و نه از مجتمعِ جاری، تا حتی در دستورهای
                 * کنسولی (که مجتمعِ جاری ندارند) درست بماند.
                 */
                'complex_id' => $unit->complex_id,
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'relation' => $relation,
                'share_percent' => $sharePercent,
                'start_date' => $start,
                'is_current' => true,
            ]);
        }, attempts: 3);
    }

    /** بستنِ یک دوره‌ی مشخص. */
    public function close(UnitTenure $tenure, ?Carbon $endDate = null): UnitTenure
    {
        $end = $endDate ?? now();

        if ($tenure->start_date && $end->lt($tenure->start_date)) {
            throw DomainException::invalid(
                'تاریخ پایان نمی‌تواند پیش از تاریخ شروع باشد.',
                'tenure.invalid_end_date',
            );
        }

        $tenure->update(['is_current' => false, 'end_date' => $end]);

        return $tenure->refresh();
    }

    /**
     * انتقالِ مالکیتِ یک واحد به مجموعه‌ی تازه‌ای از مالکان.
     *
     * ─── چرا یک عملیاتِ مستقل ───────────────────────────────────────────────
     * انتقالِ مالکیت با «افزودنِ یک مالک» فرق دارد: مالکانِ قبلی باید با هم
     * بسته شوند و مالکانِ تازه با هم باز، وگرنه در فاصله‌ی میانی واحد یا
     * بی‌مالک است یا دو مالکِ ۱۰۰ درصدی دارد. هر دو حالت در گزارشِ سهم و در
     * نظرسنجیِ وزنی (R24) خطا می‌سازند.
     *
     * @param  array<int, array{user: User, share: float}>  $newOwners
     * @return Collection<int, UnitTenure>
     */
    public function transferOwnership(Unit $unit, array $newOwners, ?Carbon $at = null): Collection
    {
        if ($newOwners === []) {
            throw DomainException::invalid('دست‌کم یک مالک لازم است.', 'tenure.no_owner');
        }

        $total = array_sum(array_column($newOwners, 'share'));

        // ۰.۰۱ رواداری برای خطای ممیز شناور در سهم‌هایی مثل ۳۳.۳۳
        if (abs($total - 100) > 0.01) {
            throw DomainException::invalid(
                'جمع سهم مالکان باید ۱۰۰ درصد باشد؛ اکنون '.round($total, 2).' درصد است.',
                'tenure.share_mismatch',
            );
        }

        $moment = $at ?? now();

        return DB::transaction(function () use ($unit, $newOwners, $moment) {
            // اول همه‌ی مالکانِ فعلی بسته می‌شوند، بعد تازه‌ها باز
            $unit->tenures()->current()->owners()->get()
                ->each(fn (UnitTenure $tenure) => $this->close($tenure, $moment));

            return collect($newOwners)->map(fn (array $owner) => UnitTenure::create([
                'complex_id' => $unit->complex_id,
                'unit_id' => $unit->id,
                'user_id' => $owner['user']->id,
                'relation' => ResidentRelation::Owner,
                'share_percent' => $owner['share'],
                'start_date' => $moment,
                'is_current' => true,
            ]));
        }, attempts: 3);
    }

    /**
     * تاریخچه‌ی کاملِ یک واحد — تازه‌ترین دوره اول.
     *
     * @return Collection<int, UnitTenure>
     */
    public function history(Unit $unit): Collection
    {
        return $unit->tenures()
            ->with('user:id,name,phone')
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    /** بستنِ همه‌ی دوره‌های جاریِ یک کاربر (روی هر واحدی). */
    private function closeAllFor(User $user, Carbon $at): void
    {
        UnitTenure::where('user_id', $user->id)
            ->current()
            ->get()
            ->each(function (UnitTenure $tenure) use ($at) {
                /*
                 * تاریخِ پایان نباید پیش از شروع بیفتد. اگر دوره‌ای در آینده
                 * شروع شده (ورودِ دستیِ مدیر)، همان روزِ شروع بسته می‌شود تا
                 * بازه‌ی منفی ثبت نشود.
                 */
                $end = $tenure->start_date && $at->lt($tenure->start_date)
                    ? $tenure->start_date
                    : $at;

                $tenure->update(['is_current' => false, 'end_date' => $end]);
            });
    }

    /**
     * جمعِ سهمِ مالکانِ جاری نباید از ۱۰۰ بگذرد.
     *
     * خطا می‌دهد و سهم را خودش کم نمی‌کند: تصحیحِ خودکار یعنی مدیر عددی را
     * ببیند که خودش وارد نکرده.
     */
    private function guardOwnershipShare(Unit $unit, float $incoming): void
    {
        $existing = (float) $unit->tenures()->current()->owners()->sum('share_percent');

        if ($existing + $incoming > 100.01) {
            throw DomainException::invalid(
                'جمع سهم مالکان از ۱۰۰ درصد بیشتر می‌شود (اکنون '.round($existing, 2).' درصد ثبت شده است).',
                'tenure.share_overflow',
            );
        }
    }
}
