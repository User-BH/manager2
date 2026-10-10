<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideAccountRecoveryRequest;
use App\Http\Requests\RejectAccountRecoveryRequest;
use App\Models\AccountRecoveryRequest;
use App\Models\User;
use App\Services\Account\AccountRecovery;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;

/**
 * صفِ بازیابیِ حساب برای ادمینِ کل (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * شواهدِ لازم برای تصمیم کنارِ هم: شماره‌ی قبلی و تازه، انطباقِ کدِ ملی، حسابِ
 * منطبق (نام، مجتمع، نقش، فعال‌بودن) و شرحِ درخواست‌کننده. تأیید و رد هر دو
 * تأییدِ دوباره‌ی رمز می‌خواهند (`reauth`) و در حسابرسی ثبت می‌شوند.
 */
class AccountRecoveryController extends Controller
{
    public function __construct(private readonly AccountRecovery $recovery) {}

    public function index(): JsonResponse
    {
        $requests = AccountRecoveryRequest::with(['user.complex', 'decider'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [AccountRecoveryRequest::PENDING])
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $requests->map(fn (AccountRecoveryRequest $r) => $this->present($r))->values(),
        ]);
    }

    public function approve(DecideAccountRecoveryRequest $request, AccountRecoveryRequest $recovery): JsonResponse
    {
        $data = $request->validated();

        /** @var User $admin */
        $admin = $request->user();
        $this->recovery->approve($recovery, $admin, $data['note'] ?? null);

        return response()->json([
            'message' => 'شماره‌ی حساب تغییر کرد و همه‌ی نشست‌های آن بسته شد.',
            'request' => $this->present($recovery->fresh(['user.complex', 'decider'])),
        ]);
    }

    public function reject(RejectAccountRecoveryRequest $request, AccountRecoveryRequest $recovery): JsonResponse
    {
        $data = $request->validated();

        /** @var User $admin */
        $admin = $request->user();
        $this->recovery->reject($recovery, $admin, $data['note']);

        return response()->json([
            'message' => 'درخواست رد شد.',
            'request' => $this->present($recovery->fresh(['user.complex', 'decider'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(?AccountRecoveryRequest $r): array
    {
        if ($r === null) {
            return [];
        }

        $account = $r->user;

        return [
            'id' => $r->id,
            'status' => $r->status,
            'decidable' => $r->isDecidable(),
            'oldPhone' => $r->old_phone,
            'newPhone' => $r->new_phone,
            'explanation' => $r->explanation,
            // فقط انطباق؛ کدِ ملیِ حساب این‌جا تکرار نمی‌شود
            'nationalIdMatches' => $r->national_id !== null && $account?->national_id
                ? $r->national_id === $account->national_id
                : null,
            'account' => $account ? [
                'id' => $account->id,
                'name' => $account->name,
                'role' => $account->role->label(),
                'complex' => $account->complex?->name,
                'isActive' => (bool) $account->is_active,
                'phoneStillMatches' => $account->phone === $r->old_phone,
            ] : null,
            'createdAt' => $r->created_at ? Jalali::dateTime($r->created_at) : null,
            'decidedAt' => $r->decided_at ? Jalali::dateTime($r->decided_at) : null,
            'decidedBy' => $r->decider?->name,
            'decisionNote' => $r->decision_note,
        ];
    }
}
