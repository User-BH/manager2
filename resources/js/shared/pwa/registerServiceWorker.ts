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
 * پاک‌کردنِ پاسخ‌های کش‌شده‌ی API هنگامِ خروج از حساب.
 *
 * ⚠️ بدونِ این، قبض و موجودیِ کاربرِ قبلی روی همان دستگاه در کش می‌ماند و
 * کاربرِ بعدی — یا خودِ همان کاربر پس از خروج — می‌تواند در حالتِ آفلاین
 * ببیندش.
 */
export async function clearApiCache(): Promise<void> {
  if (typeof caches === 'undefined') return

  const keys = await caches.keys()

  await Promise.all(
    keys.filter((key) => key.startsWith('sakena-pages-')).map((key) => caches.delete(key)),
  )
}
