<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complex extends Model
{
    protected $fillable = [
        'name', 'slug', 'address', 'phone', 'currency',
        'messenger_enabled', 'good_payer_enabled', 'good_payer_config',
        'payment_gateway', 'gateway_config',
        'charge_due_day', 'penalty_enabled', 'penalty_type', 'penalty_value', 'penalty_grace_days',
        'fund_balance', 'settings', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'messenger_enabled' => 'boolean',
            'good_payer_enabled' => 'boolean',
            'good_payer_config' => 'array',
            'gateway_config' => 'array',
            'settings' => 'array',
            'penalty_enabled' => 'boolean',
            'penalty_value' => 'decimal:2',
            'fund_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function chargeRules(): HasMany
    {
        return $this->hasMany(ChargeRule::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function currencyLabel(): string
    {
        return $this->currency === 'rial' ? 'ریال' : 'تومان';
    }
}
