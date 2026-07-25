<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * مدیریتِ همه‌ی اعضای ثبت‌نام‌شده برای ادمینِ کل.
 *
 * کاربران دامنه‌ی سراسری (ComplexScope) دارند، پس فهرست‌ها معمولاً فقط
 * کاربرانِ مجتمعِ فعال را نشان می‌دهند؛ اینجا با `withoutGlobalScopes` همه‌ی
 * کاربران — از جمله ثبت‌نام‌هایی که هنوز به مجتمعی وصل نشده‌اند — دیده می‌شوند.
 */
class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $query = User::withoutGlobalScopes()->with('complex')->latest();

        if ($search !== '') {
            $normalized = Phone::normalize($search);
            $query->where(function ($q) use ($search, $normalized) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$normalized}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => collect($users->items())->map(fn (User $u) => $this->present($u))->values(),
            'meta' => [
                'total' => $users->total(),
                'page' => $users->currentPage(),
                'lastPage' => $users->lastPage(),
            ],
            'roles' => collect(UserRole::options())
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function update(Request $request, string $user): JsonResponse
    {
        $target = User::withoutGlobalScopes()->findOrFail($user);

        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(UserRole::options()))],
            'is_active' => ['boolean'],
        ], [], ['role' => 'نقش']);

        $role = UserRole::from($data['role']);

        // ادمینِ کل نباید نقشِ خودش را از ادمین‌کل پایین بیاورد و خود را قفل کند.
        if ($target->id === Auth::id() && $role !== UserRole::SuperAdmin) {
            throw ValidationException::withMessages([
                'role' => 'نمی‌توانید نقشِ حسابِ خودتان را از «ادمین کل» تغییر دهید.',
            ]);
        }

        $target->role = $role;

        if (array_key_exists('is_active', $data)) {
            $target->is_active = $data['is_active'];
        }

        // ادمینِ کل به مجتمعِ خاصی وصل نیست و همیشه فعال است.
        if ($role === UserRole::SuperAdmin) {
            $target->complex_id = null;
            $target->is_active = true;
        }

        $target->save();

        return response()->json([
            'message' => 'نقشِ کاربر به‌روزرسانی شد.',
            'user' => $this->present($target->fresh('complex')),
        ]);
    }

    public function destroy(string $user): JsonResponse
    {
        $target = User::withoutGlobalScopes()->findOrFail($user);

        if ($target->id === Auth::id()) {
            throw ValidationException::withMessages([
                'user' => 'نمی‌توانید حسابِ خودتان را حذف کنید.',
            ]);
        }

        $target->delete();

        return response()->json(['message' => 'کاربر حذف شد.']);
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'role' => $user->role->value,
            'roleLabel' => $user->role->label(),
            'isActive' => (bool) $user->is_active,
            'complex' => $user->complex ? ['id' => $user->complex->id, 'name' => $user->complex->name] : null,
            'registeredAt' => Jalali::date($user->created_at),
        ];
    }
}
