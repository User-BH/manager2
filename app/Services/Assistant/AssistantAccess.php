<?php

namespace App\Services\Assistant;

use App\Enums\AccountState;
use App\Enums\PlanTier;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\User;
use App\Services\Subscription\PlanGate;
use App\Support\ComplexResolver;
use App\Support\SessionContext;

/**
 * چه کسی دستیار دارد (بندِ ۴.۲؛ تصمیم‌های ۲.۳-۳ و ۵.۱۸) — یک منبع برای `/me` (نمایشِ دکمه) و برای هر مسیرِ API.
 *
 * - داشبوردِ ثبت‌نامِ اولیه و صفحه‌ی انتخابِ مجتمع: هرگز — نه دکمه، نه API.
 * - داشبوردهای مجتمع (واحد و مدیر): فقط وقتی اشتراکِ **فعالِ همان مجتمع** سطحِ «پیشرفته» دارد. سطح از ردیفِ پلن
 *   (`plans.tier`) می‌آید؛ اشتراکِ enum ِ قدیمی سطحی ندارد و پیشرفته شمرده نمی‌شود.
 * - سوپر ادمین: در زمینه‌ی سکو یا مجتمعِ برگزیده‌اش؛ داشبوردِ او مالِ هیچ مجتمعی نیست تا پلنی داشته باشد.
 *
 * داشبوردهای آزمایشی همین قاعده را دارند: حساب‌های Preview اشتراکِ واقعیِ پایگاه‌داده‌ی خودشان را دارند (سندِ
 * آزمایشی هیچ استثنایی برای دستیار نمی‌گذارد).
 */
final class AssistantAccess
{
    public const INITIAL = 'assistant.initial_account';

    public const PLAN = 'assistant.plan_required';

    public function __construct(private readonly PlanGate $plans) {}

    /** null یعنی مجاز؛ وگرنه کدِ رد */
    public function denialFor(User $user): ?string
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        if (AccountState::of($user) !== AccountState::Member || SessionContext::detachedAs() !== null) {
            return self::INITIAL;
        }

        $complex = $this->complexOf($user);

        return $complex !== null && $this->tierOf($complex) === PlanTier::Advanced ? null : self::PLAN;
    }

    public function allows(User $user): bool
    {
        return $this->denialFor($user) === null;
    }

    public function contextFor(User $user): AssistantContext
    {
        return new AssistantContext($user, $this->complexOf($user), $user->role ?? UserRole::Tenant);
    }

    public function tierOf(Complex $complex): ?PlanTier
    {
        $tier = $this->plans->activeSubscription($complex)?->planRef?->tier;

        return $tier instanceof PlanTier ? $tier : PlanTier::tryFrom((string) $tier);
    }

    private function complexOf(User $user): ?Complex
    {
        $id = ComplexResolver::idFor($user);

        return $id === null ? null : Complex::find($id);
    }

    public static function message(string $code): string
    {
        return match ($code) {
            self::INITIAL => 'دستیار در داشبوردِ ثبت‌نامِ اولیه نیست؛ پس از پیوستن به یک مجتمع و در داشبوردِ همان مجتمع در دسترس است.',
            default => 'دستیارِ هوشمند قابلیتِ پلنِ پیشرفته‌ی مجتمع است و این مجتمع پلنِ پیشرفته‌ی فعال ندارد.',
        };
    }
}
