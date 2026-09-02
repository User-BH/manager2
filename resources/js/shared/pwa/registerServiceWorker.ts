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
export function registerServiceWorker(url = '/sw.js'): ServiceWorkerHandle | null {
  if (!serviceWorkerSupported()) return null

  let waiting: ServiceWorker | null = null
  let notify: (() => void) | null = null
  let reloading = false

  const announce = (worker: ServiceWorker) => {
    waiting = worker
    notify?.()
  }

  navigator.serviceWorker
    .register(url, { scope: '/' })
    .then((registration) => {
      // نسخه‌ای که همین حالا منتظر است (کاربر تبِ قبلی را نبسته)
      if (registration.waiting && navigator.serviceWorker.controller) {
        announce(registration.waiting)
      }

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
    if (reloading) return
    reloading = true
    window.location.reload()
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
