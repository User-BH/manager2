<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Models\ChargeRule;
use Illuminate\Http\Request;

class ChargeRuleController extends Controller
{
    public function index()
    {
        $rules = ChargeRule::orderBy('sort_order')->get();

        return view('admin.charge-rules.index', [
            'rules' => $rules,
            'types' => ChargeRuleType::cases(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:'.implode(',', array_column(ChargeRuleType::cases(), 'value'))],
            'category' => ['required', 'in:owner,tenant'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'base' => ['nullable', 'numeric', 'min:0'],
            'per_area_rate' => ['nullable', 'numeric', 'min:0'],
            'per_person_rate' => ['nullable', 'numeric', 'min:0'],
            'pool_amount' => ['nullable', 'numeric', 'min:0'],
            'exempt_ground_floor' => ['nullable', 'boolean'],
        ], [], ['name' => 'نام قانون', 'type' => 'نوع', 'category' => 'دسته']);

        $type = ChargeRuleType::from($data['type']);

        $config = array_filter([
            'amount' => $data['amount'] ?? null,
            'base' => $data['base'] ?? null,
            'per_area_rate' => $data['per_area_rate'] ?? null,
            'per_person_rate' => $data['per_person_rate'] ?? null,
        ], fn ($v) => $v !== null);

        if ($type === ChargeRuleType::ElevatorByFloor) {
            $config['exempt_ground_floor'] = $request->boolean('exempt_ground_floor');
        }

        ChargeRule::create([
            'name' => $data['name'],
            'type' => $type,
            'category' => ExpenseCategory::from($data['category']),
            'config' => $config,
            'pool_amount' => $type->isPoolBased() ? ($data['pool_amount'] ?? 0) : null,
            'sort_order' => (ChargeRule::max('sort_order') ?? 0) + 1,
        ]);

        return back()->with('success', 'قانون شارژ افزوده شد.');
    }

    public function toggle(ChargeRule $chargeRule)
    {
        $chargeRule->update(['is_active' => ! $chargeRule->is_active]);

        return back()->with('success', 'وضعیت قانون تغییر کرد.');
    }

    public function destroy(ChargeRule $chargeRule)
    {
        $chargeRule->delete();

        return back()->with('success', 'قانون حذف شد.');
    }
}
