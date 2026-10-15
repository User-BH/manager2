import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * سیاستِ کشِ service worker (R60).
 *
 * ─── 🔴 نقصی که این مرحله پیدا کرد ─────────────────────────────────────────
 * `public/sw.js` **همه‌ی** مسیرهای `/api/` را با `networkFirst` کش می‌کرد.
 * یعنی موجودیِ کیفِ پول، فهرستِ قبض‌ها، پیام‌های ساکن، پروفایلش — و
 * `/api/v1/payments/{id}/receipt`، یعنی **تصویرِ رسیدِ بانکی** — روی دیسکِ
 * دستگاه نوشته می‌شدند.
 *
 * `networkFirst` یعنی در قطعیِ شبکه همان نسخه‌ی کهنه سرو می‌شود. روی
 * دستگاهِ مشترک، نفرِ بعدی می‌توانست آفلاین شود و داده‌ی نفرِ قبلی را
 * ببیند — Cache Storage به نشستِ سرور کاری ندارد.
 *
 * ─── چرا خودِ فایل اجرا می‌شود و نه نسخه‌ی دومی از منطقش ────────────────────
 * ⚠️ `sw.js` اسکریپتِ کلاسیک است و import نمی‌شود. وسوسه‌ی طبیعی این بود
 * که جدولِ سیاست را در تست دوباره بنویسم — ولی آن‌وقت این تست **نسخه‌ی
 * دوم** را می‌سنجید، نه چیزی که واقعاً روی دستگاهِ کاربر اجرا می‌شود، و
 * واگراییِ این دو دقیقاً همان چیزی است که نشت را ساخت.
 *
 * پس فایل خوانده و در یک `self`ِ ساختگی اجرا می‌شود.
 */

interface Rule {
  name: string
  strategy: string
  normalize?: boolean
}

interface SwSandbox {
  __policyFor: (url: URL) => Rule
  __cacheable: (response: unknown) => boolean
  __cacheKeyFor: (
    request: { url: string; headers: unknown },
    normalize?: boolean,
  ) => { url: string }
}

function loadServiceWorker(): SwSandbox {
  const source = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf8')

  /*
   * ⚠️ `node:vm` و نه `new Function`.
   *
   * هر دو کار می‌کنند، ولی این یکی صریح می‌گوید دارد چه می‌کند — اجرای
   * یک فایلِ خودی در جعبه‌ای با `self`ِ ساختگی — و قاعده‌ی
   * `no-implied-eval` را هم دور نمی‌زند، رعایت می‌کند.
   */
  const self: Record<string, unknown> = {
    addEventListener: () => {},
    location: { origin: 'https://sakena.test' },
    registration: {},
    clients: {},
    skipWaiting: () => {},
  }

  const sandbox = {
    self,
    caches: {
      open: () => Promise.resolve({}),
      keys: () => Promise.resolve([]),
      match: () => Promise.resolve(undefined),
    },
    fetch: () => Promise.resolve(undefined),
    URL,
    Request,
    console,
  }

  runInNewContext(source, sandbox)

  return self as unknown as SwSandbox
}

const sw = loadServiceWorker()

const ruleFor = (path: string) => sw.__policyFor(new URL(path, 'https://sakena.test')).name
const strategyFor = (path: string) => sw.__policyFor(new URL(path, 'https://sakena.test')).strategy

/** پاسخِ ساختگی با هدرهای دلخواه. */
function response(status: number, headers: Record<string, string> = {}, type = 'basic') {
  const map = new Map(Object.entries(headers).map(([k, v]) => [k.toLowerCase(), v]))

  return { status, type, headers: { get: (key: string) => map.get(key.toLowerCase()) ?? null } }
}

describe('منعِ پیش‌فرض برای مسیرهای API', () => {
  /**
   * 🔴 مهم‌ترین ادعای این مرحله.
   *
   * ⚠️ فهرست عمداً نمونه‌های **مالی و شخصی** را دارد؛ همان‌هایی که بندِ
   * ممیزی نام برده بود.
   */
  it.each([
    '/api/v1/me',
    '/api/v1/csrf-token',
    '/api/v1/my-bills',
    '/api/v1/wallet',
    '/api/v1/payments',
    '/api/v1/payments/12/receipt',
    '/api/v1/messenger/5/attachment',
    '/api/v1/service-requests/3/attachment',
    '/api/v1/finance/summary',
    '/api/v1/units',
    '/api/me',
    '/api/my-bills',
  ])('«%s» هرگز روی دیسک نمی‌نشیند', (path) => {
    expect(strategyFor(path)).toBe('networkOnly')
  })

  /**
   * ⚠️ محافظِ اصلی: مسیری که هنوز وجود ندارد هم باید امن باشد.
   *
   * قاعده‌ی قبلی «همه‌ی /api را کش کن» بود، پس هر endpointِ تازه‌ای
   * خودبه‌خود نشت می‌کرد. حالا برعکس است.
   */
  it('مسیرِ APIی که هنوز ساخته نشده هم پیش‌فرضش منع است', () => {
    expect(strategyFor('/api/v1/چیزی-که-فردا-اضافه-می‌شود')).toBe('networkOnly')
    expect(strategyFor('/api/v1/a/b/c/d/e')).toBe('networkOnly')
  })
})

describe('مسیرهای عمومی', () => {
  it.each(['/api/v1/features', '/api/v1/site-settings', '/api/v1/ads', '/api/v1/support/starters'])(
    '«%s» چون برای همه یکسان است کش می‌شود',
    (path) => {
      expect(ruleFor(path)).toBe('public-api')
    },
  )

  it('هر دو پیشوند را می‌شناسد', () => {
    expect(ruleFor('/api/ads')).toBe('public-api')
    expect(ruleFor('/api/v1/ads')).toBe('public-api')
  })

  /**
   * ⚠️ تطبیق باید **دقیق** باشد.
   *
   * اگر با `startsWith` نوشته می‌شد، `/api/v1/ads/7/image` هم «عمومی»
   * می‌شد — و بدتر، مسیرهای زیرِ `/api/v1/features/...`ِ فرضی هم.
   */
  it('زیرمسیرِ یک مسیرِ عمومی، عمومی حساب نمی‌شود', () => {
    expect(strategyFor('/api/v1/ads/7/image')).toBe('networkOnly')
    expect(strategyFor('/api/v1/site-settings/secret')).toBe('networkOnly')
  })

  /**
   * ⚠️ این دو احراز هویت نمی‌خواهند ولی عمومی هم نیستند: یکی می‌گوید «تو
   * کیستی» و دیگری توکنِ امنیتیِ زمان‌دار است.
   */
  it('me و csrf-token با اینکه بی‌نیاز از ورودند، کش نمی‌شوند', () => {
    expect(strategyFor('/api/v1/me')).toBe('networkOnly')
    expect(strategyFor('/api/v1/csrf-token')).toBe('networkOnly')
  })
})

describe('بقیه‌ی مسیرها همچنان آفلاین کار می‌کنند', () => {
  it('دارایی‌های اثرانگشت‌دار از کش می‌آیند', () => {
    expect(strategyFor('/build/assets/app-a1b2c3.js')).toBe('staleWhileRevalidate')
    expect(strategyFor('/build/assets/app-a1b2c3.css')).toBe('staleWhileRevalidate')
  })

  it('تصویر و فونت کش‌اول‌اند', () => {
    expect(strategyFor('/icons/icon-192.png')).toBe('cacheFirst')
    expect(strategyFor('/fonts/vazir.woff2')).toBe('cacheFirst')
  })

  it('تصویرِ آگهی عمومی است و در سطلِ تصاویر می‌نشیند', () => {
    expect(ruleFor('/ads/7/image')).toBe('ad-image')
  })

  it('صفحه‌ها شبکه‌اول می‌مانند', () => {
    expect(ruleFor('/dashboard')).toBe('page')
    expect(ruleFor('/')).toBe('page')
  })
})

describe('لایه‌ی دوم: خودِ پاسخ هم باید اجازه بدهد', () => {
  it('پاسخِ سالمِ هم‌مبدأ کش می‌شود', () => {
    expect(sw.__cacheable(response(200))).toBe(true)
  })

  /**
   * 🔴 سیگنالِ صریحِ سرور.
   *
   * میدل‌ورِ `ApiCacheHeaders` روی هر مسیرِ خصوصی `no-store` می‌گذارد. اگر
   * روزی جدولِ سیاست اشتباه شود، این شرط همچنان جلوی نوشتن را می‌گیرد.
   */
  it('پاسخی که سرور گفته ذخیره نشود، ذخیره نمی‌شود', () => {
    expect(sw.__cacheable(response(200, { 'Cache-Control': 'no-store, private' }))).toBe(false)
  })

  /** پاسخی که به کوکی وابسته است یعنی جوابِ یک نفرِ مشخص. */
  it('پاسخِ وابسته به کوکی ذخیره نمی‌شود', () => {
    expect(sw.__cacheable(response(200, { Vary: 'Accept-Encoding, Cookie' }))).toBe(false)
  })

  it('پاسخِ ناموفق یا از مبدأ دیگر ذخیره نمی‌شود', () => {
    expect(sw.__cacheable(response(404))).toBe(false)
    expect(sw.__cacheable(response(200, {}, 'opaque'))).toBe(false)
  })
})

describe('نسخه‌ی کش', () => {
  /**
   * 🔴 بالا رفتنِ نسخه در R60 نظافت نیست، پاک‌کردنِ نشت است.
   *
   * نصب‌های موجود پاسخ‌های شخصی را در `sakena-pages-v3` دارند. `activate`
   * هر سطلی را که در `CACHE` نباشد پاک می‌کند، پس همین عدد باعث می‌شود آن
   * داده با فعال‌شدنِ نسخه‌ی تازه برود.
   */
  it('نسخه از v3 جلوتر رفته تا سطلِ نشتیِ قدیمی پاک شود', () => {
    const source = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf8')
    const match = source.match(/const VERSION = 'v(\d+)'/)

    expect(match).not.toBeNull()
    expect(Number(match?.[1])).toBeGreaterThan(3)
  })

  /**
   * «چند نکته»، بندهای ۲ و ۷: آیکون‌ها و manifest با همان نشانی عوض شدند و تصویر `cacheFirst` است؛ فقط پاک‌شدنِ
   * سطل‌های v4 آیکونِ قدیمِ زمینه‌سفید را از مرورگرهایی که سایت را دیده‌اند بیرون می‌برد.
   */
  it('نسخه از v4 جلوتر رفته تا آیکونِ قدیمیِ نصب از کش برود', () => {
    const source = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf8')
    const match = source.match(/const VERSION = 'v(\d+)'/)

    expect(Number(match?.[1])).toBeGreaterThan(4)
  })

  it('سطلِ API از سطلِ صفحه‌ها جداست', () => {
    const source = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf8')

    expect(source).toContain('api: `sakena-api-${VERSION}`')
  })
})

describe('نرمال‌سازیِ کلیدِ کش', () => {
  const keyFor = (path: string, normalize?: boolean) =>
    sw.__cacheKeyFor(
      { url: new URL(path, 'https://sakena.test').toString(), headers: new Headers() },
      normalize,
    ).url

  /**
   * 🔴 آدرس‌های ساختگی نباید ورودیِ جدا بسازند.
   *
   * ─── حمله‌ای که این را لازم کرد ──────────────────────────────────────────
   * ⚠️ Cache Storage کلید را از **کلِ URL** می‌سازد. پس `?x=1` تا `?x=99`
   * نود‌ونه ورودیِ جدا می‌شوند، با اینکه سرور برای همه یک چیز می‌دهد.
   *
   * سقفِ `LIMIT` جلوی رشدِ بی‌نهایت را می‌گیرد، ولی مهاجم می‌تواند با چند
   * ده آدرسِ بی‌معنی کشِ **واقعی** را بیرون بریزد. آن‌وقت کاربر در قطعیِ
   * شبکه چیزی برای دیدن ندارد — نه سرریز، بلکه پاک‌سازیِ هدفمند.
   */
  it('پارامترهای بی‌اثر از کلید حذف می‌شوند', () => {
    const base = keyFor('/api/v1/ads', true)

    expect(keyFor('/api/v1/ads?x=1', true)).toBe(base)
    expect(keyFor('/api/v1/ads?x=2&y=3', true)).toBe(base)
    expect(base).toBe('https://sakena.test/api/v1/ads')
  })

  /**
   * ⚠️ و برعکسش لازم است: دارایی‌ها باید پارامترشان را نگه دارند.
   *
   * 🔴 `?v=` نسخه‌بندی است. اگر حذف شود، نسخه‌ی قدیمیِ فایل از کش سرو
   * می‌شود و به‌روزرسانی هرگز به کاربر نمی‌رسد — یعنی محافظِ ضدِ مسمومیت
   * خودش یک باگِ بدتر می‌سازد.
   */
  it('برای دارایی‌ها پارامتر دست‌نخورده می‌ماند', () => {
    expect(keyFor('/build/app.js?v=abc', false)).toBe('https://sakena.test/build/app.js?v=abc')
    expect(keyFor('/ads/7/image?v=2', false)).not.toBe(keyFor('/ads/7/image?v=3', false))
  })

  /** ⚠️ و جدولِ سیاست باید همین تفکیک را اعلام کند، نه چیزِ دیگری. */
  it('فقط سیاست‌های بی‌پارامتر نرمال‌سازی دارند', () => {
    const rule = (path: string) => sw.__policyFor(new URL(path, 'https://sakena.test'))

    expect(rule('/api/v1/ads').normalize).toBe(true)
    expect(rule('/dashboard').normalize).toBe(true)

    expect(rule('/build/app.js').normalize).toBeUndefined()
    expect(rule('/ads/7/image').normalize).toBeUndefined()
    expect(rule('/logo.png').normalize).toBeUndefined()
  })
})
