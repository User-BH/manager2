<?php

namespace App\Models;

use App\Casts\EncryptedJson;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $gateway_config
 */
class Complex extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'address', 'phone', 'currency',
        'messenger_enabled', 'good_payer_enabled', 'good_payer_config',
        'payment_gateway', 'gateway_config',
        'charge_due_day', 'penalty_enabled', 'penalty_type', 'penalty_value', 'penalty_grace_days',
        'fund_balance', 'settings', 'is_active',
        // تعلیق (R29) — بدونِ اینها `update()` بی‌صدا نادیده‌شان می‌گیرد
        'suspended_at', 'suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'messenger_enabled' => 'boolean',
            'good_payer_enabled' => 'boolean',
            'good_payer_config' => 'array',
            /*
             * ⚠️ رمزشده روی دیسک (R55 / رفعِ M-01).
             *
             * این ستون رمز و شناسه‌ی درگاهِ بانکی را نگه می‌دارد. تا پیش
             * از این `'array'` بود، یعنی متنِ ساده — و هر نشتِ دیتابیس
             * مستقیماً به اعتبارنامه‌ی زنده‌ی بانکی تبدیل می‌شد.
             *
             * `EncryptedJson` عمداً **تحمل‌پذیر** است: ردیف‌های سادهٔ
             * قدیمی را هم می‌خواند و همیشه رمزشده می‌نویسد.
             */
            'gateway_config' => EncryptedJson::class,
            'settings' => 'array',
            'penalty_enabled' => 'boolean',
            'penalty_value' => 'decimal:2',
            'fund_balance' => 'decimal:2',
            'is_active' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return HasMany<Building, $this> */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<ChargeRule, $this> */
    public function chargeRules(): HasMany
    {
        return $this->hasMany(ChargeRule::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<Income, $this> */
    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class);
    }

    /** @return HasMany<Bill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Announcement, $this> */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function currencyLabel(): string
    {
        return $this->currency === 'rial' ? 'ریال' : 'تومان';
    }
}
