<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class GoodPayerController extends Controller
{
    public function index(): JsonResponse
    {
        $complex = $this->currentComplex();

        if (! $complex) {
            return response()->json([
                'enabled' => false,
                'data' => [],
                'reason' => 'ابتدا یک مجتمع را انتخاب کنید.',
            ]);
        }

        if (! $complex->good_payer_enabled) {
            return response()->json([
                'enabled' => false,
                'data' => [],
                'reason' => 'نمایش ساکنین خوش‌حساب برای این مجتمع غیرفعال است.',
            ]);
        }

        $payers = (new ReportService($complex))->goodPayers(20);

        return response()->json([
            'enabled' => true,
            'reason' => null,
            'currency' => $complex->currencyLabel(),
            'data' => $payers->map(fn ($row) => [
                'id' => $row['unit']->id,
                'label' => 'واحد '.$row['unit']->unit_number,
                'floor' => (int) $row['unit']->floor,
                'onTime' => (int) $row['on_time'],
                'totalPaid' => (float) $row['total_paid'],
                'tier' => $row['tier'],
            ])->values(),
        ]);
    }
}
