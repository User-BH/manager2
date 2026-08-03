import { useEffect, useRef } from 'react'

/** همان شناسه‌ای که `layouts/public.blade.php` روی گره‌ی نشان می‌گذارد. */
const SEAL_ID = 'enamad-seal'

/**
 * نشانِ اعتمادِ الکترونیکی (اینماد) در فوتر.
 *
 * ─── چرا این کامپوننت خودش نشان را نمی‌سازد ────────────────────────────────
 * بررسیِ خودکارِ اینماد صفحه را **بدونِ اجرای جاوااسکریپت** می‌خواند. صفحه‌ی
 * فرودِ ما یک island است و کلِ فوتر سمتِ کلاینت رندر می‌شود، پس نشانی که
 * اینجا ساخته شود در HTMLِ خامِ سرور وجود ندارد و در آن بررسی اصلاً دیده
 * نمی‌شود — یعنی تاییدیه نمی‌گیرد. (این را با `curl` روی خروجیِ واقعی
 * سنجیدیم: هیچ نشانی از فوتر در HTMLِ سرور نبود.)
 *
 * پس نشان در Blade رندر می‌شود و این کامپوننت فقط **همان گره را جابه‌جا
 * می‌کند** تا سرِ جای درستش در فوتر بنشیند. نتیجه: یک نسخه روی صفحه، هم
 * برای خزنده و هم برای کاربر.
 *
 * اگر جاوااسکریپت خاموش باشد، گره همان‌جا در پایینِ صفحه می‌ماند — که باز
 * هم فوتر است.
 */
export function TrustSeal() {
  const slotRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const seal = document.getElementById(SEAL_ID)
    const slot = slotRef.current

    if (!seal || !slot || slot.contains(seal)) return

    // padding عمودیِ نسخه‌ی مستقل لازم نیست وقتی داخلِ فوتر می‌نشیند
    seal.classList.remove('py-6')
    slot.appendChild(seal)

    /*
     * با unmount گره به بدنه برمی‌گردد و پاک نمی‌شود: اگر حذفش می‌کردیم،
     * پیمایشِ درون‌برنامه‌ای بین صفحه‌های MPA-island می‌توانست نشان را برای
     * همیشه از صفحه بردارد.
     */
    return () => {
      if (seal.parentElement === slot) {
        seal.classList.add('py-6')
        document.body.appendChild(seal)
      }
    }
  }, [])

  return <div ref={slotRef} className="shrink-0" />
}
