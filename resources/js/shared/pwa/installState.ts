/**
 * «ساکنا روی این دستگاه نصب است؟» — و آیا پیشنهادِ نصب را نشان بدهیم
 * (مرحلهٔ ۸ · فاز ۲، بندِ ۱ ِ «بررسی جدید»).
 *
 * ─── چه چیزی واقعاً قابلِ دانستن است ───────────────────────────────────────
 * مرورگرها برای این پرسش API ِ یکسانی ندارند. این‌جا فقط سیگنال‌های واقعی:
 *
 *   ① **داخلِ خودِ اپ** (همه‌جا قطعی): `display-mode` ِ standalone،
 *     minimal-ui یا window-controls-overlay — همان حالت‌های `display_override`
 *     ِ manifest — یا `navigator.standalone` در iOS.
 *     ⚠️ `fullscreen` عمداً نه: کروم در F11 ِ یک تبِ معمولی هم آن را می‌گوید.
 *
 *   ② **کروم، اج، سامسونگ (اندروید، ویندوز، مک، لینوکس، کروم‌اواس)**: وقتی
 *     ساکنا نصب است `beforeinstallprompt` اصلاً نمی‌آید؛ وقتی می‌آید، یعنی
 *     نصب نیست. پس دکمه‌ی «نصب» فقط با همین رویداد ظاهر می‌شود — قطعی.
 *
 *   ③ **سافاریِ iOS و مک، فایرفاکسِ اندروید**: رویدادی ندارند و نصب از منوی
 *     خودِ مرورگر است. این‌جا قطعیت ممکن نیست:
 *       - iOS و مک: اپِ افزوده‌شده انبارهٔ جدای خودش را دارد؛ سافاری حتی
 *         نمی‌تواند بفهمد کاربر آن را افزوده.
 *       - فایرفاکسِ اندروید: اپ و مرورگر یک انباره دارند، پس اگر اپ یک بار
 *         باز شده باشد (`installedSeen`) این‌جا می‌دانیم.
 *     پس **راهنما** نشان داده می‌شود نه دکمه، با متنِ مشروط («اگر هنوز نصب
 *     نکرده‌اید…») و با فاصله‌ی طولانی‌تر بینِ دو نمایش.
 *
 *   فایرفاکسِ دسکتاپ و مرورگرهای درون‌برنامه‌ای (اینستاگرام، تلگرام) نصبِ
 *   PWA ندارند: هیچ‌چیز نشان داده نمی‌شود.
 *
 * ─── تکرار ────────────────────────────────────────────────────────────────
 * بستن همیشگی است (همان کلیدِ فاز ۵ِ مرحلهٔ ۷). بی‌بستن هم کارت با هر
 * بارگذاری برنمی‌گردد: پس از هر بار دیده‌شدن، دکمه‌ی نصب تا ۳ روز و راهنما
 * تا ۱۴ روز دوباره نمی‌آید.
 *
 * `getInstalledRelatedApps` عمداً به کار نرفت: فقط در کرومیوم است، همان‌جا
 * که ② از قبل قطعی است، و نشانیِ مطلقِ manifest را در `related_applications`
 * می‌خواهد که به دامنه‌ی استقرار گره می‌خورد.
 */

export type InstallGuide = 'ios' | 'macos-safari' | 'firefox-android'
export type OfferKind = 'native' | InstallGuide

/** کلیدِ «نه، ممنون» — از مرحلهٔ ۷ · فاز ۵؛ عوضش نکنید، بستن‌های قبلی گم می‌شوند */
export const DISMISSED_KEY = 'sakena:install-dismissed'
/** `{ installedSeen?: true, lastShownAt?: number }` */
export const STATE_KEY = 'sakena:install-state'

const DAY = 24 * 60 * 60 * 1000
export const COOLDOWN: Record<'native' | 'guide', number> = { native: 3 * DAY, guide: 14 * DAY }

interface StoredState {
  installedSeen?: boolean
  lastShownAt?: number
}

/** ⚠️ `localStorage` در حالتِ خصوصیِ سافاری یا با کوکیِ مسدود پرتاب می‌کند. */
function read(): StoredState {
  try {
    const raw = localStorage.getItem(STATE_KEY)
    const parsed: unknown = raw ? JSON.parse(raw) : null
    if (!parsed || typeof parsed !== 'object') return {}
    const { installedSeen, lastShownAt } = parsed as Record<string, unknown>
    return {
      installedSeen: installedSeen === true ? true : undefined,
      lastShownAt: typeof lastShownAt === 'number' ? lastShownAt : undefined,
    }
  } catch {
    return {}
  }
}

function write(patch: StoredState): void {
  try {
    localStorage.setItem(STATE_KEY, JSON.stringify({ ...read(), ...patch }))
  } catch {
    // حالتِ خصوصی: فقط برای همین صفحه
  }
}

/** ① همین حالا داخلِ اپِ نصب‌شده اجرا می‌شود؟ */
export function runningInstalled(): boolean {
  if (typeof window === 'undefined') return false

  const media = (query: string) => window.matchMedia?.(query).matches ?? false
  const app =
    media('(display-mode: standalone)') ||
    media('(display-mode: minimal-ui)') ||
    media('(display-mode: window-controls-overlay)')
  const iosStandalone = (window.navigator as { standalone?: boolean }).standalone === true

  return app || iosStandalone
}

/** ③ مرورگری که نصب دارد ولی رویدادش را نه — کدام راهنما؟ */
export function installGuide(): InstallGuide | null {
  if (typeof window === 'undefined') return null

  const nav = window.navigator as Navigator & { standalone?: boolean }
  const ua = nav.userAgent
  const touch = nav.maxTouchPoints > 1

  /*
   * iOS: خودِ `navigator.standalone` نشانه است — فقط در WebKitی هست که
   * «افزودن به صفحه‌ی اصلی» دارد و بیرون از اپ `false` است؛ مرورگرهای
   * درون‌برنامه‌ای آن را ندارند. آی‌پدِ امروزی خودش را «Macintosh» می‌نامد.
   */
  const iOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && touch)
  if (iOS) return nav.standalone === false ? 'ios' : null

  /*
   * سافاریِ مک ‎۱۷+ «Add to Dock» دارد. کروم، اج و اپرا روی مک هم «Safari/» در
   * UA دارند ولی «Version/N» نه — آن نشانه‌ی خودِ سافاری است؛ فایرفاکس هیچ‌کدام.
   * (فهرستِ جداییِ «نه کروم، نه اج» این‌جا بود؛ خرابکاری نشان داد هیچ UA ِ
   * واقعی‌ای به آن نمی‌رسد و برداشته شد.)
   */
  const safariVersion = /Version\/(\d+)[\d.]* Safari\//.exec(ua)
  if (/Macintosh/.test(ua) && safariVersion && Number(safariVersion[1]) >= 17) {
    return 'macos-safari'
  }

  if (/Android/.test(ua) && /Firefox\//.test(ua)) return 'firefox-android'

  return null
}

export function isDismissed(): boolean {
  try {
    return localStorage.getItem(DISMISSED_KEY) === '1'
  } catch {
    return false
  }
}

export function dismissForever(): void {
  try {
    localStorage.setItem(DISMISSED_KEY, '1')
  } catch {
    // حالتِ خصوصی: فقط برای همین صفحه بسته می‌ماند
  }
}

/** اپ روی همین مرورگر/انباره باز شده یا نصبش همین‌جا تمام شده */
export function markInstalled(): void {
  write({ installedSeen: true })
}

/**
 * ② کروم گفت «نصب نیست» (رویداد آمد): نشانه‌ی قبلی کهنه است — مثلاً اپ
 * حذف شده. بی‌این، کسی که یک بار نصب و بعد حذف کرده بود دیگر هرگز پیشنهاد
 * نمی‌گرفت.
 */
export function markNotInstalled(): void {
  write({ installedSeen: undefined })
}

export function markShown(now = Date.now()): void {
  write({ lastShownAt: now })
}

/** آیا این نوع پیشنهاد همین حالا مجاز است؟ */
export function mayOffer(kind: OfferKind, now = Date.now()): boolean {
  if (runningInstalled() || isDismissed()) return false

  const state = read()
  // راهنما سیگنالی از مرورگر ندارد؛ اگر اپ این‌جا دیده شده، دیگر لازم نیست
  if (kind !== 'native' && state.installedSeen) return false

  const cooldown = kind === 'native' ? COOLDOWN.native : COOLDOWN.guide
  if (state.lastShownAt !== undefined && now - state.lastShownAt < cooldown) return false

  return true
}
