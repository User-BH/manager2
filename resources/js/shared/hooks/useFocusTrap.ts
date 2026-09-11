import { useEffect, type RefObject } from 'react'

/**
 * نگه‌داشتنِ Focus داخلِ یک ظرف، و برگرداندنش پس از بسته‌شدن (بندهای ۳۴ و ۳۵).
 *
 * ─── 🔴 چه چیزی باز بود ────────────────────────────────────────────────────
 * `Modal` مشترک `role="dialog"` و `aria-modal` و Escape داشت — ولی هیچ
 * مدیریتِ Focusی نداشت:
 *
 *   • Focus هنگام بازشدن روی دکمه‌ای می‌ماند که **پشتِ** روکش بود
 *   • Tab از مودال بیرون می‌رفت و صفحهٔ زیرش را می‌پیمود
 *   • پس از بستن، Focus به هیچ‌جا برنمی‌گشت
 *
 * ⚠️ برای کاربرِ ماوس هیچ‌کدام دیده نمی‌شود. برای کاربرِ کیبورد و
 * صفحه‌خوان یعنی مودالی که «باز شده» ولی نمی‌شود واردش شد.
 *
 * ─── چرا بدونِ پکیج ────────────────────────────────────────────────────────
 * `focus-trap-react` و مانندش نصب نیستند و قاعده «بی‌خودی پکیج نصب نکن»
 * است. کارِ لازم سه چیز است و هر سه در چند خط جا می‌شوند.
 *
 * ─── مرزِ صریح ─────────────────────────────────────────────────────────────
 * ⚠️ فقط Tab را می‌گیرد. کلیکِ ماوس روی بیرونِ مودال کارِ روکش است
 * (`onClick={onClose}`)، نه این هوک.
 */

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

export function useFocusTrap(container: RefObject<HTMLElement | null>, active: boolean): void {
  useEffect(() => {
    if (!active) return

    const root = container.current
    if (!root) return

    // ⚠️ `trap` یک function declaration است و hoist می‌شود؛ TypeScript نمی‌تواند
    // باریک‌سازیِ `root` را به داخلش ببرد. نامِ تازه با نوعِ قطعی این را حل می‌کند.
    const panel: HTMLElement = root

    /*
     * ⚠️ چیزی که پیش از بازشدن Focus داشت، ذخیره می‌شود تا بعداً برگردد.
     *
     * 🔴 بدونِ این، پس از بستنِ مودال Focus به `<body>` می‌افتد و کاربرِ
     * کیبورد باید از اولِ صفحه دوباره راه بیفتد.
     */
    const previouslyFocused = document.activeElement as HTMLElement | null

    /*
     * ⚠️ پنهان‌بودن با **صفت** سنجیده می‌شود، نه با `offsetParent`.
     *
     * 🔴 نسخهٔ اول `offsetParent !== null` را می‌سنجید و در jsdom — که چیدمان
     * ندارد — همیشه `null` بود؛ فهرست به یک عنصر فرومی‌ریخت و تستِ چرخش
     * قرمز می‌شد. ولی مسئله فقط تست نبود: عنصری که با `display:none`ِ
     * والدِ دور پنهان شده هم `offsetParent` ندارد، پس در مرورگر هم فیلتر
     * بیش از حد سخت‌گیر بود.
     */
    const focusables = (): HTMLElement[] =>
      Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
        (el) => !el.hidden && el.getAttribute('aria-hidden') !== 'true',
      )

    // اولین عنصرِ قابلِ Focus، وگرنه خودِ ظرف
    const first = focusables()[0]
    if (first) first.focus()
    else {
      // ⚠️ `setAttribute` و نه انتسابِ ویژگی؛ لینترِ کامپایلرِ React انتساب روی
      // مقدارِ مشتق از ref را «تغییرِ مقدارِ ثابت» می‌بیند
      panel.setAttribute('tabindex', '-1')
      panel.focus()
    }

    function trap(event: KeyboardEvent) {
      if (event.key !== 'Tab') return

      const items = focusables()
      if (items.length === 0) {
        event.preventDefault()
        return
      }

      const firstItem = items[0]
      const lastItem = items[items.length - 1]
      const current = document.activeElement

      if (event.shiftKey && (current === firstItem || !panel.contains(current))) {
        event.preventDefault()
        lastItem?.focus()
      } else if (!event.shiftKey && (current === lastItem || !panel.contains(current))) {
        event.preventDefault()
        firstItem?.focus()
      }
    }

    document.addEventListener('keydown', trap)

    return () => {
      document.removeEventListener('keydown', trap)

      // ⚠️ فقط اگر آن عنصر هنوز در سند است؛ وگرنه focus() بی‌اثر و بی‌خطر است
      if (previouslyFocused && document.contains(previouslyFocused)) previouslyFocused.focus()
    }
  }, [container, active])
}
