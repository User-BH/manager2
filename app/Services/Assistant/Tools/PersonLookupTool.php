<?php

namespace App\Services\Assistant\Tools;

use App\Enums\UserRole;
use App\Models\ComplexMembership;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Services\Search\CommunityLookup;
use App\Support\Jalali;
use App\Support\SearchTerm;
use Illuminate\Support\Facades\DB;

/**
 * «شماره‌ی موبایلِ آقای عسگری موسوی چیست؟» (بندهای ۴.۴ و ۴.۸؛ آزمون‌های ۵، ۱۵ و ۲۲).
 *
 * - مدیر: فقط اعضای فعالِ **همین** مجتمع — همان دامنه‌ی صفحه‌ی ساکنین و جست‌وجوی مدیر.
 * - سوپر ادمین در زمینه‌ی سکو: همه‌ی حساب‌های عملیاتی (بی داده‌ی آزمایشی روی Production) — هر پاسخِ یافته audit
 *   می‌شود (`Assistant`).
 * - ساکن: هرگز؛ اطلاعاتِ تماسِ دیگران در داشبوردِ واحد نیست.
 * - چند نفرِ هم‌نام: هیچ تلفنی برنمی‌گردد؛ فهرستِ قابلِ تشخیص (نام و واحد/مجتمع) و سؤالِ تکمیلی.
 */
final class PersonLookupTool extends Tool
{
    private const ROLES = [UserRole::Owner, UserRole::Tenant, UserRole::ComplexAdmin];

    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'person.lookup',
            title: 'یافتنِ شخص',
            dashboards: ['manager', 'platform'],
            source: 'users + complex_memberships + unit_user — CommunityLookup::membersMatching',
            fields: ['name' => 'string', 'phone' => 'string (حساس)', 'role' => 'enum', 'units' => 'list', 'complexes' => 'list (فقط سکو)'],
            relations: ['memberships', 'units'],
            filters: ['person (نام)'],
            operations: ['read'],
            derivation: '—',
            access: 'مدیر: اعضای فعالِ همین مجتمع؛ سوپر ادمین: حساب‌های عملیاتی با audit',
            privacy: 'هم‌نامی یعنی بی تلفن؛ کدِ ملی، رمز و نشست هرگز',
            time: 'عضویتِ فعلی',
            limit: 8,
            presentation: 'یک نفر: مشخصات؛ چند نفر: فهرستِ قابلِ تشخیص و سؤالِ تکمیلی',
            keywords: ['آقای' => 2, 'خانم' => 2, 'جناب' => 2],
            sensitive: true,
        );
    }

    public function score(Question $question): int
    {
        return $question->person === null ? 0 : 5 + ($question->wantsContact ? 1 : 0);
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $name = (string) $question->person;
        $term = SearchTerm::parse($name);

        $ids = $context->isPlatform()
            ? User::query()->when(! $context->includesDemoData(), fn ($q) => $q->operational())
                ->where('users.name', 'like', "%{$term->text}%")->select('users.id')
            : CommunityLookup::membersMatching((int) $context->complex?->id, $term, self::ROLES);

        $people = User::query()->whereIn('users.id', $ids)->orderBy('users.name')->limit($this->descriptor()->limit + 1)
            ->get(['users.id', 'users.name', 'users.phone', 'users.role']);

        if ($people->isEmpty()) {
            return ToolResult::notFound($context->isPlatform()
                ? "حسابی با نامِ «{$name}» پیدا نشد."
                : "عضوی با نامِ «{$name}» در این مجتمع پیدا نشد.");
        }

        if ($people->count() > 1) {
            return ToolResult::clarify(
                "چند نفر با نامِ «{$name}» هست؛ کدام؟ با نامِ کامل یا شماره‌ی واحد دوباره بپرسید.",
                $people->take($this->descriptor()->limit)->map(fn (User $u) => $u->name.' — '.$this->whereabouts($context, $u))->all(),
            );
        }

        /** @var User $person */
        $person = $people->first();

        return ToolResult::found("اطلاعاتِ «{$person->name}»:", [
            ToolResult::fact('نام', $person->name),
            ToolResult::fact('تلفن', $person->phone ? Jalali::digits($person->phone) : 'ثبت نشده'),
            ToolResult::fact($context->isPlatform() ? 'عضویت‌ها' : 'نقش و واحد', $this->whereabouts($context, $person)),
        ], basis: $context->isPlatform() ? 'حساب‌های عملیاتیِ سامانه' : 'اعضای فعالِ همین مجتمع');
    }

    /** «مالک · واحد ۳» در مجتمع؛ «مدیر در آفتاب، مستأجر در نفیس» در سکو */
    private function whereabouts(AssistantContext $context, User $user): string
    {
        if ($context->isPlatform()) {
            $memberships = DB::table('complex_memberships as cm')->join('complexes', 'complexes.id', '=', 'cm.complex_id')
                ->where('cm.user_id', $user->id)->whereIn('cm.status', ComplexMembership::LIVE)
                ->get(['cm.role', 'complexes.name']);

            return $memberships->isEmpty()
                ? 'بی عضویتِ فعال'
                : $memberships->map(fn (object $m) => UserRole::from($m->role)->label().' در '.$m->name)->implode('، ');
        }

        $complexId = (int) $context->complex?->id;
        $role = ComplexMembers::roleOf($user, $complexId)?->label() ?? 'عضو';
        $units = $user->currentUnits()->where('units.complex_id', $complexId)->pluck('units.unit_number')
            ->map(fn ($n) => 'واحد '.Jalali::digits((string) $n))->implode('، ');

        return $units === '' ? $role : $role.' · '.$units;
    }
}
