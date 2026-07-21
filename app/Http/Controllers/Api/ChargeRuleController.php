<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Models\ChargeRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChargeRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->requireComplex();

        return response()->json([
            'data' => ChargeRule::orderBy('sort_order')->get()
                ->map(fn (ChargeRule $r) => $this->present($r))->values(),

            // هر نوع، فیلدهای متفاوتی لازم دارد؛ کلاینت از همین فهرست
            // تصمیم می‌گیرد کدام ورودی‌ها را نشان دهد.
            'types' => collect(ChargeRuleType::cases())->map(fn (ChargeRuleType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'isPoolBased' => $t->isPoolBased(),
                'fields' => $this->fieldsFor($t),
            ])->values(),

            'categories' => [
                ['value' => 'owner', 'label' => 'مالکانه'],
                ['value' => 'tenant', 'label' => 'مستاجرانه'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireComplex();

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

        $rule = ChargeRule::create([
            'name' => $data['name'],
            'type' => $type,
            'category' => ExpenseCategory::from($data['category']),
            'config' => $config,
            'pool_amount' => $type->isPoolBased() ? ($data['pool_amount'] ?? 0) : null,
            'sort_order' => (ChargeRule::max('sort_order') ?? 0) + 1,
        ]);

        return response()->json([
            'message' => 'قانون شارژ افزوده شد.',
            'rule' => $this->present($rule),
        ], 201);
    }

    public function toggle(ChargeRule $chargeRule): JsonResponse
    {
        $chargeRule->update(['is_active' => ! $chargeRule->is_active]);

        return response()->json(['rule' => $this->present($chargeRule->fresh())]);
    }

    public function destroy(ChargeRule $chargeRule): JsonResponse
    {
        $chargeRule->delete();

        return response()->json(['message' => 'قانون حذف شد.']);
    }

    private function present(ChargeRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'type' => $rule->type->value,
            'typeLabel' => $rule->type->label(),
            'isPoolBased' => $rule->type->isPoolBased(),
            'category' => $rule->category->value,
            'categoryLabel' => $rule->category === ExpenseCategory::Owner ? 'مالکانه' : 'مستاجرانه',
            'config' => $rule->config ?? [],
            'poolAmount' => $rule->pool_amount !== null ? (float) $rule->pool_amount : null,
            'isActive' => (bool) $rule->is_active,
        ];
    }

    /** فیلدهایی که هر نوع قانون واقعاً استفاده می‌کند. */
    private function fieldsFor(ChargeRuleType $type): array
    {
        return match ($type) {
            ChargeRuleType::Fixed,
            ChargeRuleType::PerPerson,
            ChargeRuleType::PerArea => ['amount'],
            ChargeRuleType::Combined => ['base', 'per_area_rate', 'per_person_rate'],
            ChargeRuleType::ElevatorByFloor => ['pool_amount', 'exempt_ground_floor'],
            default => ['pool_amount'],
        };
    }
}
