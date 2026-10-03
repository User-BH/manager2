<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * یک ردیفِ دفترِ کیفِ پول (R22).
 *
 * ردیف‌ها **فقط اضافه می‌شوند**. هیچ‌جای برنامه این مدل را ویرایش یا حذف
 * نمی‌کند؛ اصلاح با ردیفِ معکوس انجام می‌شود. `WalletLedgerTest` این قاعده
 * را با بازخوانیِ سورس قفل کرده است.
 *
 * @property int $id
 * @property int $complex_id
 * @property int $unit_id
 * @property string $direction
 * @property string $amount
 * @property string $balance_after
 * @property string $source
 * @property int|null $payment_id
 * @property int|null $bill_id
 * @property int|null $created_by
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class WalletTransaction extends Model
{
    /*
     * ⚠️ `Immutable` قاعده‌ی «فقط افزودنی» را از یک **قرارداد** به یک
     * **اجبار** تبدیل می‌کند (R51).
     *
     * پیش از این، تنها محافظ `WalletLedgerTest` بود که سورس را
     * می‌خواند. آن تست جلوی کدِ تازه را می‌گیرد ولی جلوی
     * `$row->update(...)` در یک tinker یا دستورِ موقت را نه. حالا خودِ
     * مدل استثنا می‌دهد.
     */
    use BelongsToComplex, Immutable;

    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    /* ── منبع‌ها ─────────────────────────────────────────────────────────── */

    /** شارژ با رسیدِ کارت‌به‌کارت که مدیر تایید کرده. */
    public const SOURCE_TOPUP_RECEIPT = 'topup_receipt';

    /** شارژ از درگاه بانکی. */
    public const SOURCE_TOPUP_GATEWAY = 'topup_gateway';

    /** پرداختِ قبض از موجودیِ کیف. */
    public const SOURCE_BILL_PAYMENT = 'bill_payment';

    /** اصلاحِ دستیِ مدیر (مثبت یا منفی). */
    public const SOURCE_ADJUSTMENT = 'adjustment';

    /*
     * 🔴 دو منبعِ بی‌برچسب (مرحلهٔ ۷ · فاز ۷).
     *
     * `PaymentService` و `BillGenerator` با رشته‌ی خامِ `'payment'` و
     * `'manual'` اعتبار می‌دادند که در `sourceLabel()` نبودند؛ صورت‌حسابِ
     * کیفِ پول به ساکن واژه‌ی انگلیسیِ «payment» نشان می‌داد. (در عکس‌گرفتن
     * برای مقاله‌ی کیفِ پول دیده شد.) مقدارها همان می‌مانند چون در ردیف‌های
     * موجود ذخیره‌اند؛ فقط نام و برچسب گرفتند.
     */

    /** مازادِ رسیدی که بیش از مانده‌ی قبض‌ها تأیید شد. */
    public const SOURCE_PAYMENT_SURPLUS = 'payment';

    /** قبضِ پرداخت‌شده‌ای که کم شد؛ تفاوت به کیف برمی‌گردد. */
    public const SOURCE_BILL_REDUCTION = 'manual';

    /** همه‌ی منبع‌های شناخته — `WalletLedgerTest` می‌سنجد هر کدام برچسبِ فارسی دارد. */
    public const SOURCES = [
        self::SOURCE_TOPUP_RECEIPT,
        self::SOURCE_TOPUP_GATEWAY,
        self::SOURCE_BILL_PAYMENT,
        self::SOURCE_ADJUSTMENT,
        self::SOURCE_PAYMENT_SURPLUS,
        self::SOURCE_BILL_REDUCTION,
    ];

    protected $fillable = [
        'complex_id', 'unit_id', 'direction', 'amount', 'balance_after',
        'source', 'payment_id', 'bill_id', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isCredit(): bool
    {
        return $this->direction === self::CREDIT;
    }

    /** برچسبِ فارسیِ منبع، برای صورت‌حساب. */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_TOPUP_RECEIPT => 'شارژ با رسید',
            self::SOURCE_TOPUP_GATEWAY => 'شارژ از درگاه',
            self::SOURCE_BILL_PAYMENT => 'پرداخت قبض',
            self::SOURCE_ADJUSTMENT => 'اصلاح توسط مدیر',
            self::SOURCE_PAYMENT_SURPLUS => 'مازاد پرداخت',
            self::SOURCE_BILL_REDUCTION => 'بازگشت از کاهش قبض',
            default => $this->source,
        };
    }
}
