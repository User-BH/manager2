<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\Expense;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use App\Services\Features\FeatureFlags;
use App\Services\Search\CommunityLookup;
use App\Support\Jalali;
use App\Support\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * جستجوی سراسری داشبورد.
 *
 * هر گروه دقیقاً همان قید دسترسی صفحه‌ی خودش را دارد: ساکن نباید از راه
 * جستجو به فهرست واحدها یا ساکنین برسد، چون آن صفحات با میدل‌ور
 * `role:` بسته‌اند و در غیر این صورت جستجو یک درِ پشتی می‌شد.
 *
 * نتایج زیر باکس جستجو نشان داده نمی‌شوند؛ کلاینت با کلیک روی ذره‌بین به
 * صفحه‌ی «نتایج جستجو» می‌رود و آنجا این پاسخ را رندر می‌کند.
 */
class SearchController extends Controller
{
    /** بیش از این تعداد در هر گروه نمایش داده نمی‌شود. */
    private const PER_GROUP = 8;

    /**
     * اندازه‌ی هر صفحه وقتی کاربر «همه‌ی نتایجِ این گروه» را باز کرده (`group` + `page`).
     *
     * ⚠️ تا ۱۰/۸ این‌جا سقفِ ثابتِ ۵۰ بود و صفحه‌ی دومی نداشت — و کلاینت حتی آن را هم نمی‌خواست: هر گروه
     * بی‌صدا در ۸ بریده می‌شد. بندِ ۲۰ ِ فایلِ معماری صفحه‌بندی می‌خواهد؛ مجتمعِ ۵۰۰ واحدی «علی» ِ بیش از ۸
     * دارد. صفحه با `OFFSET` است، نه نشانگر: نتیجه‌ی جست‌وجو به ترتیبِ ثابت (نام، شماره) است و چیزی به
     * سرش نمی‌پرد.
     */
    private const PAGE = 20;

    /** صفحه‌ی بیش از این خواسته نمی‌شود: ۲۰۰۰ نتیجه در یک گروه یعنی عبارت باید دقیق‌تر شود. */
    private const MAX_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $term = trim((string) $request->string('q'));

        /*
         * `%` و `_` در LIKE معنی ویژه دارند. بدون این پاکسازی، جستجوی «%»
         * کل جدول را برمی‌گرداند و «_» با هر تک‌نویسه‌ای جور می‌شد — یعنی
         * نتیجه بی‌ربط می‌شد و کاربر گمان می‌کرد جستجو خراب است.
         * (خطر تزریق نبود؛ مقادیر bind می‌شوند.)
         *
         * ⚠️ `query` ِ پاسخ همین عبارت است، نه شکلِ یکدست‌شده‌اش: کلاینت پاسخ را با عبارتِ نشانی می‌سنجد
         * تا پاسخِ کهنه را کنار بگذارد، و «۱۰» ِ تبدیل‌شده به «10» آن را همیشه کهنه می‌دید.
         */
        $term = $this->stripLikeWildcards($term);
        $parsed = SearchTerm::parse($term);

        if (! $parsed->isSearchable()) {
            return response()->json([
                'query' => $term,
                'total' => 0,
                'groups' => [],
                'message' => 'برای جستجو حداقل دو نویسه وارد کنید.',
            ]);
        }

        /*
         * حالتِ «همه‌ی نتایجِ این گروه» (R30).
         *
         * پیش از این هر گروه سقفِ ۸ داشت و کاربر **نمی‌دانست** نتیجه‌ی
         * بیشتری هست یا نه — `count` هم همان ۸ را برمی‌گرداند. حالا یکی
         * بیشتر خوانده می‌شود تا `hasMore` معنا داشته باشد، و با انتخابِ
         * یک گروه سقفش بالا می‌رود.
         */
        $only = $request->string('group')->value();

        $text = $parsed->text;

        $groups = array_values(array_filter([
            $user->isAdmin() ? $this->units($parsed) : null,
            $user->isAdmin() ? $this->residents($parsed) : null,
            $user->isAdmin() ? $this->bills($parsed) : null,
            $user->isAdmin() ? $this->expenses($text) : null,
            $user->isAdmin() ? null : $this->myBills($user, $parsed->digits),
            $this->announcements($user, $text),
            $this->messages($user, $text),
        ], fn (?array $group) => $group !== null && $group['items'] !== []));

        if ($only !== '') {
            $groups = array_values(array_filter($groups, fn (array $g) => $g['id'] === $only));
        }

        return response()->json([
            'query' => $term,
            'total' => array_sum(array_column($groups, 'count')),
            'groups' => $groups,
            'group' => $only ?: null,
            'page' => $this->page(),
            // نامِ قدیمِ R30، همان اندازه‌ی صفحه
            'expandedLimit' => self::PAGE,
        ]);
    }

    /**
     * برداشتن نویسه‌های ویژه‌ی LIKE از عبارت جستجو.
     *
     * چرا حذف و نه اسکیپ؟ اسکیپ‌کردن به بند `ESCAPE` نیاز دارد و نوشتنِ آن
     * بین MySQL (تولید) و SQLite (توسعه و تست) یکسان نیست؛ نتیجه کدی می‌شد که
     * روی یکی درست کار کند و روی دیگری نه. این نویسه‌ها در نام و شماره‌ی واحد
     * و شماره‌ی تلفن معنایی ندارند، پس حذفشان چیزی از جستجو کم نمی‌کند.
     */
    private function stripLikeWildcards(string $term): string
    {
        return str_replace(['%', '_', '\\'], '', $term);
    }

    /**
     * «۱۰» هر واحدی که ۱۰ در شماره‌اش هست؛ «واحد ۱۰» و «مالک واحد ۱۰» فقط خودِ واحدِ ۱۰. دامنه‌ی مستأجر
     * (`Unit`) و شرطِ صریحِ `CommunityLookup` هر دو به همین مجتمع می‌بندند.
     */
    private function units(SearchTerm $term): array
    {
        $complex = $this->currentComplex();
        $matching = $complex ? CommunityLookup::units($complex->id, $term) : null;

        $units = Unit::with('building')
            ->when(
                $matching,
                fn ($q, $ids) => $q->whereIn('units.id', $ids),
                fn ($q) => $q->where(fn ($w) => $w
                    ->where('unit_number', 'like', "%{$term->digits}%")
                    ->orWhere('notes', 'like', "%{$term->text}%")),
            )
            ->orderBy('unit_number')
            ->orderBy('id')
            ->offset($this->offset())
            ->limit($this->limit() + 1)
            ->get();

        return $this->group('units', 'واحدها', 'Building2', '/units', $units->map(fn (Unit $u) => [
            'id' => $u->id,
            'title' => 'واحد '.$u->unit_number,
            'subtitle' => trim(($u->building?->name ? $u->building->name.' · ' : '').'طبقه '.$u->floor),
            'badge' => (float) $u->balance > 0 ? 'بدهکار: '.Jalali::money($u->balance) : null,
            'path' => '/units?search='.rawurlencode($u->unit_number),
        ])->all());
    }

    /**
     * ساکنانِ این مجتمع — با نام، تلفن یا کدِ ملی، یا با «مالک/مستأجر/ساکن واحد N».
     *
     * «مالک واحد ۱۰» پیش از ۱۰/۸ به `name LIKE '%مالک واحد ۱۰%'` می‌رسید و همیشه خالی بود. حالا مالکِ
     * **فعلیِ** واحدِ ۱۰ ِ همین مجتمع است؛ «۱۰» ِ تنها هم ساکنانِ خودِ واحدِ ۱۰ را کنارِ نام و تلفن می‌آورد.
     */
    private function residents(SearchTerm $term): array
    {
        $complex = $this->currentComplex();
        if (! $complex) {
            return $this->group('residents', 'ساکنین', 'Users', '/residents', []);
        }

        $roles = [UserRole::Owner, UserRole::Tenant];
        // «۱۰» ِ تنها: کنارِ نام و تلفن، ساکنانِ خودِ واحدِ ۱۰ (نه ۱۰۰ و ۱۱۰)
        $unitTerm = match (true) {
            $term->unit === null => null,
            $term->exactUnit => $term,
            default => SearchTerm::parse('واحد '.$term->unit),
        };
        $inUnit = $unitTerm ? CommunityLookup::currentResidents($complex->id, $unitTerm) : null;

        $residents = ComplexMembers::query($complex->id, $roles)
            ->where(fn ($q) => $term->exactUnit && $inUnit
                ? $q->whereIn('users.id', $inUnit)
                : $q->whereIn('users.id', CommunityLookup::membersMatching($complex->id, $term, $roles))
                    ->when($inUnit, fn ($w, $ids) => $w->orWhereIn('users.id', $ids)))
            ->with('currentUnits')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->offset($this->offset())
            ->limit($this->limit() + 1)
            ->get();

        return $this->group('residents', 'ساکنین', 'Users', '/residents', $residents->map(fn (User $u) => [
            'id' => $u->id,
            'title' => $u->name,
            'subtitle' => $u->role->label().' · '.Jalali::digits($u->phone),
            'badge' => $u->currentUnits->map(fn ($unit) => 'واحد '.$unit->unit_number)->implode('، ') ?: null,
            'path' => '/residents?search='.rawurlencode($u->name),
        ])->all());
    }

    private function bills(SearchTerm $term): array
    {
        $complex = $this->currentComplex();
        $exactUnit = $complex && $term->exactUnit ? CommunityLookup::units($complex->id, $term) : null;

        $bills = Bill::with('unit')
            ->when(
                $exactUnit,
                fn ($q, $ids) => $q->whereIn('unit_id', $ids),
                fn ($q) => $q->where(fn ($w) => $w
                    ->where('period', 'like', "%{$term->digits}%")
                    ->orWhereHas('unit', fn ($u) => $u->where('unit_number', 'like', "%{$term->digits}%"))),
            )
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->offset($this->offset())
            ->limit($this->limit() + 1)
            ->get();

        return $this->group('bills', 'قبوض', 'Receipt', '/bills', $bills->map(fn (Bill $b) => [
            'id' => $b->id,
            'title' => ($b->unit ? 'واحد '.$b->unit->unit_number : 'قبض').' — '.Jalali::periodLabel($b->period),
            'subtitle' => 'مبلغ '.Jalali::money($b->total_amount).' · '.$b->status->label(),
            'badge' => $b->remaining() > 0 ? 'مانده: '.Jalali::money($b->remaining()) : null,
            'path' => '/bills?period='.rawurlencode($b->period),
        ])->all());
    }

    private function expenses(string $term): array
    {
        $expenses = Expense::where(fn ($q) => $q
            ->where('title', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%"))
            ->orderByDesc('spend_date')
            ->orderByDesc('id')
            ->offset($this->offset())
            ->limit($this->limit() + 1)
            ->get();

        return $this->group('expenses', 'هزینه‌ها', 'Wallet', '/finance', $expenses->map(fn (Expense $e) => [
            'id' => $e->id,
            'title' => $e->title,
            'subtitle' => Jalali::money($e->amount).' · '.Jalali::date($e->spend_date),
            'badge' => null,
            'path' => '/finance',
        ])->all());
    }

    private function myBills(User $user, string $term): array
    {
        $unitIds = $user->currentUnits()->pluck('units.id');
        if ($unitIds->isEmpty()) {
            return $this->group('my-bills', 'صورت‌حساب‌های من', 'Receipt', '/my-bills', []);
        }

        // فقط دوره‌های سکونتِ خودش (مرحلهٔ ۹ · فاز ۶، F4)
        $bills = Bill::with('unit')
            ->visibleToResident($user)
            ->where('period', 'like', "%{$term}%")
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->offset($this->offset())
            ->limit($this->limit() + 1)
            ->get();

        return $this->group('my-bills', 'صورت‌حساب‌های من', 'Receipt', '/my-bills', $bills->map(fn (Bill $b) => [
            'id' => $b->id,
            'title' => Jalali::periodLabel($b->period),
            'subtitle' => 'مبلغ '.Jalali::money($b->total_amount).' · '.$b->status->label(),
            'badge' => $b->remaining() > 0 ? 'مانده: '.Jalali::money($b->remaining()) : null,
            'path' => $b->remaining() > 0 ? '/pay/'.$b->id : '/my-bills',
        ])->all());
    }

    private function announcements(User $user, string $term): array
    {
        $announcements = Announcement::query()
            ->visibleTo($user)
            ->where(fn ($q) => $q
                ->where('title', 'like', "%{$term}%")
                ->orWhere('body', 'like', "%{$term}%"))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->offset($this->offset())
            ->limit($this->limit() + 1)
            ->get();

        return $this->group('announcements', 'اطلاعیه‌ها', 'Megaphone', '/announcements',
            $announcements->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'subtitle' => Str::limit(preg_replace('/\s+/u', ' ', $a->body), 80),
                'badge' => $a->published_at ? Jalali::date($a->published_at) : null,
                'path' => '/announcements?focus='.$a->id,
            ])->all());
    }

    private function messages(User $user, string $term): ?array
    {
        // پیام‌رسانِ خاموش از راهِ جست‌وجو هم باز نمی‌شود
        if (! app(FeatureFlags::class)->enabled('messenger')) {
            return null;
        }

        /*
         * 🔴 همان قاعده‌ی دیدِ خودِ پیام‌رسان (مرحلهٔ ۶ · فاز ۱۴).
         *
         * این پرسش از نخستین نسخه فقط `is_hidden` را می‌سنجید: هر ساکن با یک
         * کلمه تکه‌ای از پیامِ خصوصیِ واحدِ دیگر با مدیریت را — با نامِ
         * نویسنده و شماره‌ی واحد — می‌دید؛ مستأجرِ رفته هم رشته‌ی واحدِ قبلی
         * را. `visibleTo` برای مدیر همه را و برای ساکن فقط کانال، رشته‌ی
         * واحدهای **فعلی** و گروه‌های عضو را برمی‌گرداند.
         *
         * پیام‌های پنهان‌شده توسط مدیر نباید از راه جستجو دوباره پیدا شوند.
         */
        $messages = Message::query()->visibleTo($user)->where('is_hidden', false)->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->where('body', 'like', "%{$term}%")
                ->orWhere('author_name', 'like', "%{$term}%"))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset($this->offset())
            ->limit($this->limit() + 1)
            ->get();

        return $this->group('messages', 'پیام‌رسان', 'MessageSquare', '/messenger',
            $messages->map(fn (Message $m) => [
                'id' => $m->id,
                'title' => $m->author_name ?: 'پیام',
                'subtitle' => Str::limit(preg_replace('/\s+/u', ' ', $m->body), 80),
                'badge' => $m->unit_label ?: null,
                // از فاز ۱۲ صفحه‌ی پیام‌رسان `?focus=` را نمی‌خواند؛ خودِ گفت‌وگو باز می‌شود
                'path' => $m->conversation_id ? '/messenger/'.$m->conversation_id : '/messenger',
            ])->all());
    }

    /**
     * سقفِ فعلی: عادی، یا اندازه‌ی صفحه برای گروهی که کاربر بازش کرده.
     *
     * سازنده‌ها `limit() + 1` می‌خوانند تا بدانیم نتیجه‌ی بیشتری هست یا
     * نه — بدونِ یک `count()` جدا که روی هر هفت گروه هفت کوئریِ اضافه بود.
     */
    private function limit(): int
    {
        return $this->expanded() ? self::PAGE : self::PER_GROUP;
    }

    private function expanded(): bool
    {
        return request()->string('group')->value() !== '';
    }

    /** صفحه فقط در حالتِ «همه‌ی نتایجِ این گروه» معنا دارد؛ نمای کلی همیشه صفحه‌ی اول است. */
    private function page(): int
    {
        return $this->expanded() ? max(1, min(self::MAX_PAGE, request()->integer('page', 1))) : 1;
    }

    private function offset(): int
    {
        return ($this->page() - 1) * $this->limit();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function group(string $id, string $title, string $icon, string $path, array $items): array
    {
        $limit = $this->limit();
        $hasMore = count($items) > $limit;

        return [
            'id' => $id,
            'title' => $title,
            // نام آیکون lucide؛ کلاینت آن را به کامپوننت تبدیل می‌کند
            'icon' => $icon,
            'path' => $path,
            'count' => min(count($items), $limit),
            // «نتیجه‌ی بیشتری هست» — تا کاربر بداند فهرست بریده شده
            'hasMore' => $hasMore,
            // صفحه‌ی بعدِ همین گروه؛ در نمای کلی null است و «همه» صفحه‌ی ۱ ِ گروه را باز می‌کند
            'nextPage' => $hasMore && $this->expanded() && $this->page() < self::MAX_PAGE ? $this->page() + 1 : null,
            'items' => array_slice($items, 0, $limit),
        ];
    }
}
