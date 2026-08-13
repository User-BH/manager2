/**
 * Service worker — ساکنا (R35).
 *
 * ─── چرا دست‌نویس و نه Workbox ──────────────────────────────────────────────
 * Workbox همین کار را می‌کند ولی یک وابستگیِ ساخت + حدودِ ۱۵ کیلوبایت
 * زمانِ‌اجرا اضافه می‌کند، و پروژه قیدِ «پکیجِ بی‌دلیل نصب نکن» دارد. آنچه
 * اینجا لازم است چهار استراتژیِ شناخته‌شده و یک نسخه‌بندیِ ساده است — نه
 * چیزی که ارزشِ یک وابستگیِ تازه را داشته باشد.
 *
 * ─── نکته‌ای که پیش از این نسخه غلط بود ────────────────────────────────────
 * نسخه‌ی قبلی برای مسیرهای عادی می‌نوشت:
 *
 *     event.respondWith(fetch(req).catch(() => caches.match(req)))
 *
 * دو اشکال داشت. یک: هیچ‌وقت چیزی در کش **نمی‌نوشت**، پس آن `catch` عملاً
 * همیشه `undefined` برمی‌گرداند. دو: `respondWith(undefined)` خودش خطای
 * شبکه می‌سازد، یعنی کاربرِ آفلاین به‌جای صفحه‌ی آفلاین، خطای خامِ مرورگر
 * می‌دید. مهم‌تر از هر دو: این فایل **هرگز ثبت نمی‌شد**، پس هیچ‌کدامِ
 * این‌ها هیچ‌وقت اجرا نشده بودند.
 */

/**
 * نسخه‌ی کش.
 *
 * ⚠️ با هر تغییرِ این فایل باید بالا برود. کشِ نسخه‌های قبلی در `activate`
 * پاک می‌شود؛ بدون این، کاربری که یک‌بار سایت را باز کرده تا ابد نسخه‌ی
 * قدیمِ دارایی‌ها را می‌گیرد.
 */
const VERSION = 'v3'

const CACHE = {
  shell: `sakena-shell-${VERSION}`,
  assets: `sakena-assets-${VERSION}`,
  images: `sakena-images-${VERSION}`,
  pages: `sakena-pages-${VERSION}`,
}

const OFFLINE_URL = '/offline'

/** حداکثر تعداد ورودی در کش‌هایی که رشدشان مرزی ندارد. */
const LIMIT = { images: 60, pages: 30 }

/**
 * چیزهایی که بدونشان صفحه‌ی آفلاین هم کار نمی‌کند.
 *
 * عمداً کوتاه است: هر چیزی که اینجا بیاید و ۴۰۴ بدهد، کلِ نصبِ SW را
 * شکست می‌دهد و آن‌وقت هیچ‌چیز کش نمی‌شود.
 */
const PRECACHE = [OFFLINE_URL, '/manifest.webmanifest', '/icons/icon-192.png']

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE.shell)
      .then((cache) => cache.addAll(PRECACHE))
      /*
       * ⚠️ اینجا `skipWaiting` صدا زده **نمی‌شود**.
       *
       * اگر نسخه‌ی تازه وسطِ کارِ کاربر جای قبلی را بگیرد، دارایی‌های
       * نیمه‌بارگذاری‌شده‌ی صفحه‌ی باز از نسخه‌ی قبل می‌آیند و صفحه
       * می‌شکند. نسخه‌ی تازه منتظر می‌ماند تا کاربر خودش «به‌روزرسانی» را
       * بزند — همان پیامی که `PwaPrompts` نشان می‌دهد.
       */
      .catch((error) => {
        console.error('[sw] نصب شکست خورد', error)
        throw error
      }),
  )
})

self.addEventListener('activate', (event) => {
  const keep = new Set(Object.values(CACHE))

  event.waitUntil(
    (async () => {
      const keys = await caches.keys()

      await Promise.all(
        keys
          .filter((key) => key.startsWith('sakena-') && !keep.has(key))
          .map((key) => caches.delete(key)),
      )

      // پیمایش را زودتر شروع می‌کند تا اولین درخواستِ صفحه هم از SW رد شود
      if (self.registration.navigationPreload) {
        await self.registration.navigationPreload.enable()
      }

      await self.clients.claim()
    })(),
  )
})

/**
 * پیام از سمتِ صفحه.
 *
 * تنها راهی که نسخه‌ی منتظر فعال می‌شود، و عمداً فقط با خواستِ کاربر.
 */
self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting()
  }
})

/* ─────────────────────────── استراتژی‌ها ─────────────────────────── */

/** فقط پاسخِ سالمِ هم‌مبدأ ارزشِ کش‌شدن دارد. */
function cacheable(response) {
  return response && response.status === 200 && response.type === 'basic'
}

/**
 * نگه‌داشتنِ اندازه‌ی کش.
 *
 * بدون این، کشِ تصاویر با هر آواتار و پیوستِ تازه بی‌مرز رشد می‌کند تا
 * مرورگر کلِ سهمیه را دور بیندازد — یعنی درست همان لحظه‌ای که کاربر آفلاین
 * است، هیچ‌چیز نمانده.
 */
async function trim(cacheName, max) {
  const cache = await caches.open(cacheName)
  const keys = await cache.keys()

  for (const key of keys.slice(0, Math.max(0, keys.length - max))) {
    await cache.delete(key)
  }
}

/** کش اول؛ برای چیزی که با نامش نسخه‌بندی شده و هرگز عوض نمی‌شود. */
async function cacheFirst(request, cacheName, max) {
  const cache = await caches.open(cacheName)
  const hit = await cache.match(request)

  if (hit) return hit

  const response = await fetch(request)

  if (cacheable(response)) {
    await cache.put(request, response.clone())
    if (max) trim(cacheName, max)
  }

  return response
}

/**
 * کشِ فوری + به‌روزرسانی در پس‌زمینه.
 *
 * برای CSS/JS: کاربر بی‌درنگ نسخه‌ی کش‌شده را می‌گیرد و دفعه‌ی بعد نسخه‌ی
 * تازه را. چون نامِ فایل‌ها اثرانگشت دارند، «یک بار عقب‌بودن» خطری ندارد.
 */
async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName)
  const hit = await cache.match(request)

  const fresh = fetch(request)
    .then((response) => {
      if (cacheable(response)) cache.put(request, response.clone())
      return response
    })
    .catch(() => null)

  return hit || (await fresh) || Response.error()
}

/**
 * شبکه اول؛ برای چیزی که کهنه‌بودنش گمراه‌کننده است.
 *
 * قبض و موجودی و پیام باید تازه باشند. کش فقط تورِ ایمنیِ حالتِ آفلاین
 * است، نه منبعِ اول.
 */
async function networkFirst(request, cacheName, max) {
  const cache = await caches.open(cacheName)

  try {
    const response = await fetch(request)

    if (cacheable(response)) {
      await cache.put(request, response.clone())
      if (max) trim(cacheName, max)
    }

    return response
  } catch (error) {
    const hit = await cache.match(request)

    if (hit) return hit

    throw error
  }
}

/* ─────────────────────────── مسیریابی ─────────────────────────── */

const ASSET_EXT = /\.(?:js|mjs|css)$/i
const IMAGE_EXT = /\.(?:png|jpe?g|webp|avif|gif|svg|ico)$/i
const FONT_EXT = /\.(?:woff2?|ttf|otf|eot)$/i

self.addEventListener('fetch', (event) => {
  const request = event.request
  const url = new URL(request.url)

  /*
   * ⚠️ سه چیز عمداً اصلاً دست‌کاری نمی‌شوند:
   *
   * • هر متدی جز GET — نوشتن را نباید کش کرد و نباید دوباره فرستاد.
   * • مبدأ دیگر — پاسخِ opaque نه قابلِ بازرسی است نه قابلِ اعتماد، و
   *   کش‌کردنش سهمیه را با چیزی پر می‌کند که حتی نمی‌دانیم درست بوده.
   * • درخواست‌های `range` — پاسخِ ۲۰۶ در کش، ویدیو را خراب می‌کند.
   */
  if (request.method !== 'GET') return
  if (url.origin !== self.location.origin) return
  if (request.headers.has('range')) return

  /*
   * پیمایشِ صفحه: پیش‌بارگذاری ← شبکه ← کش ← صفحه‌ی آفلاین.
   *
   * ⚠️ ترتیبِ دو تای آخر مهم است و اولین بار غلط نوشتمش.
   *
   * نسخه‌ی اولِ همین بلوک، `await event.preloadResponse` را داخلِ همان
   * `try` داشت که `catch`ش صفحه‌ی آفلاین را برمی‌گرداند. در آزمونِ واقعی —
   * سرور خاموش، صفحه‌ی `/` از قبل در کش — کاربر **صفحه‌ی آفلاین** گرفت،
   * نه صفحه‌ی کش‌شده‌اش. چون وقتی شبکه قطع است `preloadResponse` رد
   * می‌شود و همان رد، کل زنجیره را می‌پراند به `catch` بی‌آنکه اصلاً کش
   * نگاه شود. یعنی گران‌ترین قابلیتِ این مرحله — «صفحه‌های دیده‌شده آفلاین
   * باز می‌شوند» — عملاً کار نمی‌کرد.
   */
  if (request.mode === 'navigate') {
    event.respondWith(
      (async () => {
        const cache = await caches.open(CACHE.pages)

        try {
          const preloaded = await event.preloadResponse

          if (preloaded) {
            if (cacheable(preloaded)) cache.put(request, preloaded.clone())

            return preloaded
          }
        } catch {
          // پیش‌بارگذاری شکست خورد؛ هنوز شبکه و کش مانده‌اند
        }

        try {
          const fresh = await fetch(request)

          if (cacheable(fresh)) {
            cache.put(request, fresh.clone())
            trim(CACHE.pages, LIMIT.pages)
          }

          return fresh
        } catch {
          const cached = await cache.match(request)

          // اگر حتی صفحه‌ی آفلاین هم نبود، خطای خام بهتر از پاسخِ خالی است
          return cached || (await caches.match(OFFLINE_URL)) || Response.error()
        }
      })(),
    )
    return
  }

  /*
   * API: شبکه اول.
   *
   * ⚠️ پاسخِ API عمداً در کشِ **صفحات** نمی‌رود و سقفِ جدا دارد؛ داده‌ی
   * یک کاربر نباید در کشی بماند که بعدِ خروج هم پاک نمی‌شود. پاک‌سازیِ
   * کاملش هنگامِ خروج از حساب انجام می‌شود (`clearApiCache`).
   */
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(networkFirst(request, CACHE.pages, LIMIT.pages))
    return
  }

  if (ASSET_EXT.test(url.pathname)) {
    event.respondWith(staleWhileRevalidate(request, CACHE.assets))
    return
  }

  if (IMAGE_EXT.test(url.pathname) || FONT_EXT.test(url.pathname)) {
    event.respondWith(cacheFirst(request, CACHE.images, LIMIT.images))
    return
  }

  event.respondWith(networkFirst(request, CACHE.pages, LIMIT.pages))
})
