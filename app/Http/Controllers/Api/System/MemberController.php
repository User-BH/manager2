<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\User;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function update(UpdateMemberRequest $request, string $user): JsonResponse
    {
        $target = User::withoutGlobalScopes()->findOrFail($user);

        $data = $request->validated();

        $role = UserRole::from($data['role']);

        // ادمینِ کل نباید نقشِ خودش را از ادمین‌کل پایین بیاورد و خود را قفل کند.
        if ($target->id === Auth::id() && $role !== UserRole::SuperAdmin) {
            throw ValidationException::withMessages([
                'role' => 'نمی‌توانید نقشِ حسابِ خودتان را از «ادمین کل» تغییر دهید.',
            ]);
        }

        $before = ['role' => $target->role->value, 'is_active' => (bool) $target->is_active];

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

        /*
         * نقش و فعال‌بودن حساسِ‌ترین تغییرِ این پنل‌اند (ارتقا تا ادمینِ کل،
         * برگرداندنِ حسابِ غیرفعال) و تا مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶ هیچ
         * ردی نمی‌گذاشتند — برخلافِ غیرفعال‌سازی. حالا قبل و بعد ثبت می‌شود.
         */
        $after = ['role' => $target->role->value, 'is_active' => (bool) $target->is_active];
        if ($after !== $before) {
            Audit::log('member.updated', 'تغییرِ نقش یا وضعیتِ عضو سامانه', $target, [
                'before' => $before,
                'after' => $after,
            ], $target->complex_id);
        }

        return response()->json([
            'message' => 'نقشِ کاربر به‌روزرسانی شد.',
            'user' => $this->present($target->fresh('complex')),
        ]);
    }

    /**
     * «حذفِ عضو» در پنلِ ادمینِ کل = **غیرفعال‌سازی** (مرحلهٔ ۹ · فاز ۶).
     *
     * رابط و مسیر همان ماندند (تصمیمِ کارفرما)؛ فقط پشتِ آن دیگر حذفِ سخت
     * نیست. پیش از این ردیفِ `users` می‌رفت و با آن دوره‌های واحد
     * (`cascadeOnDelete`)، ورودها، دستگاه‌ها و درخواست‌های خدمات؛ پرداخت‌ها و
     * خریدهای اشتراک پرداخت‌کننده‌شان را گم می‌کردند (`NULL`).
     *
     * حالا حساب، مجتمع، نقش، دوره‌ها و همه‌ی سابقه می‌مانند و فقط `is_active`
     * خاموش می‌شود: `EnsureActive` نشستِ باز را در درخواستِ بعدی می‌بندد و ورود
     * رد می‌شود. برگرداندن همان ویرایشِ عضو با `is_active` است.
     */
    public function destroy(string $user): JsonResponse
    {
        $target = User::withoutGlobalScopes()->findOrFail($user);

        if ($target->id === Auth::id()) {
            throw ValidationException::withMessages([
                'user' => 'نمی‌توانید حسابِ خودتان را حذف کنید.',
            ]);
        }

        Audit::log('member.deactivated', 'غیرفعال‌سازیِ عضو سامانه', $target, [
            'name' => $target->name,
            'phone' => $target->phone,
            'role' => $target->role->value,
        ]);

        $target->forceFill(['is_active' => false])->save();

        // «مرا به خاطر بسپار» هم نباید حساب را زنده نگه دارد
        $target->trustedDevices()->delete();

        return response()->json([
            'message' => 'حسابِ کاربر غیرفعال شد؛ داده‌ها و سابقه‌اش حفظ شد.',
        ]);
    }

    /**
     * شکلِ خروجی حالا در `MemberResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(User $user): array
    {
        return (new MemberResource($user))->toArray(request());
    }
}
