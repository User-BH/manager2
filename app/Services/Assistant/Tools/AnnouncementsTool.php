<?php

namespace App\Services\Assistant\Tools;

use App\Models\Announcement;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;
use App\Support\Notifications;

/** اطلاعیه‌های مجتمع — همان `Announcement::visibleTo` ِ صفحه‌ی اطلاعیه‌ها (مخاطبِ هدف، زمانِ انتشار). */
final class AnnouncementsTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'announcements',
            title: 'اطلاعیه‌ها',
            dashboards: ['unit', 'manager'],
            source: 'announcements — Announcement::visibleTo',
            fields: ['title' => 'string', 'published_at' => 'datetime', 'is_pinned' => 'bool'],
            relations: ['reads'],
            filters: ['period'],
            operations: ['read', 'aggregate'],
            derivation: 'خوانده‌نشده = اطلاعیه‌های قابلِ دید بی ردیفِ خواندن',
            access: 'همان مخاطبانِ هر اطلاعیه',
            privacy: '—',
            time: 'زمانِ انتشار؛ بی بازه = ده اطلاعیه‌ی آخر',
            limit: 10,
            presentation: 'فهرستِ عنوان و تاریخ',
            keywords: ['اطلاعیه' => 5, 'اطلاعیهها' => 5, 'آگهی' => 2, 'اطلاع رسانی' => 3],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $visible = Announcement::query()->visibleTo($context->user)
            ->when($question->period, fn ($q, $p) => $q->where('published_at', '>=', $p->startUtc())->where('published_at', '<', $p->endUtc()));

        $count = (clone $visible)->count();
        if ($count === 0) {
            return ToolResult::notFound('اطلاعیه‌ای نیست.', $question->period);
        }

        return ToolResult::found(
            'اطلاعیه‌ها: '.$this->count($count).' مورد، '.$this->count(Notifications::unreadAnnouncementCount($context->user)).' خوانده‌نشده.',
            [ToolResult::fact('تعداد', $this->count($count))],
            basis: 'اطلاعیه‌هایی که در صفحه‌ی «اطلاعیه‌ها» می‌بینید',
            period: $question->period,
            columns: ['عنوان', 'تاریخِ انتشار'],
            rows: (clone $visible)->orderByDesc('published_at')->limit(10)->get()
                ->map(fn (Announcement $a) => [$a->title, Jalali::date($a->published_at)])->all(),
            more: $count > 10,
        );
    }
}
