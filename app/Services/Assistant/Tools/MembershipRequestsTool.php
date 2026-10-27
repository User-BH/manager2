<?php

namespace App\Services\Assistant\Tools;

use App\Models\ComplexInvitation;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;

/**
 * درخواستِ عضویت و دعوت (بندِ ۴.۵-ح؛ آزمونِ ۱۱).
 *
 * - مدیر: درخواست‌های **همین** مجتمع که منتظرِ تصمیم‌اند (همان صفحه‌ی «درخواست‌های عضویت»).
 * - هر عضو (و مدیر با «من»): درخواست‌ها و دعوت‌های **خودِ حساب** — حسابی‌اند، مثلِ اعلانِ دعوت (تصمیمِ ۲.۳-۴).
 */
final class MembershipRequestsTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'requests.membership',
            title: 'درخواست‌های عضویت و دعوت',
            dashboards: ['unit', 'manager'],
            source: 'complex_invitations (direction request/invite) — JoinRequestController / InvitationController',
            fields: ['complex' => 'string', 'status' => 'enum', 'created_at' => 'datetime', 'responded_at' => 'datetime|null', 'applicant' => 'string (مدیر)'],
            relations: ['complex', 'user', 'unit'],
            filters: ['من'],
            operations: ['read', 'aggregate'],
            derivation: 'شمارِ در انتظار = درخواست‌های pending ِ همین مجتمع',
            access: 'مدیر: درخواست‌های همین مجتمع؛ هر حساب: درخواست‌ها و دعوت‌های خودش',
            privacy: 'توکن، کد و شماره‌ی متقاضی برنمی‌گردد',
            time: 'زمانِ ثبت و پاسخ',
            limit: 10,
            presentation: 'فهرست با وضعیت و زمان',
            keywords: ['درخواست عضویت' => 6, 'درخواستهای عضویت' => 6, 'عضویت' => 3, 'دعوت' => 4, 'دعوتنامه' => 4, 'تاییدنشده' => 2, 'تایید نشده' => 2, 'در انتظار' => 1],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        if ($context->isManager() && ! $question->mentions('درخواستهای من', 'درخواست من', 'دعوتهای من', 'خودم')) {
            return $this->pendingInComplex($context);
        }

        return $this->own($context);
    }

    private function pendingInComplex(AssistantContext $context): ToolResult
    {
        $pending = ComplexInvitation::requests()->pending()->where('complex_id', $context->complex?->id)->with('user:id,name');
        $count = (clone $pending)->count();
        if ($count === 0) {
            return ToolResult::notFound('درخواستِ عضویتِ در انتظاری برای این مجتمع نیست.');
        }

        return ToolResult::found(
            'درخواست‌های عضویتِ در انتظار: '.$this->count($count).'.',
            [ToolResult::fact('در انتظار', $this->count($count))],
            basis: 'درخواست‌های عضویتِ همین مجتمع که هنوز تأیید یا رد نشده‌اند',
            columns: ['متقاضی', 'نقش', 'زمان'],
            rows: (clone $pending)->latest()->limit(10)->get()->map(fn (ComplexInvitation $i) => [
                $i->user->name ?? '—', $i->role->label(), Jalali::dateTime($i->created_at),
            ])->all(),
            more: $count > 10,
        );
    }

    private function own(AssistantContext $context): ToolResult
    {
        $user = $context->user;
        $mine = ComplexInvitation::query()->with('complex:id,name')
            ->where(fn ($q) => $q->where(fn ($r) => $r->requests()->where('user_id', $user->id))
                ->when($user->phone, fn ($w, $phone) => $w->orWhere(fn ($i) => $i->invites()->where('phone', $phone))));

        $count = (clone $mine)->count();
        if ($count === 0) {
            return ToolResult::notFound('درخواستِ عضویت یا دعوتی برای حسابِ شما ثبت نیست.');
        }

        return ToolResult::found(
            'درخواست‌ها و دعوت‌های حسابِ شما:',
            basis: 'درخواست‌ها و دعوت‌های همین حساب (حسابی، نه وابسته به مجتمعِ فعال)',
            columns: ['نوع', 'مجتمع', 'وضعیت', 'زمان'],
            rows: (clone $mine)->latest()->limit(10)->get()->map(fn (ComplexInvitation $i) => [
                $i->direction === ComplexInvitation::INVITE ? 'دعوت' : 'درخواست',
                $i->complex->name ?? '—',
                $this->status($i),
                Jalali::dateTime($i->responded_at ?? $i->created_at),
            ])->all(),
            more: $count > 10,
        );
    }

    private function status(ComplexInvitation $invitation): string
    {
        return match ($invitation->status) {
            ComplexInvitation::ACCEPTED => 'پذیرفته شد',
            ComplexInvitation::DECLINED => 'رد شد',
            ComplexInvitation::REVOKED => 'پس گرفته شد',
            default => $invitation->direction === ComplexInvitation::INVITE && ! $invitation->isLive() ? 'منقضی' : 'در انتظار',
        };
    }
}
