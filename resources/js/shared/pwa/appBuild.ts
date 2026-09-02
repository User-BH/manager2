import { create } from 'zustand'

/**
 * تشخیصِ «این صفحه با نسخهٔ قدیم باز مانده» (R61).
 *
 * ─── 🔴 مسئله: Mixed App Versions ──────────────────────────────────────────
 * ⚠️ کاربری که تب را باز گذاشته، جاوااسکریپتِ دیروز را در حافظه دارد. ما
 * امروز استقرار می‌دهیم. از آن لحظه، آن تب با APIی حرف می‌زند که کدش عوض
 * شده — و هیچ‌کس خبر ندارد.
 *
 * خرابی‌اش معمولاً «خطا» نیست، **رفتارِ اشتباه** است: فیلدی که دیگر نیست
 * `undefined` می‌شود، ستونی خالی می‌ماند، دکمه‌ای کاری نمی‌کند. چون خطایی
 * ثبت نمی‌شود، تنها نشانه‌اش شکایتِ کاربر است.
 *
 * ─── سازوکار ───────────────────────────────────────────────────────────────
 * صفحه با HTML خودش یک `<meta name="app-build">` گرفته. سرور همان مقدار
 * را روی هر پاسخِ API در هدرِ `X-App-Build` می‌گذارد. اگر این دو فرق کنند،
 * یعنی از وقتی این تب باز شده استقرار انجام شده.
 *
 * ⚠️ **بارگذاری دوباره تحمیل نمی‌شود.** کاربری که وسطِ پرکردنِ فرمِ هزینه
 * یا پرداخت است نباید کارش را از دست بدهد — همان استدلالی که در R35
 * جلوی `skipWaiting`ِ خودکار را گرفت.
 */

/** مقدارِ ثابتِ حالتِ توسعه؛ هرگز با خودش فرق نمی‌کند، پس هشدارِ کاذب نمی‌دهد. */
const DEV_BUILD = 'dev'

interface AppBuildState {
  /** نسخه‌ای که سرور اعلام کرده و با نسخهٔ این صفحه فرق دارد. */
  serverBuild: string | null
}

export const useAppBuildStore = create<AppBuildState>(() => ({ serverBuild: null }))

/** شناسهٔ ساختی که این صفحه با آن بارگذاری شده. */
export function pageBuild(): string | null {
  if (typeof document === 'undefined') return null

  return document.querySelector<HTMLMetaElement>('meta[name="app-build"]')?.content ?? null
}

/**
 * با هر پاسخِ API صدا زده می‌شود.
 *
 * ⚠️ سه حالت عمداً نادیده گرفته می‌شوند، چون هرکدامشان هشدارِ کاذب
 * می‌سازند و هشدارِ کاذب سریع‌تر از هر چیزی اعتمادِ کاربر را از بین
 * می‌برد:
 *
 *   ① هدر نیامده — پاسخ از کش یا از مسیری بیرون از API
 *   ② صفحه شناسه ندارد — HTML قدیمی‌تر از این قابلیت
 *   ③ یکی از دو طرف `dev` است — سرورِ توسعه، بی‌ساخت
 */
export function observeServerBuild(headerValue: string | null | undefined): void {
  const server = headerValue?.trim()
  const page = pageBuild()

  if (!server || !page) return
  if (server === DEV_BUILD || page === DEV_BUILD) return
  if (server === page) return

  // ⚠️ یک‌بار می‌نشیند و همان می‌ماند: پاسخ‌های بعدی نباید نوار را چشمک‌زن کنند
  if (useAppBuildStore.getState().serverBuild === server) return

  useAppBuildStore.setState({ serverBuild: server })
}

/** برای تست‌ها. */
export function resetAppBuildState(): void {
  useAppBuildStore.setState({ serverBuild: null })
}
