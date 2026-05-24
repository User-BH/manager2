<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Income;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->query('period', Jalali::currentPeriod());

        $expenses = Expense::where('period', $period)->latest()->get();
        $incomes = Income::where('period', $period)->latest()->get();

        // Pool-based split methods only (these can be distributed across units).
        $splitMethods = collect(ChargeRuleType::cases())
            ->filter(fn ($t) => $t->isPoolBased())
            ->mapWithKeys(fn ($t) => [$t->value => $t->label()]);

        return view('admin.expenses.index', [
            'period' => $period,
            'expenses' => $expenses,
            'incomes' => $incomes,
            'expenseTotal' => $expenses->sum('amount'),
            'incomeTotal' => $incomes->sum('amount'),
            'splitMethods' => $splitMethods,
        ]);
    }

    public function storeExpense(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0'],
            'category' => ['required', 'in:owner,tenant'],
            'period' => ['required', 'string', 'max:7'],
            'split_method' => ['nullable', 'in:'.implode(',', array_column(ChargeRuleType::cases(), 'value'))],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['title' => 'عنوان', 'amount' => 'مبلغ']);

        Expense::create([
            'title' => $data['title'],
            'amount' => $data['amount'],
            'category' => ExpenseCategory::from($data['category']),
            'period' => $data['period'],
            'spend_date' => now(),
            'split_method' => $data['split_method'] ?? null,
            'is_distributed' => ! empty($data['split_method']),
            'split_config' => ['exempt_ground_floor' => true],
            'description' => $data['description'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'هزینه ثبت شد.');
    }

    public function destroyExpense(Expense $expense)
    {
        $expense->delete();

        return back()->with('success', 'هزینه حذف شد.');
    }

    public function storeIncome(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:120'],
            'period' => ['required', 'string', 'max:7'],
        ], [], ['title' => 'عنوان', 'amount' => 'مبلغ']);

        Income::create([
            'title' => $data['title'],
            'amount' => $data['amount'],
            'source' => $data['source'] ?? null,
            'period' => $data['period'],
            'received_date' => now(),
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'درآمد ثبت شد.');
    }

    public function destroyIncome(Income $income)
    {
        $income->delete();

        return back()->with('success', 'درآمد حذف شد.');
    }
}
