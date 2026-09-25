/**
 * ثبتِ service worker و تشخیصِ نسخه‌ی تازه (R35).
 *
 * ─── وضعیتِ پیش از این ─────────────────────────────────────────────────────
 * `public/sw.js` از قبل وجود داشت ولی **هیچ‌جا ثبت نمی‌شد**. یعنی نه کشی
 * ساخته می‌شد، نه حالتِ آفلاینی بود، و نه مرورگر اصلاً برنامه را قابلِ نصب
 * می‌دید — چون شرطِ نصب داشتنِ SWِ فعال با کنترل‌کننده‌ی `fetch` است.
 */

/** یگانه‌مقدارِ تشخیص: مرورگر SW را پشتیبانی می‌کند و صفحه امن است؟ */
export function serviceWorkerSupported(): boolean {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
    return false
  }

  /*
   * SW فقط روی مبدأ امن کار می‌کند. `localhost` استثناست تا توسعه ممکن
   * بماند؛ بدونِ این شرط، ثبت روی HTTP خطای کنسول می‌دهد بی‌آنکه کاری
   * از پیش برود.
   */
  return window.isSecureContext || window.location.hostname === 'localhost'
}

export interface ServiceWorkerHandle {
  /** نسخه‌ی تازه آماده است و منتظرِ اجازه‌ی کاربر مانده. */
  onUpdateReady: (callback: () => void) => void
  /** اجازه‌ی کاربر: نسخه‌ی منتظر را فعال کن و صفحه را تازه کن. */
  applyUpdate: () => void
}

/**
 * ⚠️ چرا به‌روزرسانی خودکار اعمال نمی‌شود.
 *
 * `skipWaiting`ِ خودکار وسوسه‌انگیز است ولی صفحه‌ی بازِ کاربر را می‌شکند:
 * نسخه‌ی تازه کنترل را می‌گیرد در حالی که تکه‌های تنبلِ نسخه‌ی قبل هنوز
 * بار نشده‌اند و دیگر در کش نیستند. نتیجه‌اش صفحه‌ی سفید وسطِ کار است.
 */
export function registerServiceWorker(
  url = '/sw.js',
  reload: () => void = () => window.location.reload(),
): ServiceWorkerHandle | null {
  if (!serviceWorkerSupported()) return null

  let waiting: ServiceWorker | null = null
  let notify: (() => void) | null = null
  let reloading = false
  let controlled = Boolean(navigator.serviceWorker.controller)

  const announce = (worker: ServiceWorker) => {
    waiting = worker
    notify?.()
  }

  navigator.serviceWorker
    /*
     * ⚠️ `updateViaCache: 'none'` صریح (R61).
     *
     * پیش‌فرضِ مرورگرهای امروز (`imports`) خودِ فایلِ SW را از کشِ HTTP
     * نمی‌خواند، ولی فایل‌های importشده‌اش را می‌خواند. امروز importی
     * نداریم؛ روزی که داشته باشیم، پیش‌فرض یعنی نسخهٔ تازهٔ SW با
     * تکه‌های **کهنه** بالا بیاید.
     *
     * نوشتنِ صریحش هزینه‌ای ندارد و آن تله را برای همیشه می‌بندد.
     */
    .register(url, { scope: '/', updateViaCache: 'none' })
    .then((registration) => {
      // نسخه‌ای که همین حالا منتظر است (کاربر تبِ قبلی را نبسته)
      if (registration.waiting && navigator.serviceWorker.controller) {
        announce(registration.waiting)
      }

      watchForUpdates(registration)

      registration.addEventListener('updatefound', () => {
        const installing = registration.installing

        if (!installing) return

        installing.addEventListener('statechange', () => {
          /*
           * `controller` تهی یعنی این **اولین** نصب است، نه به‌روزرسانی.
           * بدونِ این شرط، هر کاربرِ تازه‌وارد بلافاصله پیامِ «نسخه‌ی جدید
           * آماده است» می‌گرفت.
           */
          if (installing.state === 'installed' && navigator.serviceWorker.controller) {
            announce(installing)
          }
        })
      })
    })
    .catch((error) => {
      console.error('[pwa] ثبتِ service worker شکست خورد', error)
    })

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    /*
     * 🔴 نصبِ اول هم `controllerchange` می‌دهد (مرحلهٔ ۵ · فاز ۸).
     *
     * `sw.js` در `activate` خودش `clients.claim()` می‌کند، پس صفحه‌ای که
     * تا حالا کنترل‌کننده نداشت یکی پیدا می‌کند. بدونِ این شرط، داشبوردِ
     * هر کاربرِ تازه چند لحظه پس از باز شدن **خودبه‌خود دوباره بار
     * می‌شد** — آزمونِ مرورگر دو بار بارشدنِ `/dashboard` را شمرد — و
     * هرچه کاربر شروع به نوشتن کرده بود از دست می‌رفت.
     *
     * بارگذاریِ دوباره فقط وقتی لازم است که کنترل از نسخه‌ای **به نسخه‌ی
     * دیگر** برود؛ یعنی به‌روزرسانی.
     */
    if (!controlled) {
      controlled = true
      return
    }

    if (reloading) return
    reloading = true
    reload()
  })

  return {
    onUpdateReady(callback) {
      notify = callback
      if (waiting) callback()
    },
    applyUpdate() {
      waiting?.postMessage({ type: 'SKIP_WAITING' })
    },
  }
}

/** فاصلهٔ بررسیِ خودکارِ نسخهٔ تازه. */
export const UPDATE_CHECK_MS = 30 * 60 * 1000

/**
 * پرسیدنِ دوره‌ایِ «نسخهٔ تازه‌ای هست؟» (R61).
 *
 * ─── چرا لازم است ──────────────────────────────────────────────────────────
 * ⚠️ مرورگر خودش فقط هنگامِ **پیمایش** سراغِ `sw.js` می‌رود. در یک SPA
 * پیمایشِ واقعی تقریباً هرگز رخ نمی‌دهد: کاربر تب را باز می‌گذارد و همه‌ی
 * جابه‌جایی‌ها سمتِ کلاینت است.
 *
 * یعنی تبِ بازِ یک کاربر می‌توانست **روزها** روی نسخهٔ قدیم بماند و هیچ
 * سازوکاری خبردار نشود — که دقیقاً همان Mixed App Versions است.
 *
 * ─── دو محرک، عمداً ─────────────────────────────────────────────────────────
 * ① زمان‌سنج — برای تبی که ساعت‌ها باز است
 * ② بازگشتِ دید — برای کاربری که لپ‌تاپ را بسته و فردا باز کرده؛ زمان‌سنجِ
 *   تبِ خواب‌رفته قابل‌اعتماد نیست
 *
 * ⚠️ محرکِ دوم throttle دارد، وگرنه هر بار جابه‌جا شدن بینِ تب‌ها یک
 * درخواستِ شبکه می‌شد.
 */
function watchForUpdates(registration: ServiceWorkerRegistration): void {
  let last = Date.now()

  const check = () => {
    last = Date.now()
    void registration.update().catch(() => {
      // شبکه نیست یا سرور پاسخ نداد؛ دفعهٔ بعد دوباره تلاش می‌شود
    })
  }

  setInterval(check, UPDATE_CHECK_MS)

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return
    if (Date.now() - last < UPDATE_CHECK_MS) return

    check()
  })
}

/**
 * پاک‌کردنِ کش‌هایی که ممکن است پاسخِ مربوط به کاربر داشته باشند.
 *
 * ─── چه چیزی و چرا ─────────────────────────────────────────────────────────
 * پس از R60 هیچ پاسخِ **شخصی**ای اصلاً کش نمی‌شود (`sw.js` مسیرهای خصوصی
 * را `networkOnly` می‌بیند). پس این تابع دیگر خطِ دفاعِ اول نیست — خطِ
 * دومش است، و دو کارِ مشخص می‌کند:
 *
 * ① سطلِ `sakena-api-*` را که پاسخ‌های عمومیِ همان مجتمع را دارد پاک
 *   می‌کند، تا کاربرِ بعدی روی همان دستگاه از صفرِ تازه شروع کند.
 *
 * ② 🔴 سطلِ `sakena-pages-*` را هم پاک می‌کند — و این عمدی و مهم است:
 *   نصب‌هایی که هنوز روی نسخه‌ی قدیمِ service worker مانده‌اند، **داده‌ی
 *   شخصی** در همان سطل دارند (تا پیش از R60 همه‌ی پاسخ‌های API آنجا
 *   می‌نشستند). نسخه‌ی تازه‌ی SW آن را در `activate` پاک می‌کند، ولی
 *   `activate` تا وقتی کاربر به‌روزرسانی را نپذیرد اجرا نمی‌شود.
 *
 *   پس خروج از حساب، نشتِ قدیمی را همان لحظه می‌بندد — بی‌آنکه منتظرِ
 *   به‌روزرسانی بماند.
 *
 * ⚠️ دارایی‌ها (`assets`/`images`) عمداً دست‌نخورده می‌مانند: چیزی از کاربر
 * در آن‌ها نیست و پاک‌کردنشان فقط یعنی ورودِ بعدی کُند باشد.
 */
export async function clearApiCache(): Promise<void> {
  if (typeof caches === 'undefined') return

  const keys = await caches.keys()

  await Promise.all(
    keys
      .filter((key) => key.startsWith('sakena-api-') || key.startsWith('sakena-pages-'))
      .map((key) => caches.delete(key)),
  )
}

/**
 * خاموش‌کردنِ کاملِ service worker از راه دور (R61).
 *
 * ─── 🔴 مسئله: بازگشت برای خودِ SW ─────────────────────────────────────────
 * ⚠️ استقرارِ خرابِ بک‌اند را با یک `git revert` برمی‌گردانیم. ولی service
 * workerِ خراب روی **دستگاهِ کاربر** نصب شده و کنترلِ همه‌ی درخواست‌هایش
 * را دارد. اگر آن نسخه پوسته‌ی شکسته‌ای را کش کرده باشد، کاربر ممکن است
 * حتی نتواند صفحه‌ای بگیرد که به او بگوید چه شده.
 *
 * ─── راهِ نجات ─────────────────────────────────────────────────────────────
 * پرچمِ `pwa` در تنظیماتِ سامانه خاموش می‌شود. کلاینت — که این پرچم را
 * از `/features` می‌گیرد — SW را حذف می‌کند، همه‌ی کش‌ها را پاک می‌کند و
 * یک بار صفحه را تازه می‌کند. از آن پس همه‌چیز مستقیم از شبکه می‌آید.
 *
 * ⚠️ **مرزِ صادقانه‌اش:** این راه به بالا آمدنِ صفحه وابسته است. اگر SWی
 * منتشر شود که خودِ HTML را هم بشکند، این کار نمی‌کند و باید رویهٔ دستیِ
 * `docs/API_COMPATIBILITY.md` را اجرا کرد. به همین دلیل `sw.js` عمداً
 * ساده مانده و `install`ش فقط سه فایلِ کوچک را precache می‌کند.
 */
export async function unregisterServiceWorker(): Promise<void> {
  if (!serviceWorkerSupported()) return

  const registrations = await navigator.serviceWorker.getRegistrations()

  await Promise.all(registrations.map((registration) => registration.unregister()))

  if (typeof caches !== 'undefined') {
    const keys = await caches.keys()

    await Promise.all(
      keys.filter((key) => key.startsWith('sakena-')).map((key) => caches.delete(key)),
    )
  }
}
