<?php

namespace App\Services\Assistant\Tools;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\ComplexMention;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Support\Jalali;

/**
 * «مدیرِ مجتمعِ آفتاب کیست؟» (بندهای ۴.۴ و ۴.۸) — مدیرانِ فعالِ واقعیِ همان مجتمع از عضویت‌ها.
 *
 * ساکن نامِ مدیر را می‌بیند (همان که در پیام‌رسان و اطلاعیه‌ها می‌بیند)؛ شماره‌ی تماس فقط برای مدیرِ همان مجتمع و
 * سوپر ادمین (با audit).
 */
final class ComplexManagersTool extends Tool
{
    public function __construct(private readonly ComplexMention $mentions) {}

    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'complex.managers',
            title: 'مدیرانِ مجتمع',
            dashboards: ['unit', 'manager', 'platform'],
            source: 'complex_memberships (role = complex_admin, status = active) — ComplexMembers',
            fields: ['name' => 'string', 'phone' => 'string (حساس)', 'since' => 'date'],
            relations: ['complex', 'user'],
            filters: ['complex (سوپر ادمین با نام)'],
            operations: ['read'],
            derivation: '—',
            access: 'نام برای اعضای همان مجتمع؛ تلفن برای مدیرِ همان مجتمع و سوپر ادمین',
            privacy: 'تلفن به ساکن داده نمی‌شود؛ نقشِ پایان‌یافته یا معلق شمرده نمی‌شود',
            time: 'عضویتِ فعالِ فعلی',
            limit: 10,
            presentation: 'نام، و برای مجاز تلفن',
            // «مدیر» ِ تنها کم‌وزن است: «من مدیر هستم؛ مالکِ واحدِ ۵…» پرسشِ واحد است، نه پرسشِ مدیر
            keywords: ['مدیر مجتمع' => 5, 'مدیران مجتمع' => 5, 'مدیر' => 3, 'مدیران' => 3, 'چه کسی' => 1, 'کیست' => 1],
            sensitive: true,
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        if ($context->isPlatform()) {
            $found = $this->mentions->forPlatform($context, $question);
            if ($found->count() > 1) {
                return ToolResult::clarify('چند مجتمع با این نام هست؛ کدام؟', $found->pluck('name')->all());
            }
            $complex = $found->first();
            if ($complex === null) {
                return ToolResult::notFound('مجتمعی با این نام پیدا نشد؛ نامِ مجتمع را در پرسش بنویسید.');
            }
        } else {
            $complex = Complex::findOrFail($context->complex?->id);
        }

        $managers = ComplexMembers::query($complex->id, [UserRole::ComplexAdmin])->orderBy('users.name')->limit(10)->get(['users.id', 'users.name', 'users.phone']);
        if ($managers->isEmpty()) {
            return ToolResult::notFound("برای «{$complex->name}» مدیرِ فعالی ثبت نشده است.");
        }

        $withPhone = $context->isManager() || $context->isSuperAdmin();

        return ToolResult::found(
            "مدیرِ «{$complex->name}»:",
            columns: $withPhone ? ['نام', 'تلفن'] : ['نام'],
            rows: $managers->map(fn (User $u) => $withPhone ? [$u->name, $u->phone ? Jalali::digits($u->phone) : 'ثبت نشده'] : [$u->name])->all(),
            basis: 'عضویت‌های فعالِ نقشِ مدیر در همان مجتمع',
        );
    }
}
