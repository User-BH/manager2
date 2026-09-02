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
const VERSION = 'v4'

/**
 * ⚠️ بالا رفتنِ نسخه در R60 فقط نظافت نیست — **پاک‌کردنِ نشت است**.
 *
 * نصب‌های موجود پاسخ‌های شخصیِ API را در `sakena-pages-v3` دارند (شرحش
 * پایین‌تر). `activate` هر کشی را که در `CACHE` نباشد پاک می‌کند، پس همین
 * یک عدد باعث می‌شود آن داده با اولین فعال‌شدنِ نسخه‌ی تازه برود.
 */
const CACHE = {
  shell: `sakena-shell-${VERSION}`,
  assets: `sakena-assets-${VERSION}`,
  images: `sakena-images-${VERSION}`,
  pages: `sakena-pages-${VERSION}`,
  /*
   * ⚠️ سطلِ جدا برای APIِ **عمومی**.
   *
   * پیش از این پاسخ‌های API در همان `pages` می‌نشستند — با اینکه کامنتِ
   * بالای همان خط ادعا می‌کرد نمی‌نشینند. نتیجه‌اش دو خرابی بود: داده‌ی
   * شخصی کنارِ صفحه‌ها، و سهمیه‌ی سی‌تایی که این دو از هم می‌دزدیدند
   * (سی درخواستِ API یعنی همه‌ی صفحه‌های آفلاین بیرون انداخته شدند).
   */
  api: `sakena-api-${VERSION}`,
}

const OFFLINE_URL = '/offline'

/** حداکثر تعداد ورودی در کش‌هایی که رشدشان مرزی ندارد. */
const LIMIT = { images: 60, pages: 30, api: 20 }

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

/**
 * فقط پاسخِ سالمِ هم‌مبدأ که **خودِ سرور اجازه‌اش را داده** ارزشِ کش‌شدن دارد.
 *
 * ─── دو شرطِ تازه‌ی R60 ─────────────────────────────────────────────────────
 * ⚠️ این‌ها لایه‌ی دومِ همان تصمیم‌اند. جدولِ `POLICY` پایین‌تر می‌گوید چه
 * چیزی *باید* کش شود؛ این تابع می‌گوید چه چیزی *مجاز* است. اگر روزی آن
 * جدول اشتباه شود، این شرط‌ها همچنان جلوی نشت را می‌گیرند.
 *
 * • `no-store` — سرور صریح گفته «ننویس» (میدل‌ورِ `ApiCacheHeaders`)
 * • `Vary: Cookie` — پاسخ به اینکه **چه کسی** پرسیده بستگی دارد، پس
 *   نوشتنش یعنی جوابِ یک نفر به نفرِ بعدی داده شود
 *
 * محافظتی که فقط یک لایه دارد، محافظت نیست؛ یک فرض است.
 */
function cacheable(response) {
  if (!response || response.status !== 200 || response.type !== 'basic') return false

  const control = response.headers.get('cache-control') || ''
  if (control.includes('no-store')) return false

  const vary = response.headers.get('vary') || ''
  if (/\bcookie\b/i.test(vary)) return false

  return true
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

/* ─────────────────────────── سیاستِ کش ─────────────────────────── */

const ASSET_EXT = /\.(?:js|mjs|css)$/i
const IMAGE_EXT = /\.(?:png|jpe?g|webp|avif|gif|svg|ico)$/i
const FONT_EXT = /\.(?:woff2?|ttf|otf|eot)$/i

/**
 * مسیرهای APIی که پاسخشان **برای همه یکسان** است.
 *
 * ⚠️ باید با `config('pwa.public_api')` یکی بماند؛ `CachePolicyTest` این
 * را قفل کرده. شرطِ عضویت یکی است: اگر پاسخ به اینکه چه کسی پرسیده بستگی
 * دارد، اینجا جایش نیست.
 *
 * ⚠️ `me` و `csrf-token` عمداً نیستند، هرچند احراز هویت نمی‌خواهند: اولی
 * می‌گوید «تو کیستی» و دومی توکنِ امنیتیِ زمان‌دار است.
 */
const PUBLIC_API = ['features', 'site-settings', 'ads', 'support/starters']

/** آیا این دقیقاً یکی از مسیرهای عمومی است؟ (هر دو پیشوند) */
function isPublicApi(pathname) {
  return PUBLIC_API.some((path) => pathname === `/api/${path}` || pathname === `/api/v1/${path}`)
}

/**
 * 🔴 جدولِ صریحِ «هر الگو → کدام استراتژی».
 *
 * ─── چه چیزی پیش از R60 اشتباه بود ─────────────────────────────────────────
 * یک خط بود و بس:
 *
 *     if (url.pathname.startsWith('/api/')) {
 *       event.respondWith(networkFirst(request, CACHE.pages, LIMIT.pages))
 *     }
 *
 * یعنی **همه‌ی** مسیرهای API روی دیسک نوشته می‌شدند: موجودیِ کیفِ پول،
 * فهرستِ قبض‌ها، پیام‌های ساکن، پروفایلش — و بدتر از همه
 * `/api/v1/payments/{id}/receipt`، یعنی **تصویرِ رسیدِ بانکی**.
 *
 * `networkFirst` یعنی در قطعیِ شبکه همان نسخه‌ی کهنه سرو می‌شود. روی
 * دستگاهِ مشترک، نفرِ بعدی می‌توانست آفلاین شود و داده‌ی نفرِ قبلی را
 * ببیند — چون Cache Storage به نشستِ سرور کاری ندارد.
 *
 * ⚠️ و کامنتِ بالای همان خط ادعا می‌کرد «پاسخِ API عمداً در کشِ صفحات
 * نمی‌رود». کد خلافش را می‌گفت. کامنتی که با کد نمی‌خواند بدتر از نبودنش
 * است: خواننده را مطمئن می‌کند که مشکلی نیست.
 *
 * ─── قاعده‌ی تازه: منعِ پیش‌فرض ─────────────────────────────────────────────
 * ترتیب مهم است و اولین قاعده‌ی جوردرآمده برنده است. قاعده‌ی دومِ این
 * جدول هر مسیرِ APIی را که در فهرستِ عمومی نباشد به `networkOnly`
 * می‌فرستد — پس **مسیرِ تازه‌ای که فردا اضافه شود خودبه‌خود امن است**.
 *
 * هزینه‌اش صادقانه: دادهٔ API دیگر آفلاین در دسترس نیست. پوسته‌ی برنامه و
 * صفحه‌های دیده‌شده همچنان باز می‌شوند، ولی عددهای مالی نه — و این درست
 * است، چون عددِ مالیِ کهنه بدتر از نبودنِ عدد است.
 */
const POLICY = [
  {
    name: 'public-api',
    test: (url) => isPublicApi(url.pathname),
    strategy: 'networkFirst',
    cache: () => CACHE.api,
    limit: () => LIMIT.api,
  },
  {
    // 🔴 منعِ پیش‌فرض — هر چیزِ دیگری زیرِ /api هرگز روی دیسک نمی‌نشیند
    name: 'private-api',
    test: (url) => url.pathname.startsWith('/api/'),
    strategy: 'networkOnly',
  },
  {
    // تصویرِ آگهی: عمومی، و با `?v=` نسخه‌بندی می‌شود
    name: 'ad-image',
    test: (url) => /^\/ads\/\d+\/image$/.test(url.pathname),
    strategy: 'cacheFirst',
    cache: () => CACHE.images,
    limit: () => LIMIT.images,
  },
  {
    name: 'asset',
    test: (url) => ASSET_EXT.test(url.pathname),
    strategy: 'staleWhileRevalidate',
    cache: () => CACHE.assets,
  },
  {
    name: 'image-or-font',
    test: (url) => IMAGE_EXT.test(url.pathname) || FONT_EXT.test(url.pathname),
    strategy: 'cacheFirst',
    cache: () => CACHE.images,
    limit: () => LIMIT.images,
  },
  {
    name: 'page',
    test: () => true,
    strategy: 'networkFirst',
    cache: () => CACHE.pages,
    limit: () => LIMIT.pages,
  },
]

/** اولین قاعده‌ی جوردرآمده. همیشه چیزی برمی‌گرداند، چون قاعده‌ی آخر همه را می‌گیرد. */
function policyFor(url) {
  return POLICY.find((rule) => rule.test(url))
}

/*
 * ⚠️ برای تست بیرون گذاشته می‌شوند.
 *
 * `sw.js` اسکریپتِ کلاسیک است و import نمی‌شود؛ تست فایل را می‌خواند و در
 * یک `self`ِ ساختگی اجرا می‌کند. بدونِ این دو خط، تنها راهِ سنجشِ
 * مسیریابی، بازنویسیِ همان منطق در تست بود — یعنی سنجشِ نسخه‌ی دوم به‌جای
 * چیزی که واقعاً اجرا می‌شود.
 */
self.__policyFor = policyFor
self.__cacheable = cacheable

/* ─────────────────────────── مسیریابی ─────────────────────────── */

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

  const rule = policyFor(url)

  /*
   * ⚠️ `networkOnly` یعنی **اصلاً دخالت نکن**.
   *
   * وسوسه‌ی طبیعی این بود که `event.respondWith(fetch(request))` بنویسم.
   * ولی آن یعنی هر درخواستِ شخصی از میانِ کدِ ما رد شود و هر اشتباهِ
   * آینده در این فایل بتواند به آن دست بزند. برنگرداندنِ پاسخ یعنی
   * مرورگر دقیقاً همان کاری را می‌کند که انگار SWی وجود ندارد — کمترین
   * سطحِ ممکن برای حساس‌ترین داده.
   */
  if (rule.strategy === 'networkOnly') return

  if (rule.strategy === 'staleWhileRevalidate') {
    event.respondWith(staleWhileRevalidate(request, rule.cache()))
    return
  }

  if (rule.strategy === 'cacheFirst') {
    event.respondWith(cacheFirst(request, rule.cache(), rule.limit?.()))
    return
  }

  event.respondWith(networkFirst(request, rule.cache(), rule.limit?.()))
})
