<?php

namespace App\Models;

use App\Casts\EncryptedJson;
use App\Enums\ComplexType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property ComplexType $type
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $gateway_config
 * @property string|null $join_code
 * @property Carbon|null $join_code_created_at
 * @property list<string>|null $facilities
 * @property Carbon|null $information_completed_at
 * @property string|null $brand_name
 * @property string|null $province
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $description
 * @property string|null $structure
 * @property string|null $common_areas
 * @property string|null $facilities_other
 * @property string|null $board
 * @property string|null $response_hours
 * @property string|null $emergency_info
 */
class Complex extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'type', 'address', 'phone', 'currency',
        'messenger_enabled', 'good_payer_enabled', 'good_payer_config',
        'payment_gateway', 'gateway_config',
        'charge_due_day', 'penalty_enabled', 'penalty_type', 'penalty_value', 'penalty_grace_days',
        'settings', 'is_active',
        // تعلیق (R29) — بدونِ اینها `update()` بی‌صدا نادیده‌شان می‌گیرد
        'suspended_at', 'suspension_reason',
    ];

    /**
     * کدِ QR ِ ورودِ عمومی (مرحلهٔ ۱۰ · فاز ۵) فقط از `GET join-link` به مدیرِ همین مجتمع می‌رسد —
     * نه در تنظیماتِ مجتمع برای هر عضو، نه در فهرستِ ادمینِ کل، نه در پشتیبانِ خروجی. نوشتنش هم فقط
     * از `PublicJoinLink` (عمداً در `$fillable` نیست).
     */
    /**
     * `fund_balance` ستونی مرده است (مرحلهٔ ۱۰ · فاز ۱۳؛ بندِ ۲): هیچ کدی نمی‌نویسدش و موجودیِ صندوق از دفتر حساب
     * می‌شود (`ReportService::fundBalance` = پرداخت‌های موفق + درآمد − هزینه). پنهان است تا هیچ پاسخی عددِ دومی
     * برای «موجودیِ صندوق» نشان ندهد؛ حذفِ ستون برگشتِ دقیقِ مهاجرت را روی SQLite می‌شکست.
     */
    protected $hidden = ['join_code', 'fund_balance'];

    protected function casts(): array
    {
        return [
            'type' => ComplexType::class,
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
            'is_active' => 'boolean',
            'suspended_at' => 'datetime',
            'join_code_created_at' => 'datetime',
            // «اطلاعاتِ مجتمع» (مرحلهٔ ۱۰ · فاز ۷)
            'facilities' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'information_completed_at' => 'datetime',
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

    /**
     * اعضای **فعالِ** مجتمع، از عضویت (مرحلهٔ ۱۰ · فاز ۳) — نه از زمینه‌ی فعالِ حساب‌ها:
     * عضوی که الان در مجتمعِ دیگری کار می‌کند همچنان عضوِ این‌جاست (شمارش، بکاپِ مجتمع).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'complex_memberships')
            ->wherePivot('status', ComplexMembership::ACTIVE)
            ->withPivot('role');
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
