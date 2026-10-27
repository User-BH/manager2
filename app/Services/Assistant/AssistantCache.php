<?php

namespace App\Services\Assistant;

use App\Models\ComplexMembership;
use App\Models\User;
use App\Services\Assistant\Contracts\AssistantTool;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * کشِ کوتاهِ داده‌ی ساختاریِ غیرحساس (بندِ ۴.۱۳).
 *
 * کلید یعنی مرز: حساب + زمینه (مجتمع و نقش) + مُهرِ عضویت (وضعیت، نقش، آخرین تغییر) + نسخه‌ی کشِ حساب + ماژول + پرسش.
 * پس با تعویضِ مجتمع، تغییرِ نقش یا پایانِ عضویت کلیدِ کهنه دیگر خوانده نمی‌شود؛ تعویضِ زمینه و «پاک‌کردنِ گفت‌وگو»
 * نسخه را هم بالا می‌برند (`forget`). داده‌ی مالی و حساس هرگز کش نمی‌شود (`ToolDescriptor::cacheable`).
 */
final class AssistantCache
{
    public function remember(AssistantContext $context, Question $question, AssistantTool $tool, Closure $resolve): ToolResult
    {
        if (! $tool->descriptor()->cacheable) {
            return $resolve();
        }

        $key = 'assistant:answer:'.sha1((string) json_encode([
            $context->user->id, $context->key(), $this->stamp($context), $this->version($context->user),
            $tool->descriptor()->id, $question->compact,
        ]));

        return Cache::remember($key, (int) config('assistant.cache_seconds', 60), $resolve);
    }

    /** همه‌ی کشِ دستیارِ این حساب بی‌اعتبار — پس از تعویضِ زمینه یا پاک‌کردنِ گفت‌وگو */
    public static function forget(User $user): void
    {
        $key = 'assistant:version:'.$user->id;
        Cache::put($key, (int) Cache::get($key, 0) + 1, now()->addDays(2));
    }

    private function version(User $user): int
    {
        return (int) Cache::get('assistant:version:'.$user->id, 0);
    }

    private function stamp(AssistantContext $context): string
    {
        if ($context->complex === null || $context->isSuperAdmin()) {
            return $context->role->value;
        }

        $membership = ComplexMembership::query()
            ->where('user_id', $context->user->id)->where('complex_id', $context->complex->id)
            ->whereIn('status', ComplexMembership::LIVE)
            ->first(['status', 'role', 'updated_at']);

        return (string) json_encode($membership?->only(['status', 'role', 'updated_at']));
    }
}
