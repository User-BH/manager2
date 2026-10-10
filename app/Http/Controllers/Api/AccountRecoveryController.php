<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRecoveryCodeRequest;
use App\Http\Requests\SubmitAccountRecoveryRequest;
use App\Services\Account\AccountRecovery;
use Illuminate\Http\JsonResponse;

/**
 * بازیابیِ حساب برای کسی که به شماره‌ی قبلی دسترسی ندارد — مسیرِ مهمان (مرحلهٔ ۹).
 *
 * فقط **ثبتِ درخواست**؛ هیچ دسترسی‌ای نمی‌دهد. پاسخ‌ها برای همه‌ی شماره‌ها یکسان‌اند.
 */
class AccountRecoveryController extends Controller
{
    public function __construct(private readonly AccountRecovery $recovery) {}

    public function code(AccountRecoveryCodeRequest $request): JsonResponse
    {
        $result = $this->recovery->sendCode($request->validated()['new_phone']);

        return response()->json([
            'message' => 'اگر این شماره قابلِ استفاده باشد، کدِ تأیید به آن فرستاده شد.',
            'devCode' => $result['devCode'],
        ]);
    }

    public function store(SubmitAccountRecoveryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->recovery->submit(
            $data['old_phone'],
            $data['new_phone'],
            $data['code'],
            $data['national_id'] ?? null,
            $data['explanation'],
            $request->ip(),
        );

        return response()->json([
            'message' => 'درخواستِ شما ثبت شد. پس از بررسیِ پشتیبانی، اگر تأیید شود با شماره‌ی تازه و همان رمز وارد شوید؛ اگر رمز را هم ندارید، از «فراموشی رمز» با شماره‌ی تازه.',
        ], 201);
    }
}
