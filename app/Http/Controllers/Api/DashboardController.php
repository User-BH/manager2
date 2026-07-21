<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Services\ReportService;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * دادهٔ داشبورد برای اپلیکیشن React.
 *
 * محاسبات از همان ReportService موجود می‌آید؛ اینجا فقط به JSON تبدیل
 * می‌شود. اعداد خام (نه فرمت‌شده) برگردانده می‌شوند تا نمودار Recharts
 * بتواند مستقیم مصرفشان کند؛ قالب‌بندی فارسی سمت کلاینت انجام می‌شود.
 */
class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();

        if ($user->isSuperAdmin() && ! $this->currentComplex()) {
            return response()->json(['type' => 'system'] + $this->systemData());
        }

        if ($user->isAdmin()) {
            return response()->json(['type' => 'admin'] + $this->adminData());
        }

        return response()->json(['type' => 'resident'] + $this->residentData());
    }

    private function systemData(): array
    {
        $complexes = Complex::withCount(['units', 'users'])->get();

        return [
            'totalComplexes' => $complexes->count(),
            'totalUnits' => (int) $complexes->sum('units_count'),
            'totalUsers' => (int) $complexes->sum('users_count'),
            'complexes' => $complexes->map(fn (Complex $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'units' => (int) $c->units_count,
                'users' => (int) $c->users_count,
            ])->values(),
        ];
    }

    private function adminData(): array
    {
        $complex = $this->requireComplex();
        $report = new ReportService($complex);
        $period = Jalali::currentPeriod();

        return [
            'period' => $period,
            'periodLabel' => Jalali::periodLabel($period),
            'currency' => $complex->currencyLabel(),
            'income' => (float) $report->monthlyIncome($period),
            'expense' => (float) $report->monthlyExpense($period),
            'balance' => (float) $report->fundBalance(),
            'totalDebt' => (float) $report->totalDebt(),
            'statusCounts' => $report->paymentStatusCounts($period),

            // trend خروجی ReportService سه آرایه‌ی موازی است؛ برای Recharts
            // به آرایه‌ای از رکوردها تبدیل می‌شود تا هر نقطه یک شیء باشد.
            'trend' => $this->flattenTrend($report->trend($period)),

            'debtors' => $report->debtors()->map(fn ($unit) => [
                'id' => $unit->id,
                'label' => 'واحد '.$unit->unit_number,
                'floor' => (int) $unit->floor,
                'balance' => (float) $unit->balance,
            ])->values(),

            'goodPayers' => $report->goodPayers(5)->map(fn ($row) => [
                'id' => $row['unit']->id,
                'label' => 'واحد '.$row['unit']->unit_number,
                'onTime' => (int) ($row['on_time'] ?? 0),
            ])->values(),
        ];
    }

    private function residentData(): array
    {
        $user = Auth::user();
        $units = $user->currentUnits()->with(['bills' => fn ($q) => $q->latest('period')->limit(1)])->get();

        return [
            'unitCount' => $units->count(),
            'totalDebt' => (float) $units->sum('balance'),
            'currency' => $user->complex?->currencyLabel() ?? 'تومان',
            'units' => $units->map(function ($unit) {
                $latest = $unit->bills->first();

                return [
                    'id' => $unit->id,
                    'label' => $unit->label(),
                    'balance' => (float) $unit->balance,
                    'latestBill' => $latest ? [
                        'id' => $latest->id,
                        'periodLabel' => Jalali::periodLabel($latest->period),
                        'total' => (float) $latest->total_amount,
                        'status' => $latest->status->value,
                    ] : null,
                ];
            })->values(),
        ];
    }

    /** @param array{labels: string[], income: float[], expense: float[]} $trend */
    private function flattenTrend(array $trend): array
    {
        return collect($trend['labels'])
            ->map(fn ($label, $i) => [
                'label' => $label,
                'income' => (float) ($trend['income'][$i] ?? 0),
                'expense' => (float) ($trend['expense'][$i] ?? 0),
            ])
            ->values()
            ->all();
    }
}
