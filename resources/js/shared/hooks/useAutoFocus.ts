import { useEffect, useRef } from 'react'

/**
 * فوکوسِ خودکار روی اولین ورودیِ فرم (R37 · فنی-۸۳).
 *
 * ─── چرا `autofocus`ِ خودِ HTML به کار نیامد ───────────────────────────────
 * صفت `autofocus` فقط هنگامِ **تجزیه‌ی سندِ اولیه** اثر دارد. این پروژه
 * فرم‌هایش را با React می‌سازد و بیشترشان (مودالِ افزودن، تبِ ثبت‌نام،
 * گامِ کدِ یک‌بارمصرف) بعد از بارگذاری وارد صفحه می‌شوند؛ آنجا مرورگر آن
 * صفت را نادیده می‌گیرد.
 *
 * ─── دو استثنایی که عمدی‌اند ────────────────────────────────────────────────
 * ⚠️ **موبایل:** فوکوسِ خودکار روی صفحه‌ی لمسی صفحه‌کلید را بالا می‌آورد و
 * نصفِ صفحه را می‌پوشاند — کاربر هنوز عنوانِ فرم را نخوانده و باید اول
 * صفحه‌کلید را ببندد. روی این دستگاه‌ها فوکوس داده نمی‌شود.
 *
 * ⚠️ **`prefers-reduced-motion`:** بعضی کاربران این را برای کاهشِ حرکت و
 * پرشِ ناگهانیِ صفحه روشن می‌کنند. پرشِ اسکرول به‌سمتِ فیلدِ فوکوس‌شده
 * دقیقاً همان چیزی است که نمی‌خواهند، پس فوکوس بدونِ اسکرول انجام می‌شود.
 */

/** آیا دستگاه اصولاً صفحه‌کلیدِ سخت‌افزاری دارد؟ */
function hasPhysicalKeyboard(): boolean {
  if (typeof window === 'undefined' || !window.matchMedia) return false

  /*
   * `pointer: fine` یعنی نشانگرِ دقیق (ماوس/ترک‌پد). تشخیص با عرضِ صفحه
   * اشتباه است: تبلتِ بزرگ هم لمسی است و لپ‌تاپِ کوچک هم صفحه‌کلید دارد.
   */
  return window.matchMedia('(pointer: fine)').matches
}

/**
 * ⚠️ چرا ref روی **ظرف** است و نه روی خودِ ورودی.
 *
 * فرم‌های این پروژه از `Controller`ِ react-hook-form رد می‌شوند و آنجا ref
 * برای خودِ کتابخانه است؛ رساندنِ یک ref دوم به اولین فیلد یعنی تغییرِ
 * امضای `RestrictedField` و هر چیزی که رویش سوار است. با ref روی فرم،
 * اولین ورودیِ واقعی همان‌جا پیدا می‌شود و هیچ کامپوننتی عوض نمی‌شود.
 */
/*
 * ⚠️ استثناها باید روی **هر** انتخابگرِ فهرست بیایند، نه فقط آخری.
 *
 * اول نوشته بودم `` `${FOCUSABLE}:not([type="checkbox"])` `` و چون
 * `FOCUSABLE` سه انتخابگرِ کاما-جدا بود، آن `:not` فقط به `textarea`
 * چسبید و چک‌باکس همچنان اولین فوکوس می‌شد — تست گرفتش.
 *
 * ورودی‌هایی که «اولین فوکوس» بودنشان بی‌معناست: چک‌باکس و رادیو (تایپ
 * جایی نمی‌رود)، و دکمه‌های ارسال/فایل.
 */
const SKIP_TYPES = ['hidden', 'checkbox', 'radio', 'submit', 'reset', 'button', 'file']

const FOCUSABLE = [
  `input${SKIP_TYPES.map((type) => `:not([type="${type}"])`).join('')}:not([disabled])`,
  'select:not([disabled])',
  'textarea:not([disabled])',
].join(', ')

export function useAutoFocus<T extends HTMLElement>(enabled = true) {
  const ref = useRef<T>(null)

  useEffect(() => {
    if (!enabled) return

    const container = ref.current

    if (!container || !hasPhysicalKeyboard()) return

    const target = container.querySelector<HTMLElement>(FOCUSABLE)

    if (!target) return

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches

    target.focus({ preventScroll: reduced })
  }, [enabled])

  return ref
}
