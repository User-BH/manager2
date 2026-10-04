import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  COOLDOWN,
  DISMISSED_KEY,
  STATE_KEY,
  installGuide,
  markInstalled,
  markNotInstalled,
  markShown,
  mayOffer,
  runningInstalled,
} from '@/shared/pwa/installState'

/**
 * «نصب است؟» و «پیشنهاد بدهیم؟» (مرحلهٔ ۸ · فاز ۲ · بندِ ۱).
 *
 * سه چیز سنجیده می‌شود: سیگنال‌های واقعیِ «داخلِ اپ»، تشخیصِ مرورگرهایی که
 * نصب دارند ولی رویداد نه (فقط برای راهنما)، و قاعده‌ی تکرار — تا کارت نه به
 * نصب‌کرده نشان داده شود و نه با هر بارگذاری برگردد.
 */

const UA = {
  iphoneSafari:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
  ipadSafari:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
  macSafari17:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
  macSafari16:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Safari/605.1.15',
  macChrome:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
  macEdge:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0',
  macFirefox: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:131.0) Gecko/20100101 Firefox/131.0',
  androidFirefox: 'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0',
  androidChrome:
    'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
  windowsFirefox:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
  windowsChrome:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
}

const original = {
  ua: Object.getOwnPropertyDescriptor(window.navigator, 'userAgent'),
  touch: Object.getOwnPropertyDescriptor(window.navigator, 'maxTouchPoints'),
}

function browser(
  ua: string,
  { touch = 0, standalone }: { touch?: number; standalone?: boolean } = {},
) {
  Object.defineProperty(window.navigator, 'userAgent', { configurable: true, value: ua })
  Object.defineProperty(window.navigator, 'maxTouchPoints', { configurable: true, value: touch })
  if (standalone === undefined) Reflect.deleteProperty(window.navigator, 'standalone')
  else
    Object.defineProperty(window.navigator, 'standalone', { configurable: true, value: standalone })
}

/** فقط همین display-modeها برقرارند */
function displayModes(...modes: string[]) {
  vi.spyOn(window, 'matchMedia').mockImplementation(
    (query: string) =>
      ({ matches: modes.some((mode) => query === `(display-mode: ${mode})`) }) as MediaQueryList,
  )
}

beforeEach(() => {
  displayModes('browser')
  browser(UA.windowsChrome)
})

afterEach(() => {
  vi.restoreAllMocks()
  if (original.ua) Object.defineProperty(window.navigator, 'userAgent', original.ua)
  if (original.touch) Object.defineProperty(window.navigator, 'maxTouchPoints', original.touch)
  else Reflect.deleteProperty(window.navigator, 'maxTouchPoints')
  Reflect.deleteProperty(window.navigator, 'standalone')
})

describe('داخلِ اپِ نصب‌شده', () => {
  it('تبِ معمولیِ مرورگر نه', () => {
    expect(runningInstalled()).toBe(false)
  })

  it.each(['standalone', 'minimal-ui', 'window-controls-overlay'])(
    '%s — همان حالت‌های display_override ِ manifest — یعنی داخلِ اپ',
    (mode) => {
      displayModes(mode)
      expect(runningInstalled()).toBe(true)
    },
  )

  it('⚠️ fullscreen نه: کروم در F11 ِ یک تبِ معمولی هم آن را می‌گوید', () => {
    displayModes('fullscreen')
    expect(runningInstalled()).toBe(false)
  })

  it('iOS: سافاری برای اپِ افزوده‌شده هم display-mode ِ browser می‌گوید؛ navigator.standalone نشانه است', () => {
    browser(UA.iphoneSafari, { touch: 5, standalone: true })
    expect(runningInstalled()).toBe(true)
  })
})

describe('مرورگرهای بی‌رویداد — کدام راهنما', () => {
  it.each([
    ['سافاریِ آیفون', UA.iphoneSafari, { touch: 5, standalone: false }, 'ios'],
    ['آی‌پد (خودش را Macintosh می‌نامد)', UA.ipadSafari, { touch: 5, standalone: false }, 'ios'],
    ['مرورگرِ درون‌برنامه‌ای در iOS (بی‌standalone)', UA.iphoneSafari, { touch: 5 }, null],
    ['سافاریِ مک ‎۱۷ (Add to Dock)', UA.macSafari17, {}, 'macos-safari'],
    ['سافاریِ مک ‎۱۶ (بی‌Add to Dock)', UA.macSafari16, {}, null],
    ['کرومِ مک (رویداد دارد)', UA.macChrome, {}, null],
    ['اجِ مک (رویداد دارد)', UA.macEdge, {}, null],
    ['فایرفاکسِ مک (نصب ندارد)', UA.macFirefox, {}, null],
    ['فایرفاکسِ اندروید', UA.androidFirefox, { touch: 5 }, 'firefox-android'],
    ['کرومِ اندروید (رویداد دارد)', UA.androidChrome, { touch: 5 }, null],
    ['فایرفاکسِ ویندوز (نصب ندارد)', UA.windowsFirefox, {}, null],
  ] as const)('%s', (_name, ua, options, expected) => {
    browser(ua, options)
    expect(installGuide()).toBe(expected)
  })
})

describe('پیشنهاد بدهیم؟', () => {
  const NOW = Date.UTC(2026, 9, 4)

  it('بارِ اول بله', () => {
    expect(mayOffer('native', NOW)).toBe(true)
    expect(mayOffer('ios', NOW)).toBe(true)
  })

  it('داخلِ اپ هرگز', () => {
    displayModes('standalone')
    expect(mayOffer('native', NOW)).toBe(false)
  })

  it('بستن همیشگی است (کلیدِ مرحلهٔ ۷ · فاز ۵)', () => {
    localStorage.setItem(DISMISSED_KEY, '1')
    expect(mayOffer('native', NOW + 365 * COOLDOWN.native)).toBe(false)
  })

  it('بی‌بستن هم با هر بارگذاری برنمی‌گردد: دکمه ۳ روز، راهنما ۱۴ روز', () => {
    markShown(NOW)

    expect(mayOffer('native', NOW + 60_000)).toBe(false)
    expect(mayOffer('native', NOW + COOLDOWN.native - 1)).toBe(false)
    expect(mayOffer('native', NOW + COOLDOWN.native)).toBe(true)

    expect(mayOffer('ios', NOW + COOLDOWN.native)).toBe(false)
    expect(mayOffer('ios', NOW + COOLDOWN.guide)).toBe(true)
    expect(COOLDOWN.guide).toBeGreaterThan(COOLDOWN.native)
  })

  it('اپ این‌جا دیده شده: راهنما دیگر نه — ولی رویدادِ قطعیِ مرورگر هنوز معتبر است', () => {
    markInstalled()
    expect(mayOffer('firefox-android', NOW)).toBe(false)
    expect(mayOffer('native', NOW)).toBe(true)
  })

  it('رویدادِ مرورگر نشانه‌ی کهنه‌ی «نصب‌شده» را پاک می‌کند (اپ حذف شده بود)', () => {
    markInstalled()
    markNotInstalled()
    expect(mayOffer('firefox-android', NOW)).toBe(true)
  })

  it('انباره‌ی خراب یا مسدود نمی‌ترکاند', () => {
    localStorage.setItem(STATE_KEY, '{خراب')
    expect(mayOffer('native', NOW)).toBe(true)

    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('denied', 'SecurityError')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('denied', 'SecurityError')
    })
    expect(() => markShown(NOW)).not.toThrow()
    expect(mayOffer('native', NOW)).toBe(true)
  })
})
