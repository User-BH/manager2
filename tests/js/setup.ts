import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'

/**
 * راه‌اندازی مشترک تست‌های فرانت.
 *
 * پس از هر تست، درختِ رندرشده پاک می‌شود تا تست بعدی از صفر شروع کند و
 * کوئری‌ها به المان‌های تستِ قبلی نخورند.
 */
afterEach(() => {
  cleanup()
  localStorage.clear()
})

/*
 * jsdom این دو را پیاده نکرده و کامپوننت‌های ما (ThemeContext و انیمیشن‌ها)
 * به آن‌ها تکیه می‌کنند؛ بدون این stubها رندر با خطا می‌افتد.
 */
if (!window.matchMedia) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }))
}

if (!window.scrollTo) {
  window.scrollTo = vi.fn() as unknown as typeof window.scrollTo
}

/*
 * خاموش‌کردن یک هشدارِ شناخته‌شده‌ی framer-motion.
 *
 * چند کامپوننت (مثل میله‌های سنجه‌ی قدرت رمز) `backgroundColor` را از یک
 * متغیر CSS به متغیر دیگر انیمیت می‌کنند و framer-motion نمی‌تواند متغیرِ CSS
 * را درون‌یابی کند. این در مرورگر یعنی رنگ «می‌پرد» به‌جای اینکه نرم عوض شود —
 * یک نقصِ ظاهریِ کوچک که در R37 (انیمیشن/دسترس‌پذیری) رسیدگی می‌شود.
 *
 * فقط همین یک پیام فیلتر می‌شود تا خروجیِ تست خوانا بماند؛ بقیه‌ی هشدارها و
 * خطاها دست‌نخورده رد می‌شوند و پنهان نمی‌مانند.
 */
const originalWarn = console.warn
console.warn = (...args: unknown[]) => {
  if (typeof args[0] === 'string' && args[0].includes('is not an animatable value')) return
  originalWarn(...(args as Parameters<typeof console.warn>))
}
