<?php

namespace App\Services\Poll;

use App\Enums\MessageAudience;
use App\Enums\PollVoterScope;
use App\Enums\PollWeightMode;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\MessagePoll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * واجدِ شرایط بودن، ثبتِ رأی و آمارِ نظرسنجی (R24).
 *
 * ─── چرا سرویسِ جدا و نه چند متد در کنترلر ─────────────────────────────────
 * سه مصرف‌کننده‌ی متفاوت به همین منطق نیاز دارند: کنترلرِ رأی، خروجیِ
 * `MessageResource`، و کارتِ داشبورد. اگر هر کدام شرطِ خودش را می‌نوشت،
 * ممکن بود کارتِ داشبورد کسی را واجدِ شرایط نشان بدهد که سرور رأیش را رد
 * می‌کند.
 *
 * ─── تفکیکِ «دیدن» از «رأی دادن» ───────────────────────────────────────────
 * دامنه‌ی دید کارِ `Message::visibleTo()` است (R23a) و اینجا **بازنویسی
 * نمی‌شود**؛ کنترلر پیش از رسیدن به اینجا آن را بررسی کرده. این کلاس فقط
 * می‌گوید از میانِ کسانی که می‌بینند، چه کسی رأی می‌دهد و رأیش چقدر وزن دارد.
 */
class PollService
{
    /**
     * واحدهایی که نظرسنجی از آن‌ها می‌پرسد.
     *
     * از **مخاطبِ پیام** مشتق می‌شود، نه از یک فهرستِ جدا: اگر مدیر نظرسنجی
     * را فقط برای سه واحد فرستاده، جامعه‌ی آماری هم همان سه واحد است — وگرنه
     * درصدِ مشارکت روی کلِ ساختمان حساب می‌شد و همیشه ناچیز می‌ماند.
     *
     * @return Collection<int, Unit>
     */
    public function eligibleUnits(MessagePoll $poll): Collection
    {
        $message = $poll->message;

        return match ($message->audience) {
            MessageAudience::All => Unit::where('complex_id', $message->complex_id)
                ->where('is_active', true)->get(),

            MessageAudience::Units => $message->recipientUnits()->where('is_active', true)->get(),

            // پیامِ ساکن به مدیریت؛ نظرسنجی روی آن عملاً پیش نمی‌آید
            MessageAudience::Management => $message->unit_id
                ? Unit::whereKey($message->unit_id)->get()
                : new Collection,
        };
    }

    /**
     * کاربرانی که حقِ رأی دارند.
     *
     * فقط ساکنِ **جاری** (`is_current`) شمرده می‌شود؛ مستاجرِ سالِ پیش که
     * ردیفش با `end_date` بسته شده نه رأی می‌دهد و نه در مخرجِ مشارکت
     * می‌آید.
     *
     * @return Collection<int, User>
     */
    public function eligibleVoters(MessagePoll $poll): Collection
    {
        $unitIds = $this->eligibleUnits($poll)->modelKeys();

        if ($unitIds === []) {
            return new Collection;
        }

        /*
         * ⚠️ اینجا `wherePivot()` **کار نمی‌کند** و باید نامِ کاملِ ستونِ
         * جدولِ واسط نوشته شود.
         *
         * `wherePivot` فقط روی خودِ رابطه‌ی BelongsToMany تعریف شده؛
         * سازنده‌ای که `whereHas` به کلوژر می‌دهد Builderِ مدلِ **مقصد**
         * است، پس فراخوانی‌اش از راهِ `__call` به
         * `where('pivot', 'is_current')` تبدیل می‌شد — روی MySQL خطای
         * «Unknown column 'pivot'» و روی SQLite بی‌صدا صفر.
         */
        return User::where('is_active', true)
            ->whereHas('units', function ($query) use ($unitIds, $poll) {
                $query->whereIn('units.id', $unitIds)->where('unit_user.is_current', true);

                if ($relation = $this->relationFor($poll)) {
                    $query->where('unit_user.relation', $relation->value);
                }
            })
            ->get();
    }

    /**
     * واحدی که رأیِ این کاربر به حسابش نوشته می‌شود.
     *
     * ساکنِ چندواحدی (مثلاً مالکِ دو آپارتمان) اولین واحدِ واجدِ شرایطش را
     * می‌گیرد. عمداً چند رأی نمی‌گیرد: با فهرستِ واحدها در یک نظرسنجی،
     * رابط باید از او می‌پرسید «به نمایندگی از کدام واحد؟» و این پیچیدگی
     * ارزشِ حالتِ نادرش را ندارد. در گزارش هم قابلِ توضیح است.
     */
    public function unitFor(MessagePoll $poll, User $user): ?Unit
    {
        $eligible = $this->eligibleUnits($poll)->modelKeys();

        $query = $user->units()->whereIn('units.id', $eligible)->wherePivot('is_current', true);

        if ($relation = $this->relationFor($poll)) {
            $query->wherePivot('relation', $relation->value);
        }

        return $query->first();
    }

    /** «فقط مالکان» و «فقط مستأجران» (بندِ ۶۵)؛ «همه‌ی ساکنین» هر دو. */
    private function relationFor(MessagePoll $poll): ?ResidentRelation
    {
        return match ($poll->voter_scope) {
            PollVoterScope::Owners => ResidentRelation::Owner,
            PollVoterScope::Tenants => ResidentRelation::Tenant,
            PollVoterScope::Residents => null,
        };
    }

    /**
     * چرا این کاربر نمی‌تواند رأی بدهد — یا `null` اگر می‌تواند.
     *
     * پیام برمی‌گرداند و نه `false`، چون رابط باید بگوید **چرا** دکمه خاموش
     * است؛ «رأی‌دادن ممکن نیست» بدونِ دلیل، کاربر را به پشتیبانی می‌فرستد.
     */
    public function blockReason(MessagePoll $poll, User $user): ?string
    {
        if ($poll->isClosed()) {
            return 'این نظرسنجی بسته شده است.';
        }

        // مدیر برگزارکننده است، نه رأی‌دهنده؛ واحدی ندارد که رأیش به آن بنشیند
        if ($user->role->isAdmin()) {
            return 'برگزارکننده در نظرسنجی رأی نمی‌دهد.';
        }

        /*
         * بندِ ۶۹ (مرحلهٔ ۱۰ · فاز ۱۱): رأیِ تازه فقط با عضویتِ **فعال** در همان مجتمع. عضوِ رفته یا معلق — حتی اگر
         * ردیفِ دوره‌اش به اشتباه جاری مانده باشد — رأی نمی‌دهد؛ رأی‌هایی که پیش‌تر داده سرِ جایشان می‌مانند.
         */
        if (! ComplexMembers::has($user, (int) $poll->message->complex_id, [UserRole::Owner, UserRole::Tenant])) {
            return 'عضویتِ شما در این مجتمع فعال نیست.';
        }

        $unit = $this->unitFor($poll, $user);

        if (! $unit) {
            return match ($poll->voter_scope) {
                PollVoterScope::Owners => 'این نظرسنجی فقط برای مالکان است.',
                PollVoterScope::Tenants => 'این نظرسنجی فقط برای مستأجران است.',
                PollVoterScope::Residents => 'واحدی برای شما ثبت نشده است.',
            };
        }

        if ($poll->weight_mode->isUnitBound()) {
            $existing = $poll->votes()->where('unit_id', $unit->id)->first();

            // رأیِ واحد را همان کسی عوض می‌کند که ثبتش کرده
            if ($existing && $existing->user_id !== $user->id) {
                return 'واحد شما پیش‌تر در این نظرسنجی رأی داده است.';
            }
        }

        $own = $poll->votes()->where('user_id', $user->id)->exists();

        if ($own && ! $poll->allow_change) {
            return 'رأی شما ثبت شده و قابل تغییر نیست.';
        }

        return null;
    }

    /**
     * ثبت یا تعویضِ رأی.
     *
     * کلِ کار در یک تراکنش با `lockForUpdate` روی رأی‌های همان نظرسنجی
     * است: بدونِ قفل، دو ساکنِ یک واحد که هم‌زمان کلیک می‌کنند هر دو
     * می‌دیدند «واحد هنوز رأی نداده» و دو رأی برای یک واحد ثبت می‌شد.
     * قیدِ یکتای دیتابیس هم این را نمی‌گرفت، چون برای حالتِ `per_person`
     * باید چند کاربر از یک واحد مجاز بمانند.
     */
    /**
     * رأیِ یک نفر — یک گزینه، یا در چندگزینه‌ای تا `max_selections` گزینه (بندِ ۶۴).
     *
     * رأیِ دوباره جایگزینِ همه‌ی گزینه‌های قبلیِ همان شخص است، نه افزوده بر آن‌ها. هر گزینه باید مالِ **همین**
     * نظرسنجی باشد و تکراری نباشد؛ شمار از سقف نگذرد.
     *
     * @param  list<PollOption>  $options
     */
    public function castVote(MessagePoll $poll, User $user, array $options): void
    {
        $ids = array_map(fn (PollOption $o) => $o->id, $options);

        if ($options === [] || count(array_unique($ids)) !== count($ids)) {
            throw DomainException::invalid('گزینه‌ی انتخابی معتبر نیست.', 'poll.invalid_option');
        }

        foreach ($options as $option) {
            if ($option->message_poll_id !== $poll->id) {
                throw DomainException::invalid('گزینه‌ی انتخابی معتبر نیست.', 'poll.invalid_option');
            }
        }

        if (count($ids) > max(1, (int) $poll->max_selections)) {
            throw DomainException::invalid(
                $poll->max_selections > 1 ? 'حداکثر '.$poll->max_selections.' گزینه را می‌توانید انتخاب کنید.' : 'در این نظرسنجی فقط یک گزینه انتخاب می‌شود.',
                'poll.too_many_options',
            );
        }

        DB::transaction(function () use ($poll, $user, $ids) {
            $poll->votes()->lockForUpdate()->get();

            if ($reason = $this->blockReason($poll, $user)) {
                throw DomainException::invalid($reason, 'poll.not_eligible');
            }

            $unit = $this->unitFor($poll, $user);
            $weight = $this->weightFor($poll, $unit);

            $poll->votes()->where('user_id', $user->id)->delete();

            foreach ($ids as $optionId) {
                PollVote::create([
                    'message_poll_id' => $poll->id, 'user_id' => $user->id, 'poll_option_id' => $optionId,
                    'unit_id' => $unit?->id, 'weight' => $weight,
                ]);
            }
        }, attempts: 3);
    }

    /** وزنِ یک رأی — عکسِ لحظه‌ی ثبت، چون متراژ بعداً ممکن است اصلاح شود. */
    public function weightFor(MessagePoll $poll, ?Unit $unit): float
    {
        if ($poll->weight_mode !== PollWeightMode::ByArea) {
            return 1.0;
        }

        return $unit === null ? 0.0 : max((float) $unit->area, 0);
    }

    /** مجموعِ وزنِ کلِ جامعه‌ی آماری — مخرجِ درصدِ مشارکت. */
    public function totalWeight(MessagePoll $poll): float
    {
        return match ($poll->weight_mode) {
            PollWeightMode::PerPerson => (float) $this->eligibleVoters($poll)->count(),
            PollWeightMode::PerUnit => (float) $this->eligibleUnits($poll)->count(),
            PollWeightMode::ByArea => (float) $this->eligibleUnits($poll)->sum('area'),
        };
    }

    /**
     * نتیجه‌ی کامل، از دیدِ یک کاربرِ مشخص.
     *
     * ─── چرا مشارکت و حد نصاب هم برمی‌گردند ────────────────────────────────
     * «۳ رأی به آبی» یک عدد است، نه یک تصمیم. تا وقتی ندانیم از چند واجدِ
     * شرایط، معلوم نیست نتیجه نماینده‌ی ساختمان است یا نظرِ سه نفر. پس
     * درصدِ مشارکت و وضعیتِ حد نصاب بخشی از خودِ نتیجه‌اند، نه افزوده‌ای
     * اختیاری.
     *
     * @return array<string, mixed>
     */
    public function results(MessagePoll $poll, User $viewer): array
    {
        $votes = $poll->relationLoaded('votes') ? $poll->votes : $poll->votes()->get();

        /*
         * چندگزینه‌ای (بندِ ۶۴): هر رأی‌دهنده چند ردیف دارد. وزنِ ریخته‌شده و مشارکت از رأی‌دهندگانِ **یکتا** است، نه از
         * ردیف‌ها — وگرنه کسی که سه گزینه زده سه بار شمرده می‌شد. سهمِ هر گزینه از همان وزنِ رأی‌دهندگان است، پس در
         * چندگزینه‌ای جمعِ سهم‌ها می‌تواند از ۱۰۰ بگذرد (مثلِ هر رأی‌گیریِ تأییدی).
         */
        $voters = $votes->unique('user_id');
        $castWeight = (float) $voters->sum('weight');
        $totalWeight = $this->totalWeight($poll);
        $turnout = $totalWeight > 0 ? (int) round(($castWeight / $totalWeight) * 100) : 0;

        $options = $poll->options->map(function (PollOption $option) use ($votes, $castWeight) {
            $weight = (float) $votes->where('poll_option_id', $option->id)->sum('weight');

            return [
                'id' => $option->id,
                'label' => $option->label,
                'votes' => $votes->where('poll_option_id', $option->id)->count(),
                'weight' => round($weight, 2),
                // سهم از **آرای داده‌شده** است نه از کلِ جامعه، وگرنه جمعِ
                // درصدها هرگز ۱۰۰ نمی‌شد و نمودار گمراه‌کننده می‌بود
                'share' => $castWeight > 0 ? (int) round(($weight / $castWeight) * 100) : 0,
            ];
        })->values();

        $ranked = $options->sortByDesc('weight')->values();
        $leader = $ranked->first();
        $runnerUp = $ranked->get(1);

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'isClosed' => $poll->isClosed(),
            'closesAt' => $poll->closes_at?->toIso8601String(),
            'voterScope' => $poll->voter_scope->value,
            'voterScopeLabel' => $poll->voter_scope->label(),
            'weightMode' => $poll->weight_mode->value,
            'weightModeLabel' => $poll->weight_mode->label(),
            'weightUnit' => $poll->weight_mode->unitLabel(),
            'allowChange' => (bool) $poll->allow_change,

            'options' => $options->all(),
            'totalVotes' => $voters->count(),
            'myOptionId' => $votes->firstWhere('user_id', $viewer->id)?->poll_option_id,
            // همه‌ی گزینه‌های بیننده و سقف (۱۰/۱۱)
            'myOptionIds' => $votes->where('user_id', $viewer->id)->pluck('poll_option_id')->values()->all(),
            'maxSelections' => max(1, (int) $poll->max_selections),
            'blockReason' => $this->blockReason($poll, $viewer),
            /*
             * بندِ ۶۶: «می‌بیند ولی مجاز نیست» جدا از «بسته است» یا «رأیش قابل تغییر نیست» — رابط با همین می‌گوید «شما
             * مجاز به رأی دادن در این نظرسنجی نیستید». سرور به‌هرحال رأیِ غیرمجاز را خودش رد می‌کند.
             */
            'eligible' => ! $viewer->role->isAdmin()
                && ComplexMembers::has($viewer, (int) $poll->message->complex_id, [UserRole::Owner, UserRole::Tenant])
                && $this->unitFor($poll, $viewer) !== null,

            // ── آمار ──────────────────────────────────────────────────────
            'eligibleWeight' => round($totalWeight, 2),
            'castWeight' => round($castWeight, 2),
            'turnoutPercent' => $turnout,
            'quorumPercent' => $poll->quorum_percent,
            'quorumMet' => $poll->quorum_percent === null || $turnout >= $poll->quorum_percent,

            /*
             * تلهٔ رأیِ وزنی: `units.area` پیش‌فرضش صفر است و مجتمعی که
             * متراژ را وارد نکرده، نظرسنجیِ وزنی‌اش مخرجِ صفر می‌گیرد و
             * مشارکت همیشه ۰٪ می‌ماند. به‌جای شکستِ خاموش، رابط این را
             * صریح به مدیر می‌گوید.
             */
            'weightUnavailable' => $poll->weight_mode === PollWeightMode::ByArea && $totalWeight <= 0,

            /*
             * «برنده» فقط وقتی اعلام می‌شود که نظرسنجی بسته شده باشد.
             * اعلامِ برنده وسطِ رأی‌گیری خودش روی رأی‌های بعدی اثر می‌گذارد.
             */
            'leaderId' => $poll->isClosed() ? $leader['id'] ?? null : null,
            'isTie' => $poll->isClosed()
                && $leader !== null
                && $runnerUp !== null
                && $leader['weight'] === $runnerUp['weight']
                && $leader['weight'] > 0,
        ];
    }
}
