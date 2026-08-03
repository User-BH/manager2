<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsCampaign;
use App\Services\Sms\ChargeReminderCampaign;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * یادآوریِ پیامکیِ شارژ — سهمیه‌ی ماهانه‌ی مدیر (R27).
 *
 * قاعده‌ها همه در `ChargeReminderCampaign` هستند و نه اینجا: همان سرویس هم
 * وضعیت را می‌سازد و هم ارسال را بررسی می‌کند، پس دکمه‌ای که در رابط فعال
 * دیده می‌شود حتماً کار می‌کند.
 */
class SmsCampaignController extends Controller
{
    public function __construct(private readonly ChargeReminderCampaign $campaign) {}

    public function show(): JsonResponse
    {
        $complex = $this->requireComplex();
        $this->authorize('sendSmsCampaign', $complex);

        return response()->json($this->campaign->status($complex) + [
            'history' => SmsCampaign::where('complex_id', $complex->id)
                ->with('sender:id,name')
                ->latest('id')
                ->limit(12)
                ->get()
                ->map(fn (SmsCampaign $c) => [
                    'id' => $c->id,
                    'periodLabel' => Jalali::periodLabel($c->period),
                    'recipients' => $c->recipients,
                    'delivered' => $c->delivered,
                    'failed' => $c->failed,
                    'sentBy' => $c->sender?->name,
                    'sentAt' => Jalali::dateTime($c->created_at),
                    'template' => $c->template,
                ])
                ->all(),
        ]);
    }

    public function store(): JsonResponse
    {
        $complex = $this->requireComplex();
        $this->authorize('sendSmsCampaign', $complex);

        $result = $this->campaign->send($complex, Auth::user());

        return response()->json([
            'message' => 'پیامک یادآوری برای '.Jalali::digits((string) $result['delivered'])
                .' واحد ارسال شد.',
        ] + $result + $this->campaign->status($complex->refresh()));
    }
}
