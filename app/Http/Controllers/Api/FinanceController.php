<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\StoreIncomeRequest;
use App\Models\Expense;
use App\Models\Income;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** هزینه‌ها و درآمدهای هر دوره. */
class FinanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $complex = $this->requireComplex();
        $period = $request->query('period', Jalali::currentPeriod());

        $expenses = Expense::where('period', $period)->latest()->get();
        $incomes = Income::where('period', $period)->latest()->get();

        return response()->json([
            'period' => $period,
            'periodLabel' => Jalali::periodLabel($period),
            'periods' => collect(range(-6, 1))
                ->map(fn ($i) => Jalali::shiftPeriod(Jalali::currentPeriod(), $i))
                ->map(fn ($p) => ['value' => $p, 'label' => Jalali::periodLabel($p)])
                ->values(),
            'currency' => $complex->currencyLabel(),
            'expenseTotal' => (float) $expenses->sum('amount'),
            'incomeTotal' => (float) $incomes->sum('amount'),

            'expenses' => $expenses->map(fn (Expense $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'amount' => (float) $e->amount,
                'category' => $e->category->value,
                'categoryLabel' => $e->category === ExpenseCategory::Owner ? 'مالکانه' : 'مستاجرانه',
                // split_method در مدل به enum کست شده، پس نباید دوباره from() شود
                'splitMethod' => $e->split_method?->value,
                'splitLabel' => $e->split_method?->label(),
                'isDistributed' => (bool) $e->is_distributed,
                'description' => $e->description,
                'spendDate' => $e->spend_date ? Jalali::date($e->spend_date) : null,
            ])->values(),

            'incomes' => $incomes->map(fn (Income $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'amount' => (float) $i->amount,
                'source' => $i->source,
                'receivedDate' => $i->received_date ? Jalali::date($i->received_date) : null,
            ])->values(),

            // فقط روش‌های «استخری» قابل تقسیم بین واحدها هستند.
            'splitMethods' => collect(ChargeRuleType::cases())
                ->filter(fn (ChargeRuleType $t) => $t->isPoolBased())
                ->map(fn (ChargeRuleType $t) => ['value' => $t->value, 'label' => $t->label()])
                ->values(),

            'categories' => [
                ['value' => 'owner', 'label' => 'مالکانه'],
                ['value' => 'tenant', 'label' => 'مستاجرانه'],
            ],
        ]);
    }

    public function storeExpense(StoreExpenseRequest $request): JsonResponse
    {
        $this->requireComplex();

        $data = $request->validated();

        Expense::create([
            'title' => $data['title'],
            'amount' => $data['amount'],
            'category' => ExpenseCategory::from($data['category']),
            'period' => $data['period'],
            'spend_date' => now(),
            'split_method' => $data['split_method'] ?: null,
            'is_distributed' => filled($data['split_method'] ?? null),
            'split_config' => ['exempt_ground_floor' => true],
            'description' => $data['description'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'هزینه ثبت شد.'], 201);
    }

    public function destroyExpense(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(['message' => 'هزینه حذف شد.']);
    }

    public function storeIncome(StoreIncomeRequest $request): JsonResponse
    {
        $this->requireComplex();

        $data = $request->validated();

        Income::create([
            'title' => $data['title'],
            'amount' => $data['amount'],
            'source' => $data['source'] ?? null,
            'period' => $data['period'],
            'received_date' => now(),
            'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'درآمد ثبت شد.'], 201);
    }

    public function destroyIncome(Income $income): JsonResponse
    {
        $income->delete();

        return response()->json(['message' => 'درآمد حذف شد.']);
    }
}
