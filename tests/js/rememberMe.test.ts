import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  forgetRememberedPhone,
  loadRememberedPhone,
  saveRememberedPhone,
} from '@/shared/lib/rememberMe'

/**
 * «مرا به خاطر بسپار» باید دقیقاً ۱۰ روز اعتبار داشته باشد — همان مدتی که
 * سرور برای دستگاهِ مورداعتماد اعمال می‌کند. چون localStorage خودش انقضا
 * ندارد، این منطق دستی است و باید تست شود.
 */

const DAY = 24 * 60 * 60 * 1000

describe('rememberMe', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.useRealTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('شماره را ذخیره و بازیابی می‌کند', () => {
    saveRememberedPhone('09123456789')
    expect(loadRememberedPhone()).toBe('09123456789')
  })

  it('وقتی چیزی ذخیره نشده null برمی‌گرداند', () => {
    expect(loadRememberedPhone()).toBeNull()
  })

  it('با forget، شماره پاک می‌شود', () => {
    saveRememberedPhone('09123456789')
    forgetRememberedPhone()
    expect(loadRememberedPhone()).toBeNull()
  })

  it('درست پیش از ۱۰ روز هنوز معتبر است', () => {
    saveRememberedPhone('09123456789')

    vi.useFakeTimers()
    vi.setSystemTime(Date.now() + 10 * DAY - 60_000)

    expect(loadRememberedPhone()).toBe('09123456789')
  })

  it('پس از ۱۰ روز منقضی می‌شود و خودش را پاک می‌کند', () => {
    saveRememberedPhone('09123456789')

    vi.useFakeTimers()
    vi.setSystemTime(Date.now() + 10 * DAY + 60_000)

    expect(loadRememberedPhone()).toBeNull()
    // فقط null برنمی‌گرداند، بلکه رکوردِ منقضی را هم پاک می‌کند
    expect(localStorage.getItem('sakena.remember')).toBeNull()
  })

  it('دادهٔ خراب را بی‌سروصدا نادیده می‌گیرد', () => {
    localStorage.setItem('sakena.remember', 'not-json{{{')
    expect(loadRememberedPhone()).toBeNull()
  })

  it('رکوردِ بدونِ شماره را نامعتبر می‌داند', () => {
    localStorage.setItem('sakena.remember', JSON.stringify({ expiresAt: Date.now() + DAY }))
    expect(loadRememberedPhone()).toBeNull()
  })
})
