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
use App\Support\Jalali;
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

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $term = trim((string) $request->string('q'));

        if (mb_strlen($term) < 2) {
            return response()->json([
                'query' => $term,
                'total' => 0,
                'groups' => [],
                'message' => 'برای جستجو حداقل دو نویسه وارد کنید.',
            ]);
        }

        $groups = array_values(array_filter([
            $user->isAdmin() ? $this->units($term) : null,
            $user->isAdmin() ? $this->residents($term) : null,
            $user->isAdmin() ? $this->bills($term) : null,
            $user->isAdmin() ? $this->expenses($term) : null,
            $user->isAdmin() ? null : $this->myBills($user, $term),
            $this->announcements($user, $term),
            $this->messages($term),
        ], fn (?array $group) => $group !== null && $group['items'] !== []));

        return response()->json([
            'query' => $term,
            'total' => array_sum(array_column($groups, 'count')),
            'groups' => $groups,
        ]);
    }

    private function units(string $term): array
    {
        $units = Unit::with('building')
            ->where(fn ($q) => $q
                ->where('unit_number', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%"))
            ->orderBy('unit_number')
            ->limit(self::PER_GROUP)
            ->get();

        return $this->group('units', 'واحدها', 'Building2', '/units', $units->map(fn (Unit $u) => [
            'id' => $u->id,
            'title' => 'واحد '.$u->unit_number,
            'subtitle' => trim(($u->building?->name ? $u->building->name.' · ' : '').'طبقه '.$u->floor),
            'badge' => (float) $u->balance > 0 ? 'بدهکار: '.Jalali::money($u->balance) : null,
            'path' => '/units?search='.rawurlencode($u->unit_number),
        ])->all());
    }

    private function residents(string $term): array
    {
        $complex = $this->currentComplex();
        if (! $complex) {
            return $this->group('residents', 'ساکنین', 'Users', '/residents', []);
        }

        $residents = User::where('complex_id', $complex->id)
            ->whereIn('role', [UserRole::Owner->value, UserRole::Tenant->value])
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('national_id', 'like', "%{$term}%"))
            ->with('currentUnits')
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get();

        return $this->group('residents', 'ساکنین', 'Users', '/residents', $residents->map(fn (User $u) => [
            'id' => $u->id,
            'title' => $u->name,
            'subtitle' => $u->role->label().' · '.Jalali::digits($u->phone),
            'badge' => $u->currentUnits->map(fn ($unit) => 'واحد '.$unit->unit_number)->implode('، ') ?: null,
            'path' => '/residents?search='.rawurlencode($u->name),
        ])->all());
    }

    private function bills(string $term): array
    {
        $bills = Bill::with('unit')
            ->where(fn ($q) => $q
                ->where('period', 'like', "%{$term}%")
                ->orWhereHas('unit', fn ($u) => $u->where('unit_number', 'like', "%{$term}%")))
            ->orderByDesc('period')
            ->limit(self::PER_GROUP)
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
            ->limit(self::PER_GROUP)
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

        $bills = Bill::with('unit')
            ->whereIn('unit_id', $unitIds)
            ->where('period', 'like', "%{$term}%")
            ->orderByDesc('period')
            ->limit(self::PER_GROUP)
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
            ->limit(self::PER_GROUP)
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

    private function messages(string $term): array
    {
        // پیام‌های پنهان‌شده توسط مدیر نباید از راه جستجو دوباره پیدا شوند
        $messages = Message::where('is_hidden', false)
            ->where(fn ($q) => $q
                ->where('body', 'like', "%{$term}%")
                ->orWhere('author_name', 'like', "%{$term}%"))
            ->orderByDesc('created_at')
            ->limit(self::PER_GROUP)
            ->get();

        return $this->group('messages', 'پیام‌رسان', 'MessageSquare', '/messenger',
            $messages->map(fn (Message $m) => [
                'id' => $m->id,
                'title' => $m->author_name ?: 'پیام',
                'subtitle' => Str::limit(preg_replace('/\s+/u', ' ', $m->body), 80),
                'badge' => $m->unit_label ?: null,
                'path' => '/messenger?focus='.$m->id,
            ])->all());
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     */
    private function group(string $id, string $title, string $icon, string $path, array $items): array
    {
        return [
            'id' => $id,
            'title' => $title,
            // نام آیکون lucide؛ کلاینت آن را به کامپوننت تبدیل می‌کند
            'icon' => $icon,
            'path' => $path,
            'count' => count($items),
            'items' => $items,
        ];
    }
}
