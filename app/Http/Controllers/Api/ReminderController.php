<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountState;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteReminderRequest;
use App\Http\Requests\ReminderRangeRequest;
use App\Http\Requests\SaveReminderRequest;
use App\Models\Reminder;
use App\Models\ReminderSkip;
use App\Models\User;
use App\Support\Audit;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تقویم و یادآورها (مرحلهٔ ۱۳ · فاز ۷؛ ایرادات ۱۰، بخشِ چهارم).
 *
 * 🔴 یادآور شخصی است و مالِ یک زمینه (بندهای ۸۷، ۹۸ و ۹۹): هر پرسش با **هر دو** شرط — صاحبِ یادآور همین حساب و زمینه‌ی
 * یادآور همین زمینه‌ی سرور (مجتمعِ فعال؛ سوپر ادمین — سکو). یادآورِ دیگری یا یادآورِ مجتمعِ دیگرِ همین حساب ۴۰۴ می‌گیرد،
 * مثلِ یادآوری که نیست. شناسه‌ی مجتمع از درخواست خوانده نمی‌شود.
 *
 * داشبوردِ ثبت‌نامِ اولیه تقویم ندارد (بندِ ۶۹؛ تصمیمِ ۲.۳-۲) — نه در رابط، نه این‌جا.
 */
class ReminderController extends Controller
{
    public const HISTORY_PER_PAGE = 15;

    /** نمونه‌های یک بازه — نشانگرِ روزهای ماه و فهرستِ روزِ برگزیده (بندهای ۹۲ تا ۹۴)؛ سقفِ بازه در `ReminderRangeRequest` */
    public function index(ReminderRangeRequest $request): JsonResponse
    {
        $from = (string) $request->validated('from');
        $to = (string) $request->validated('to');

        $now = CarbonImmutable::now();
        $occurrences = $this->owned()->with('skips')
            ->where('target_date', '<=', $to)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->whereNull('repeat_every_days')->where('target_date', '>=', $from))
                ->orWhere(fn (Builder $q) => $q->whereNotNull('repeat_every_days')
                    ->where(fn (Builder $q) => $q->whereNull('repeat_until')->orWhere('repeat_until', '>=', $from))))
            ->get()
            ->flatMap(fn (Reminder $r) => array_map(fn (string $date) => $this->occurrence($r, $date, $now), $r->occurrencesBetween($from, $to)))
            ->sortBy(fn (array $o) => $o['date'].' '.$o['remindTime'])
            ->values();

        return response()->json(['data' => $occurrences]);
    }

    /** «تاریخچه‌ی یادآورها» — همه‌ی یادآورهای همین زمینه، گذشته هم (بندهای ۸۸ تا ۹۰) */
    public function history(): JsonResponse
    {
        $page = $this->owned()->with('skips')->orderByDesc('created_at')->orderByDesc('id')->paginate(self::HISTORY_PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Reminder $r) => $this->present($r))->values(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Reminder $reminder): JsonResponse
    {
        return response()->json(['reminder' => $this->present($this->guard($reminder))]);
    }

    public function store(SaveReminderRequest $request): JsonResponse
    {
        $context = $this->context();
        $reminder = DB::transaction(fn (): Reminder => $this->create($request->attributesForModel(), $context));

        return response()->json(['message' => 'یادآور ثبت شد.', 'reminder' => $this->present($reminder)], 201);
    }

    /**
     * ویرایش (بندهای ۸۱ و ۸۵): یادآورِ یک‌باره و «کلِ سری» همان ردیف؛ «فقط همین» — نمونه کنار می‌رود و یادآورِ یک‌باره‌ی
     * تازه با مقدارهای تازه؛ «همین و بعدی‌ها» — سری پیش از این نمونه بسته و سریِ تازه از این نمونه.
     */
    public function update(SaveReminderRequest $request, Reminder $reminder): JsonResponse
    {
        $reminder = $this->guard($reminder);
        $attributes = $request->attributesForModel();
        [$scope, $occurrence] = $this->scopeOf($reminder, $request->scope(), $request->input('occurrence'));

        $result = DB::transaction(function () use ($reminder, $attributes, $scope, $occurrence): Reminder {
            if ($scope === 'this') {
                $this->skip($reminder, (string) $occurrence);

                return $this->create([...$attributes, 'repeat_every_days' => null, 'repeat_until' => null, 'repeat_count' => null], $reminder->complex_id);
            }

            if ($scope === 'following') {
                $this->endBefore($reminder, (string) $occurrence);

                return $this->create($attributes, $reminder->complex_id);
            }

            $ruleChanged = $reminder->target_date->toDateString() !== $attributes['target_date']
                || $reminder->repeat_every_days !== $attributes['repeat_every_days'];
            if ($ruleChanged) {
                // نمونه‌های کنارگذاشته‌ی قاعده‌ی پیشین در قاعده‌ی تازه معنایی ندارند
                $reminder->skips()->delete();
            }
            $reminder->fill($attributes)->setRelation('skips', $reminder->skips()->get());
            $reminder->reschedule();
            $reminder->save();

            return $reminder;
        });

        return response()->json(['message' => 'یادآور به‌روزرسانی شد.', 'reminder' => $this->present($result)]);
    }

    /** حذف (بندهای ۸۲ و ۹۱): همان سه دامنه‌ی ویرایش؛ نمونه‌ی کنارگذاشته سابقه‌ی سری را نمی‌برد */
    public function destroy(DeleteReminderRequest $request, Reminder $reminder): JsonResponse
    {
        $reminder = $this->guard($reminder);
        [$scope, $occurrence] = $this->scopeOf($reminder, $request->scope(), $request->occurrence());

        DB::transaction(function () use ($reminder, $scope, $occurrence): void {
            match ($scope) {
                'this' => $this->skip($reminder, (string) $occurrence),
                'following' => $this->endBefore($reminder, (string) $occurrence),
                default => $reminder->delete(),
            };
        });

        return response()->json(['message' => 'یادآور حذف شد.']);
    }

    /** «حذفِ همه» ِ تاریخچه — فقط همین زمینه (بندِ ۹۱)؛ تأیید در رابط */
    public function destroyAll(): JsonResponse
    {
        $count = $this->owned()->delete();
        Audit::log('reminders.cleared', 'حذفِ همه‌ی یادآورها', null, ['count' => $count], $this->context());

        return response()->json(['message' => 'همه‌ی یادآورها حذف شدند.', 'deleted' => $count]);
    }

    /* ─── کمکی ─────────────────────────────────────────────────────────────── */

    /**
     * زمینه‌ی یادآورها: مجتمعِ فعالِ سرور، یا `null` برای سوپر ادمین (سکو). حسابِ اولیه تقویم ندارد.
     */
    private function context(): ?int
    {
        /** @var User $user */
        $user = Auth::user();

        if (AccountState::of($user) === AccountState::Initial) {
            abort(response()->json([
                'success' => false,
                'message' => 'تقویم و یادآور در داشبوردِ ثبت‌نامِ اولیه نیست؛ پس از پیوستن به یک مجتمع در دسترس است.',
                'code' => 'calendar.initial_account',
            ], 403));
        }

        return $user->isSuperAdmin() ? null : $this->requireComplex()->id;
    }

    /** @return Builder<Reminder> */
    private function owned(): Builder
    {
        $context = $this->context();

        return Reminder::where('user_id', Auth::id())
            ->when($context === null, fn (Builder $q) => $q->whereNull('complex_id'), fn (Builder $q) => $q->where('complex_id', $context));
    }

    /** یادآورِ دیگری یا زمینه‌ی دیگر: همان ۴۰۴ ِ «نیست» */
    private function guard(Reminder $reminder): Reminder
    {
        abort_unless($reminder->user_id === Auth::id() && $reminder->complex_id === $this->context(), 404);

        return $reminder->load('skips');
    }

    /** @param  array<string, mixed>  $attributes */
    private function create(array $attributes, ?int $context): Reminder
    {
        $reminder = new Reminder($attributes);
        $reminder->forceFill(['user_id' => Auth::id(), 'complex_id' => $context])->setRelation('skips', collect());
        $reminder->reschedule();
        $reminder->save();

        return $reminder->load('skips');
    }

    /**
     * دامنه‌ی ویرایش یا حذف؛ یادآورِ یک‌باره همیشه «کل». نمونه باید واقعاً مالِ همین سری باشد.
     *
     * @return array{0: string, 1: string|null}
     */
    private function scopeOf(Reminder $reminder, string $scope, mixed $occurrence): array
    {
        if (! $reminder->isRecurring() || $scope === 'all') {
            return ['all', null];
        }

        $date = (string) $occurrence;
        if ($date === '' || $reminder->occurrencesBetween($date, $date) === []) {
            throw ValidationException::withMessages(['occurrence' => 'این تاریخ از نمونه‌های همین یادآور نیست.']);
        }

        // «همین و بعدی‌ها» از نمونه‌ی نخست یعنی کلِ سری
        if ($scope === 'following' && $date === $reminder->target_date->toDateString()) {
            return ['all', null];
        }

        return [$scope, $date];
    }

    private function skip(Reminder $reminder, string $occurrence): void
    {
        ReminderSkip::firstOrCreate(['reminder_id' => $reminder->id, 'occurrence' => $occurrence]);
        $reminder->load('skips')->reschedule();
        $reminder->save();
    }

    /** سری پیش از این نمونه تمام می‌شود؛ نمونه‌های گذشته و تاریخچه‌اش می‌مانند */
    private function endBefore(Reminder $reminder, string $occurrence): void
    {
        $until = CarbonImmutable::parse($occurrence, 'UTC')->subDay();
        if ($reminder->repeat_until === null || $reminder->repeat_until->gt($until)) {
            $reminder->repeat_until = $until;
        }
        $reminder->reschedule();
        $reminder->save();
    }

    /** @return array<string, mixed> */
    private function occurrence(Reminder $reminder, string $date, CarbonImmutable $now): array
    {
        $at = $reminder->remindAtFor($date);

        return [
            'id' => $reminder->id.':'.$date,
            'reminderId' => $reminder->id,
            'date' => $date,
            'dateLabel' => Jalali::date($date, 'j F Y'),
            'remindDateLabel' => Jalali::date($at->toDateTimeString(), 'j F Y'),
            'remindTime' => $reminder->remind_time,
            'title' => $reminder->title,
            'description' => $reminder->description,
            'recurring' => $reminder->isRecurring(),
            'repeatLabel' => $reminder->repeatLabel(),
            'past' => $at->lte($now),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Reminder $reminder): array
    {
        $next = $reminder->next_occurrence?->toDateString();
        $shown = $next ?? $reminder->target_date->toDateString();
        $at = $reminder->remindAtFor($shown);

        return [
            'id' => $reminder->id,
            'title' => $reminder->title,
            'description' => $reminder->description,
            'targetDate' => $reminder->target_date->toDateString(),
            'targetDateLabel' => Jalali::date($shown, 'j F Y'),
            'leadDays' => $reminder->lead_days,
            'remindTime' => $reminder->remind_time,
            'remindDateLabel' => Jalali::date($at->toDateTimeString(), 'j F Y'),
            'repeatEveryDays' => $reminder->repeat_every_days,
            'repeatEnd' => match (true) {
                ! $reminder->isRecurring() => null,
                $reminder->repeat_until !== null => 'until',
                $reminder->repeat_count !== null => 'count',
                default => 'none',
            },
            'repeatUntil' => $reminder->repeat_until?->toDateString(),
            'repeatCount' => $reminder->repeat_count,
            'repeatLabel' => $reminder->repeatLabel(),
            'nextOccurrence' => $next,
            'status' => $reminder->status(),
            'statusLabel' => $reminder->statusLabel(),
            'notifiedCount' => $reminder->notified_count,
            'createdAt' => Jalali::dateTime($reminder->created_at),
        ];
    }
}
