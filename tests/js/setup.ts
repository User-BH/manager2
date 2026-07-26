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
