<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementAudience;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Complex;
use App\Services\ReportService;
use App\Support\Jalali;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if ($user->isSuperAdmin() && ! $this->currentComplex()) {
            return $this->systemDashboard();
        }

        if ($user->isAdmin()) {
            return $this->adminDashboard();
        }

        return $this->residentDashboard();
    }

    protected function systemDashboard()
    {
        $complexes = Complex::withCount(['units', 'users'])->get();

        return view('dashboard.system', [
            'complexes' => $complexes,
            'totalUnits' => $complexes->sum('units_count'),
            'totalUsers' => $complexes->sum('users_count'),
        ]);
    }

    protected function adminDashboard()
    {
        $complex = $this->requireComplex();
        $report = new ReportService($complex);
        $period = Jalali::currentPeriod();

        return view('dashboard.admin', [
            'complex' => $complex,
            'period' => $period,
            'monthlyIncome' => $report->monthlyIncome($period),
            'monthlyExpense' => $report->monthlyExpense($period),
            'fundBalance' => $report->fundBalance(),
            'totalDebt' => $report->totalDebt(),
            'debtors' => $report->debtors(),
            'goodPayers' => $report->goodPayers(5),
            'trend' => $report->trend($period),
            'statusCounts' => $report->paymentStatusCounts($period),
        ]);
    }

    protected function residentDashboard()
    {
        $user = Auth::user();
        $units = $user->currentUnits()->with(['bills' => fn ($q) => $q->latest('period')])->get();

        $audiences = [AnnouncementAudience::All];
        $audiences[] = $user->role === UserRole::Owner ? AnnouncementAudience::Owners : AnnouncementAudience::Tenants;

        $announcements = Announcement::where('is_active', true)
            ->whereIn('audience', $audiences)
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(5)
            ->get();

        $bills = $units->flatMap->bills;

        return view('dashboard.resident', [
            'units' => $units,
            'announcements' => $announcements,
            'totalDebt' => $bills->whereIn('status', [\App\Enums\BillStatus::Unpaid, \App\Enums\BillStatus::Partial])
                ->sum(fn ($b) => $b->remaining()),
            'currentBills' => $bills->where('period', Jalali::currentPeriod()),
        ]);
    }
}
