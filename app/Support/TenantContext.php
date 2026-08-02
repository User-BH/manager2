<?php

namespace App\Support;

/**
 * مجتمعِ فعالِ این درخواست (یا اجرای کنسول).
 *
 * ─── چرا «تهی» کافی نبود ───────────────────────────────────────────────────
 * تا R21 فقط دو حالت وجود داشت: شناسه‌ی مجتمع، یا `null` که یعنی «فیلتری
 * نگذار». همان `null` برای دو وضعیتِ کاملاً متفاوت به کار می‌رفت:
 *
 *   ۱. ادمینِ کل که هنوز مجتمعی انتخاب نکرده → باید همه را ببیند
 *   ۲. کاربری که به هیچ مجتمعی وصل نیست     → باید **هیچ‌چیز** نبیند
 *
 * تا وقتی حالتِ دوم اصلاً نمی‌توانست وارد شود (ثبت‌نام کاربر را غیرفعال
 * می‌ساخت) این ابهام بی‌خطر بود. R21 دقیقاً همان در را باز می‌کند، و سنجیده
 * شد نه فرض: بدونِ این تفکیک، کاربرِ تازه ثبت‌نام‌کرده **قبض و اطلاعیه‌ی
 * همه‌ی مجتمع‌ها** را می‌دید.
 *
 * پس حالا «هیچ‌چیز» صریح است، نه مشتق از تهی بودن.
 */
class TenantContext
{
    private ?int $complexId;

    /** آیا این درخواست باید از همه‌ی داده‌ی مستأجرها محروم باشد؟ */
    private bool $deniesAll = false;

    public function __construct(?int $complexId = null)
    {
        $this->complexId = $complexId;
    }

    public function set(?int $complexId): void
    {
        $this->complexId = $complexId;
        $this->deniesAll = false;
    }

    /**
     * «این کاربر به هیچ مجتمعی وصل نیست.»
     *
     * با این، `ComplexScope` به‌جای برداشتنِ فیلتر، همه‌چیز را می‌بندد.
     */
    public function denyAll(): void
    {
        $this->complexId = null;
        $this->deniesAll = true;
    }

    public function get(): ?int
    {
        return $this->complexId;
    }

    public function deniesAll(): bool
    {
        return $this->deniesAll;
    }
}
