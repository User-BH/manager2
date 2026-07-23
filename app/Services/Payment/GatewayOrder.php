<?php

namespace App\Services\Payment;

/**
 * چیزی که می‌شود بابتش از بانک پول گرفت.
 *
 * پیش از این درایورهای بانکی مستقیم به مدل `Payment` گره خورده بودند، و
 * چون اشتراک مدل جدایی دارد، برای آن یک مسیر موازی (فقط سندباکس) نوشته
 * شده بود. با این واسط، همان درایورهای ملت و سامان بدون هیچ کدِ تکراری
 * هم شارژ واحد را می‌گیرند و هم اشتراک را.
 */
interface GatewayOrder
{
    /** شناسه‌ای که به بانک به‌عنوان شماره سفارش داده می‌شود. */
    public function gatewayOrderId(): int;

    /** مبلغ به واحد پول سامانه (تبدیل به ریال کار درایور است). */
    public function gatewayAmount(): float;

    /** آدرسی که بانک کاربر را بعد از پرداخت به آن برمی‌گرداند. */
    public function gatewayCallbackUrl(): string;

    /** شماره‌ی پرداخت‌کننده، اگر درگاه بخواهد. */
    public function gatewayPayerPhone(): ?string;

    /** شرحی که روی صورت‌حساب بانکی می‌نشیند. */
    public function gatewayDescription(): string;

    /** شناسه‌ی مرجعِ صادرشده در مرحله‌ی اول (برای مقایسه هنگام بازگشت). */
    public function gatewayRefId(): ?string;

    /** ثبت نام درگاه و شناسه‌ی مرجع پس از موفقیت مرحله‌ی اول. */
    public function markGatewayRequested(string $gateway, string $refId): void;
}
