<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;
use App\Support\Notifications;
use Illuminate\Notifications\DatabaseNotification;

/**
 * «کدام اعلان‌ها هنوز خوانده نشده‌اند؟» (آزمونِ ۱۰) — همان زنگوله: اعلانِ مجتمعی فقط در زمینه‌ی همان مجتمع، اعلانِ
 * حسابی همه‌جا (`Notifications::inContext`).
 */
final class NotificationsTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'notifications',
            title: 'اعلان‌ها',
            dashboards: ['unit', 'manager', 'platform'],
            source: 'notifications (database) — Notifications::inContext',
            fields: ['title' => 'string', 'read_at' => 'datetime|null', 'created_at' => 'datetime'],
            relations: ['user'],
            filters: ['period', 'خوانده‌نشده'],
            operations: ['read', 'aggregate'],
            derivation: 'شمارِ خوانده‌نشده‌ها = اعلان‌های همین زمینه با read_at تهی',
            access: 'فقط اعلان‌های خودِ حساب در همین زمینه',
            privacy: 'اعلانِ مجتمعِ دیگرِ همین حساب نه',
            time: 'زمانِ ایجادِ اعلان',
            limit: 10,
            presentation: 'شمارِ خوانده‌نشده و فهرستِ آن‌ها (یا ده اعلانِ آخر)',
            keywords: ['اعلان' => 4, 'اعلانها' => 4, 'نوتیف' => 4, 'خوانده نشده' => 2, 'نخوانده' => 2],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $user = $context->user;
        $unreadOnly = $question->mentions('خوانده نشده', 'خواندهنشده', 'نخوانده', 'جدید');
        $query = Notifications::inContext($user->notifications(), $user)
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->when($question->period, fn ($q, $p) => $q->where('created_at', '>=', $p->startUtc())->where('created_at', '<', $p->endUtc()));

        $unread = Notifications::inContext($user->notifications(), $user)->whereNull('read_at')->count();
        $count = (clone $query)->count();
        if ($count === 0) {
            return ToolResult::notFound($unreadOnly ? 'اعلانِ خوانده‌نشده‌ای ندارید.' : 'اعلانی در این بازه ندارید.', $question->period);
        }

        return ToolResult::found(
            $unreadOnly ? 'اعلان‌های خوانده‌نشده: '.$this->count($unread).'.' : 'اعلان‌های شما ('.$this->count($unread).' خوانده‌نشده):',
            [ToolResult::fact('خوانده‌نشده', $this->count($unread))],
            basis: 'اعلان‌های همین حساب در همین زمینه (همان زنگوله)',
            period: $question->period,
            columns: ['اعلان', 'زمان', 'وضعیت'],
            rows: (clone $query)->latest()->limit(10)->get()->map(fn (DatabaseNotification $n) => [
                (string) ($n->data['title'] ?? 'اعلان'),
                Jalali::dateTime($n->created_at),
                $n->read_at === null ? 'خوانده‌نشده' : 'خوانده‌شده',
            ])->all(),
            more: $count > 10,
        );
    }
}
