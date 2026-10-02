/**
 * اسکرول نرمِ انیمیشنی به یک بخش از صفحه.
 *
 * چرا دستی و نه `<a href="#id">`: لینکِ لنگری آدرس سایت را به
 * `/#features` عوض می‌کند و در تاریخچه‌ی مرورگر ردیف می‌سازد؛ کاربر با دکمه‌ی
 * بازگشت به‌جای صفحه‌ی قبل، بین بخش‌ها می‌پرد. اینجا فقط اسکرول می‌کنیم و
 * آدرس دست‌نخورده می‌ماند.
 *
 * چرا `scrollIntoView({behavior:'smooth'})` هم نه: منحنی و مدتش دست ما نیست
 * و روی بعضی مرورگرها خیلی تند تمام می‌شود. با انیمیشنِ خودمان، حرکت طولانی‌تر
 * و نرم‌تر است و ارتفاع نوار بالای صفحه هم دقیق کم می‌شود.
 */

/** ارتفاع نوار بالای صفحه‌ی فرود (h-16) که مقصد نباید زیرش پنهان شود. */
const NAVBAR_OFFSET = 72

/** شتاب‌گیری و کاهشِ نرم — تندترین حرکت در وسط مسیر. */
function easeInOutCubic(t: number): number {
  return t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2
}

/** مدت حرکت را با فاصله متناسب می‌کند تا پرش‌های کوتاه کند به نظر نرسند. */
function durationFor(distance: number): number {
  return Math.min(1100, Math.max(450, Math.abs(distance) * 0.6))
}

let activeAnimation: number | null = null

/** اسکرول نرم به یک المان با شناسه‌ی مشخص. */
export function scrollToSection(id: string): void {
  const target = document.getElementById(id)
  if (target) scrollToElement(target)
}

/**
 * اسکرول نرم به یک المان مشخص (وقتی ref داریم نه id).
 *
 * ⚠️ مقصد **در هر فریم دوباره** خوانده می‌شود (مرحلهٔ ۷ · فاز ۵). بخش‌های
 * تنبلِ صفحه‌ی فرود در میانه‌ی همین حرکت بار می‌شوند؛ اگر یکی بالاتر از مقصد
 * کمی بلندتر از جای‌گیرش باشد، مقصدی که لحظه‌ی کلیک حساب شده بود دیگر درست
 * نیست و حرکت چند پیکسل بالا یا پایینِ عنوان می‌ایستاد.
 */
export function scrollToElement(target: Element, offset = NAVBAR_OFFSET): void {
  scrollToPosition(() => window.scrollY + target.getBoundingClientRect().top - offset)
}

/** اسکرول نرم به بالای صفحه. */
export function scrollToTop(): void {
  scrollToPosition(() => 0)
}

/*
 * هر فریم با `behavior: 'instant'` جابه‌جا می‌شویم.
 * اگر جایی در CSS `scroll-behavior: smooth` روشن باشد، فراخوانیِ ساده‌ی
 * scrollTo خودش یک انیمیشنِ مرورگری راه می‌اندازد و با انیمیشنِ ما تداخل
 * می‌کند؛ instant صراحتاً جلوی آن را می‌گیرد.
 */
function jumpTo(top: number): void {
  window.scrollTo({ top, behavior: 'instant' as ScrollBehavior })
}

function scrollToPosition(destination: () => number): void {
  const clamp = (top: number) =>
    Math.max(0, Math.min(top, document.documentElement.scrollHeight - window.innerHeight))

  // اگر کاربر کاهش حرکت را روشن کرده، بدون انیمیشن می‌پریم
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    jumpTo(clamp(destination()))
    return
  }

  if (activeAnimation !== null) {
    window.cancelAnimationFrame(activeAnimation)
  }

  const start = window.scrollY
  const distance = clamp(destination()) - start

  if (Math.abs(distance) < 2) return

  // مدت از فاصله‌ی لحظه‌ی کلیک؛ جابه‌جاییِ مقصد فقط مسیر را اصلاح می‌کند
  const duration = durationFor(distance)
  const startedAt = performance.now()

  function step(now: number) {
    const progress = Math.min(1, (now - startedAt) / duration)
    const end = clamp(destination())
    jumpTo(start + (end - start) * easeInOutCubic(progress))

    if (progress < 1) {
      activeAnimation = window.requestAnimationFrame(step)
    } else {
      activeAnimation = null
    }
  }

  activeAnimation = window.requestAnimationFrame(step)
}
