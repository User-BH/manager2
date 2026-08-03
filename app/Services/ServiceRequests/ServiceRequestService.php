<?php

namespace App\Services\ServiceRequests;

use App\Enums\ServiceRequestStatus;
use App\Exceptions\DomainException;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestComment;
use App\Models\User;
use App\Notifications\ServiceRequestNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * چرخه‌ی وضعیت، واگذاری و اطلاع‌رسانیِ درخواست‌ها (R25).
 *
 * ─── چرا جدولِ گذارها و نه چند `if` ────────────────────────────────────────
 * سه نقش روی یک درخواست کار می‌کنند (مدیر، مسئول، ساکن) و هر کدام فقط
 * بخشی از گذارها را مجاز دارند. با شرطِ پراکنده در کنترلر، دیر یا زود یک
 * مسیر جا می‌ماند — مثلاً ساکن بتواند درخواستِ خودش را «انجام شد» بزند و
 * آمارِ مدیر را خراب کند. اینجا هر گذار یک ردیفِ صریح است.
 */
class ServiceRequestService
{
    /**
     * گذارهای مجاز: وضعیتِ فعلی → وضعیت‌های بعدی.
     *
     * @return array<string, array<int, ServiceRequestStatus>>
     */
    private function transitions(): array
    {
        return [
            ServiceRequestStatus::New->value => [
                ServiceRequestStatus::InProgress,
                ServiceRequestStatus::Resolved,
                ServiceRequestStatus::Rejected,
            ],
            ServiceRequestStatus::InProgress->value => [
                ServiceRequestStatus::Resolved,
                ServiceRequestStatus::Rejected,
            ],
            /*
             * از «انجام شد» دو راه هست: ساکن تایید می‌کند (بسته) یا
             * می‌گوید هنوز درست نشده (برگشت به پیگیری). همین برگشت است
             * که نمی‌گذارد آمارِ «انجام‌شده» دروغ بگوید.
             */
            ServiceRequestStatus::Resolved->value => [
                ServiceRequestStatus::Closed,
                ServiceRequestStatus::InProgress,
            ],
            // بازکردنِ دوباره‌ی پرونده‌ی بایگانی — فقط دستِ مدیر
            ServiceRequestStatus::Closed->value => [ServiceRequestStatus::InProgress],
            ServiceRequestStatus::Rejected->value => [ServiceRequestStatus::InProgress],
        ];
    }

    /**
     * آیا این کاربر می‌تواند درخواست را به این وضعیت ببرد؟
     *
     * جدا از `transitions()` است چون دو پرسشِ متفاوت‌اند: یکی «آیا این گذار
     * از نظرِ چرخه‌ی کار معنا دارد» و دیگری «آیا این آدم اجازه‌اش را دارد».
     */
    private function mayMove(ServiceRequest $request, User $user, ServiceRequestStatus $to): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        $isAssignee = $request->assigned_to === $user->id;
        $isRequester = $request->user_id === $user->id;

        return match ($to) {
            // مسئول کار را برمی‌دارد و انجامش را اعلام می‌کند
            ServiceRequestStatus::InProgress => $isAssignee
                // ساکن هم می‌تواند از «انجام شد» برگرداندش به پیگیری
                || ($isRequester && $request->status === ServiceRequestStatus::Resolved),
            ServiceRequestStatus::Resolved => $isAssignee,

            // تاییدِ نهایی فقط با کسی است که درخواست را داده
            ServiceRequestStatus::Closed => $isRequester,

            // رد کردن تصمیمِ مدیریتی است، نه اجرایی
            ServiceRequestStatus::Rejected => false,
            ServiceRequestStatus::New => false,
        };
    }

    /** تغییرِ وضعیت با اعمالِ هر دو قاعده و ثبتِ مهرهای زمانی. */
    public function changeStatus(
        ServiceRequest $request,
        User $user,
        ServiceRequestStatus $to,
        ?string $note = null,
    ): ServiceRequest {
        $from = $request->status;

        if ($from === $to) {
            return $request;
        }

        $allowed = $this->transitions()[$from->value] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw DomainException::invalid(
                "تغییر وضعیت از «{$from->label()}» به «{$to->label()}» ممکن نیست.",
                'request.invalid_transition',
            );
        }

        if (! $this->mayMove($request, $user, $to)) {
            throw DomainException::invalid(
                'شما اجازه‌ی این تغییر وضعیت را ندارید.',
                'request.not_allowed',
            );
        }

        DB::transaction(function () use ($request, $user, $to, $note) {
            $request->update([
                'status' => $to,
                /*
                 * مهرها با برگشت پاک می‌شوند، وگرنه درخواستی که دوباره باز
                 * شده همچنان تاریخِ «حل شد» را نگه می‌داشت و گزارشِ زمانِ
                 * پاسخگویی را غلط می‌کرد.
                 */
                'resolved_at' => $to === ServiceRequestStatus::Resolved ? now() : null,
                'closed_at' => $to->isFinal() ? now() : null,
            ]);

            if ($note !== null && trim($note) !== '') {
                $this->comment($request, $user, $note, internal: false);
            }
        });

        $this->notifyStatus($request->refresh(), $user, $to);

        return $request;
    }

    /**
     * واگذاری به مسئول.
     *
     * مسئول باید کاربرِ **همان مجتمع** باشد؛ وگرنه با فرستادنِ یک شناسه‌ی
     * دلخواه می‌شد درخواست را به کاربرِ مجتمعِ دیگری چسباند و از آن‌جا هم
     * `visibleTo` عملاً پرونده را برایش باز می‌کرد.
     */
    public function assign(ServiceRequest $request, User $manager, ?User $assignee): ServiceRequest
    {
        if ($assignee && $assignee->complex_id !== $request->complex_id) {
            throw DomainException::invalid('این کاربر عضو مجتمع نیست.', 'request.invalid_assignee');
        }

        $request->update(['assigned_to' => $assignee?->id]);

        if ($assignee && $assignee->id !== $manager->id) {
            $assignee->notify(new ServiceRequestNotification(
                $request,
                'درخواست تازه‌ای به شما واگذار شد',
                $request->title,
            ));
        }

        return $request->refresh();
    }

    /** پیام در پرونده‌ی درخواست. */
    public function comment(
        ServiceRequest $request,
        User $user,
        string $body,
        bool $internal = false,
    ): ServiceRequestComment {
        if ($request->status->isFinal() && ! $user->role->isAdmin()) {
            throw DomainException::invalid(
                'این درخواست بسته شده است.',
                'request.closed',
            );
        }

        // یادداشتِ داخلی کارِ مدیریت است؛ ساکن نباید بتواند چیزی بنویسد که خودش نبیند
        $isInternal = $internal && $user->role->isAdmin();

        $comment = $request->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
            'is_internal' => $isInternal,
        ]);

        if (! $isInternal) {
            $this->notifyParticipants(
                $request,
                except: $user,
                title: 'پیام تازه روی درخواست',
                body: $request->title,
            );
        }

        return $comment;
    }

    private function notifyStatus(ServiceRequest $request, User $actor, ServiceRequestStatus $to): void
    {
        $this->notifyParticipants(
            $request,
            except: $actor,
            title: 'وضعیت درخواست تغییر کرد',
            body: $request->title.' — '.$to->label(),
        );
    }

    /**
     * اطلاع‌رسانی به همه‌ی دست‌اندرکارانِ یک درخواست جز خودِ عاملِ تغییر.
     *
     * مدیران عمداً در این فهرست نیستند: در ساختمانِ پرترافیک، مدیر با هر
     * پیامِ هر درخواست یک اعلان می‌گرفت و زنگوله بی‌فایده می‌شد. مدیر
     * فهرستِ درخواست‌ها را دارد که برایش دقیق‌تر است.
     */
    private function notifyParticipants(
        ServiceRequest $request,
        User $except,
        string $title,
        string $body,
    ): void {
        $this->participants($request)
            ->reject(fn (User $user) => $user->id === $except->id)
            ->each(fn (User $user) => $user->notify(
                new ServiceRequestNotification($request, $title, $body),
            ));
    }

    /** @return Collection<int, User> */
    private function participants(ServiceRequest $request): Collection
    {
        return collect([$request->requester, $request->assignee])
            ->filter()
            ->unique('id')
            ->values();
    }
}
