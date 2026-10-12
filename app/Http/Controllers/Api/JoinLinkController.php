<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Services\Account\PublicJoinLink;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QR ِ ورودِ عمومیِ مجتمع (مرحلهٔ ۱۰ · فاز ۵؛ بندهای ۲۲ و ۲۵ ِ فایلِ معماری).
 *
 * سمتِ مدیر: دیدن، ساختن، عوض‌کردن و خاموش‌کردنِ کد. سمتِ دارنده‌ی QR (مهمان هم): فقط نامِ مجتمع.
 * درخواستِ عضویت از همان `POST join-requests` می‌رود و تا تأییدِ مدیر عضویتی نمی‌سازد.
 */
class JoinLinkController extends Controller
{
    public function __construct(private readonly PublicJoinLink $links) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->complex()));
    }

    /** نمایشِ QR: کدِ موجود می‌ماند (QR ِ چاپ‌شده باطل نمی‌شود)؛ فقط اگر نیست ساخته می‌شود. */
    public function store(): JsonResponse
    {
        $complex = $this->complex();
        $created = $complex->join_code === null;

        $this->links->ensure($complex);

        if ($created) {
            Audit::log('join_link.created', 'روشن‌کردنِ ورودِ عمومی با QR', $complex);
        }

        return response()->json($this->payload($complex), $created ? 201 : 200);
    }

    /** QR ِ تازه؛ QR ِ قبلی از همین لحظه «نامعتبر» است. */
    public function rotate(): JsonResponse
    {
        $complex = $this->complex();
        $this->links->rotate($complex);

        Audit::log('join_link.rotated', 'تعویضِ QR ِ ورودِ عمومی', $complex);

        return response()->json($this->payload($complex));
    }

    public function destroy(): JsonResponse
    {
        $complex = $this->complex();
        $this->links->disable($complex);

        Audit::log('join_link.disabled', 'خاموش‌کردنِ ورودِ عمومی با QR', $complex);

        return response()->json($this->payload($complex));
    }

    /**
     * پیش‌نمایش برای دارنده‌ی QR — مهمان هم، چون اسکن‌کننده شاید هنوز حساب ندارد.
     *
     * فقط نامِ مجتمع؛ ۴۰۴ ِ یکسان برای کدِ بدشکل، باطل‌شده و مجتمعِ تعلیق‌شده.
     */
    public function preview(Request $request): JsonResponse
    {
        $code = $request->input('code');
        $complex = is_string($code) ? $this->links->resolve($code) : null;

        abort_if($complex === null, 404);

        return response()->json(['complexName' => $complex->name]);
    }

    /**
     * ردیفِ **تازه‌ی** مجتمعِ فعال.
     *
     * ⚠️ `requireComplex` نمونه را روی کنترلر نگه می‌دارد و لاراول کنترلر را روی خودِ Route؛ در فرایندِ
     * ماندگار (آزمون، Octane) `GET` همان مجتمعی را می‌دید که پیش از ساختِ کد خوانده بود و `link: null`
     * می‌داد. کد بارها عوض می‌شود، پس هر بار از پایگاه‌داده.
     */
    private function complex(): Complex
    {
        return Complex::query()->findOrFail($this->requireComplex()->id);
    }

    /** @return array{link: string|null, createdAt: string|null} */
    private function payload(Complex $complex): array
    {
        return [
            'link' => $complex->join_code !== null ? PublicJoinLink::url($complex->join_code) : null,
            'createdAt' => $complex->join_code_created_at?->toIso8601String(),
        ];
    }
}
