/**
 * ⚠️ آدرسِ محیطِ تست عمداً `localhost` **نیست**.
 *
 * jsdom پیش‌فرض روی `localhost` اجرا می‌شود، و `serviceWorkerSupported()`
 * برای `localhost` استثنا قائل است تا توسعه روی HTTP ممکن بماند. یعنی با
 * آدرسِ پیش‌فرض، تستِ «روی مبدأ ناامن ثبت نمی‌کند» همیشه سبز می‌شد بی‌آنکه
 * چیزی سنجیده باشد — که در اولین اجرا هم دقیقاً همین شد.
 *
 * @vitest-environment jsdom
 * @vitest-environment-options { "url": "https://app.sakena.test/dashboard" }
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  clearApiCache,
  unregisterServiceWorker,
  registerServiceWorker,
  serviceWorkerSupported,
} from '@/shared/pwa/registerServiceWorker'
import { runningInstalled as isInstalled } from '@/shared/pwa/installState'

/**
 * ثبتِ service worker و تشخیصِ نصب (R35).
 *
 * ─── چرا این‌ها تست دارند ───────────────────────────────────────────────────
 * ⚠️ باگی که این مرحله پیدا کرد دقیقاً از همین جنس بود: `public/sw.js` وجود
 * داشت، کدش درست بود، و **هیچ‌جا ثبت نمی‌شد**. هیچ خطایی هم نمی‌داد — فقط
 * هیچ‌کدام از رفتارهای PWA کار نمی‌کرد. چیزی که خطا نمی‌دهد، بدونِ تست
 * دوباره برمی‌گردد.
 */

interface FakeWorker {
  postMessage: ReturnType<typeof vi.fn>
}

function fakeRegistration(overrides: Record<string, unknown> = {}) {
  return {
    installing: null,
    waiting: null,
    addEventListener: vi.fn(),
    navigationPreload: undefined,
    ...overrides,
  }
}

function installNavigator(options: {
  register?: ReturnType<typeof vi.fn>
  controller?: unknown
  secure?: boolean
  hostname?: string
}) {
  const register = options.register ?? vi.fn().mockResolvedValue(fakeRegistration())

  Object.defineProperty(window, 'isSecureContext', {
    value: options.secure ?? true,
    configurable: true,
  })

  Object.defineProperty(navigator, 'serviceWorker', {
    value: {
      register,
      controller: options.controller ?? null,
      addEventListener: vi.fn(),
    },
    configurable: true,
  })

  return register
}

afterEach(() => {
  vi.restoreAllMocks()
  Reflect.deleteProperty(navigator, 'serviceWorker')
})

describe('پشتیبانیِ service worker', () => {
  it('روی مبدأ ناامن ثبت نمی‌کند', () => {
    const register = installNavigator({ secure: false })

    /*
     * ⚠️ SW فقط روی مبدأ امن کار می‌کند. بدونِ این نگهبان، ثبت روی HTTP
     * فقط یک خطای کنسول می‌دهد و کاربر هیچ‌وقت نمی‌فهمد چرا هیچ‌چیز کش
     * نمی‌شود.
     */
    expect(serviceWorkerSupported()).toBe(false)
    expect(registerServiceWorker()).toBeNull()
    expect(register).not.toHaveBeenCalled()
  })

  /**
   * ⚠️ `updateViaCache: 'none'` صریح (R61).
   *
   * پیش‌فرضِ مرورگر (`imports`) فایل‌های importشده‌ی SW را از کشِ HTTP
   * می‌خواند. امروز importی نداریم؛ روزی که داشته باشیم، پیش‌فرض یعنی
   * نسخهٔ تازهٔ SW با تکه‌های **کهنه** بالا بیاید.
   */
  it('خواندنِ SW از کشِ HTTP صریح خاموش است', () => {
    const register = vi.fn().mockResolvedValue({ addEventListener: vi.fn() })
    installNavigator({ register })

    registerServiceWorker()

    expect(register).toHaveBeenCalledWith('/sw.js', {
      scope: '/',
      updateViaCache: 'none',
    })
  })

  it('روی مبدأ امن ثبت می‌کند و دامنه‌اش ریشه است', () => {
    const register = installNavigator({})

    expect(registerServiceWorker()).not.toBeNull()
    expect(register).toHaveBeenCalledWith('/sw.js', { scope: '/', updateViaCache: 'none' })
  })

  it('وقتی مرورگر اصلاً SW ندارد نمی‌ترکد', () => {
    Reflect.deleteProperty(navigator, 'serviceWorker')

    expect(serviceWorkerSupported()).toBe(false)
    expect(registerServiceWorker()).toBeNull()
  })
})

describe('اعلانِ نسخه‌ی تازه', () => {
  it('برای نصبِ اولِ کاربر پیام نمی‌دهد', async () => {
    /*
     * ⚠️ `controller` تهی یعنی این اولین نصب است. بدونِ این شرط، هر
     * بازدیدکننده‌ی تازه بلافاصله «نسخه‌ی جدید آماده است» می‌گرفت — پیامی
     * که برایش بی‌معناست.
     */
    const waiting: FakeWorker = { postMessage: vi.fn() }
    const register = vi.fn().mockResolvedValue(fakeRegistration({ waiting }))

    installNavigator({ register, controller: null })

    const handle = registerServiceWorker()
    const onReady = vi.fn()

    handle?.onUpdateReady(onReady)
    await vi.waitFor(() => expect(register).toHaveBeenCalled())

    expect(onReady).not.toHaveBeenCalled()
  })

  it('وقتی نسخه‌ای منتظر است و کنترل‌کننده‌ای هست، خبر می‌دهد', async () => {
    const waiting: FakeWorker = { postMessage: vi.fn() }
    const register = vi.fn().mockResolvedValue(fakeRegistration({ waiting }))

    installNavigator({ register, controller: { scriptURL: '/sw.js' } })

    const handle = registerServiceWorker()
    const onReady = vi.fn()

    handle?.onUpdateReady(onReady)

    await vi.waitFor(() => expect(onReady).toHaveBeenCalled())
  })

  /**
   * ⚠️ این دو تست بعد از پاسِ خرابکاری اضافه شدند.
   *
   * تستِ بالا فقط شاخه‌ی `registration.waiting` را می‌سنجید. وقتی عمداً
   * شرطِ `controller` را از شاخه‌ی `updatefound` برداشتم، هیچ تستی نشکست —
   * یعنی نصبِ **اولِ** کاربر می‌توانست بی‌سروصدا پیامِ «نسخه‌ی جدید» بگیرد
   * و هیچ‌کس نمی‌فهمید.
   */
  async function driveUpdateFound(controller: unknown) {
    const installed = { state: 'installing', listeners: [] as Array<() => void> }
    const worker = {
      get state() {
        return installed.state
      },
      addEventListener: (_: string, fn: () => void) => installed.listeners.push(fn),
      postMessage: vi.fn(),
    }

    let onUpdateFound: (() => void) | null = null

    const register = vi.fn().mockResolvedValue({
      installing: worker,
      waiting: null,
      addEventListener: (event: string, fn: () => void) => {
        if (event === 'updatefound') onUpdateFound = fn
      },
    })

    installNavigator({ register, controller })

    const handle = registerServiceWorker()
    const onReady = vi.fn()

    handle?.onUpdateReady(onReady)

    await vi.waitFor(() => expect(onUpdateFound).not.toBeNull())

    onUpdateFound!()
    installed.state = 'installed'
    installed.listeners.forEach((fn) => fn())

    return onReady
  }

  it('نصبِ اولِ کاربر از مسیرِ updatefound هم پیام نمی‌گیرد', async () => {
    expect(await driveUpdateFound(null)).not.toHaveBeenCalled()
  })

  it('به‌روزرسانیِ واقعی از مسیرِ updatefound پیام می‌دهد', async () => {
    expect(await driveUpdateFound({ scriptURL: '/sw.js' })).toHaveBeenCalled()
  })

  it('به‌روزرسانی فقط با خواستِ کاربر اعمال می‌شود', async () => {
    const waiting: FakeWorker = { postMessage: vi.fn() }
    const register = vi.fn().mockResolvedValue(fakeRegistration({ waiting }))

    installNavigator({ register, controller: { scriptURL: '/sw.js' } })

    const handle = registerServiceWorker()

    await vi.waitFor(() => expect(register).toHaveBeenCalled())

    /*
     * ⚠️ `skipWaiting`ِ خودکار صفحه‌ی بازِ کاربر را می‌شکند: نسخه‌ی تازه
     * کنترل را می‌گیرد در حالی که تکه‌های تنبلِ نسخه‌ی قبل هنوز بار نشده‌اند
     * و دیگر در کش نیستند.
     */
    expect(waiting.postMessage).not.toHaveBeenCalled()

    handle?.applyUpdate()

    expect(waiting.postMessage).toHaveBeenCalledWith({ type: 'SKIP_WAITING' })
  })
})

/**
 * 🔴 نصبِ اول صفحه را دوباره بار نمی‌کند (مرحلهٔ ۵ · فاز ۸).
 *
 * `sw.js` در `activate` خودش `clients.claim()` می‌کند، پس صفحه‌ی بی‌کنترل‌کننده
 * هم `controllerchange` می‌گیرد. آزمونِ مرورگر نشان داد داشبوردِ کاربرِ تازه
 * چند لحظه پس از باز شدن دوباره بار می‌شد.
 */
describe('بارگذاریِ دوباره پس از تغییرِ کنترل‌کننده', () => {
  function fireControllerChange(controller: unknown) {
    const reload = vi.fn()
    installNavigator({ controller })
    registerServiceWorker('/sw.js', reload)

    const listen = (navigator.serviceWorker.addEventListener as ReturnType<typeof vi.fn>).mock.calls
    const onChange = listen.find(([type]) => type === 'controllerchange')?.[1] as () => void

    return { reload, onChange }
  }

  it('نصبِ اول (بی‌کنترل‌کننده) صفحه را بار نمی‌کند', () => {
    const { reload, onChange } = fireControllerChange(null)
    onChange()
    expect(reload).not.toHaveBeenCalled()

    // ولی به‌روزرسانیِ بعدی در همان صفحه بار می‌کند
    onChange()
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('به‌روزرسانی (از نسخه‌ای به نسخه‌ی دیگر) یک بار بار می‌کند', () => {
    const { reload, onChange } = fireControllerChange({ scriptURL: '/sw.js' })
    onChange()
    onChange()
    expect(reload).toHaveBeenCalledTimes(1)
  })
})

describe('تشخیصِ نصب‌بودن', () => {
  beforeEach(() => {
    Reflect.deleteProperty(window.navigator, 'standalone')
  })

  it('در تبِ معمولیِ مرورگر نصب‌شده حساب نمی‌شود', () => {
    vi.spyOn(window, 'matchMedia').mockReturnValue({ matches: false } as MediaQueryList)

    expect(isInstalled()).toBe(false)
  })

  it('حالتِ standalone یعنی نصب‌شده', () => {
    vi.spyOn(window, 'matchMedia').mockReturnValue({ matches: true } as MediaQueryList)

    expect(isInstalled()).toBe(true)
  })

  it('⚠️ سافاریِ iOS را هم می‌شناسد', () => {
    /*
     * سافاری برای برنامه‌ی نصب‌شده هم `display-mode: browser` گزارش می‌کند
     * و به‌جایش `navigator.standalone` دارد. با یکی از این دو، روی iOS
     * دکمه‌ی نصب به کاربری نشان داده می‌شد که از قبل نصبش کرده.
     */
    vi.spyOn(window, 'matchMedia').mockReturnValue({ matches: false } as MediaQueryList)
    Object.defineProperty(window.navigator, 'standalone', { value: true, configurable: true })

    expect(isInstalled()).toBe(true)
  })
})

describe('پاک‌کردنِ کشِ API هنگامِ خروج', () => {
  it('فقط کشِ پاسخ‌های کاربر را پاک می‌کند، نه دارایی‌ها را', async () => {
    const deleted: string[] = []

    vi.stubGlobal('caches', {
      keys: vi
        .fn()
        .mockResolvedValue([
          'sakena-api-v4',
          'sakena-pages-v4',
          'sakena-pages-v3',
          'sakena-assets-v4',
          'sakena-images-v4',
          'sakena-shell-v4',
        ]),
      delete: vi.fn((key: string) => {
        deleted.push(key)
        return Promise.resolve(true)
      }),
    })

    await clearApiCache()

    /*
     * ⚠️ داده‌ی کاربر باید برود، ولی دارایی‌ها نه: پاک‌کردنِ فونت و JS
     * هنگامِ خروج یعنی ورودِ بعدی کندتر است بی‌آنکه چیزی امن‌تر شده باشد.
     *
     * 🔴 و `sakena-pages-v3` عمداً در فهرست است (R60): نصب‌هایی که هنوز
     * روی SWِ قدیمی مانده‌اند **داده‌ی شخصی** در آن سطل دارند. نسخه‌ی تازه
     * در `activate` پاکش می‌کند، ولی `activate` تا پذیرشِ به‌روزرسانی
     * اجرا نمی‌شود — پس خروج از حساب باید همان لحظه ببنددش.
     */
    expect(deleted.sort()).toEqual(['sakena-api-v4', 'sakena-pages-v3', 'sakena-pages-v4'])

    vi.unstubAllGlobals()
  })

  /**
   * 🔴 سطلِ API باید پاک شود.
   *
   * ⚠️ بدونِ این ادعا، برگرداندنِ تابع به نسخه‌ی «فقط pages» از تور رد
   * می‌شد — و سطلی که در R60 ساخته شد هرگز پاک نمی‌شد.
   */
  it('سطلِ تازه‌ی API را هم پاک می‌کند', async () => {
    const deleted: string[] = []

    vi.stubGlobal('caches', {
      keys: vi.fn().mockResolvedValue(['sakena-api-v4', 'sakena-assets-v4']),
      delete: vi.fn((key: string) => {
        deleted.push(key)
        return Promise.resolve(true)
      }),
    })

    await clearApiCache()

    expect(deleted).toEqual(['sakena-api-v4'])

    vi.unstubAllGlobals()
  })

  it('در محیطی که Cache API ندارد نمی‌ترکد', async () => {
    vi.stubGlobal('caches', undefined)

    await expect(clearApiCache()).resolves.toBeUndefined()

    vi.unstubAllGlobals()
  })
})

describe('کلیدِ خاموشیِ service worker (R61)', () => {
  /**
   * 🔴 راهِ بازگشت وقتی خودِ SW خراب است.
   *
   * ⚠️ استقرارِ خرابِ بک‌اند را با یک revert برمی‌گردانیم. ولی SWِ خراب
   * روی **دستگاهِ کاربر** نصب شده و هیچ استقراری به‌تنهایی پاکش نمی‌کند.
   */
  it('همه‌ی ثبت‌ها را حذف و همه‌ی کش‌های ما را پاک می‌کند', async () => {
    const unregister = vi.fn().mockResolvedValue(true)
    const deleted: string[] = []

    installNavigator({ register: vi.fn() })
    Object.assign(navigator.serviceWorker, {
      getRegistrations: vi.fn().mockResolvedValue([{ unregister }, { unregister }]),
    })

    vi.stubGlobal('caches', {
      keys: vi.fn().mockResolvedValue(['sakena-shell-v4', 'sakena-api-v4', 'other-app-cache']),
      delete: vi.fn((key: string) => {
        deleted.push(key)
        return Promise.resolve(true)
      }),
    })

    await unregisterServiceWorker()

    expect(unregister).toHaveBeenCalledTimes(2)

    // ⚠️ فقط کش‌های خودمان؛ سطلِ برنامه‌ی دیگری روی همین دامنه دست‌نخورده می‌ماند
    expect(deleted.sort()).toEqual(['sakena-api-v4', 'sakena-shell-v4'])

    vi.unstubAllGlobals()
  })
})
