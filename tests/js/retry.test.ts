import { describe, expect, it, vi } from 'vitest'

import { ApiError, isRetryable, parseRetryAfter } from '@/shared/lib/apiError'
import { MAX_ATTEMPTS, backoffDelay, sleep } from '@/shared/lib/retry'

/**
 * سیاستِ تلاشِ دوباره.
 *
 * این تست‌ها ارزشِ واقعی دارند چون هر دو نوع اشتباه اینجا گران است: تلاشِ
 * دوباره روی خطای اعتبارسنجی، سرور را الکی سه برابر می‌زند؛ و تلاشِ دوباره روی
 * `POST` می‌تواند پرداختِ دوم بسازد.
 */

describe('isRetryable — چه خطایی ارزشِ تلاشِ دوباره دارد', () => {
  it('قطعیِ شبکه (status صفر) قابلِ تلاشِ دوباره است', () => {
    expect(isRetryable(new ApiError('ارتباط برقرار نشد', 0), 'GET')).toBe(true)
  })

  it.each([408, 425, 429, 500, 502, 503, 504])('وضعیتِ گذرای %i تلاش می‌شود', (status) => {
    expect(isRetryable(new ApiError('گذرا', status), 'GET')).toBe(true)
  })

  it.each([400, 401, 403, 404, 409, 422])('خطای دائمیِ %i تلاش نمی‌شود', (status) => {
    expect(isRetryable(new ApiError('دائمی', status), 'GET')).toBe(false)
  })

  it('۴۱۹ تلاش نمی‌شود؛ مدیریتش با تازه‌کردنِ توکنِ CSRF است', () => {
    // اگر این true شود، دو مکانیزمِ جبران روی هم می‌افتند
    expect(isRetryable(new ApiError('توکن کهنه', 419), 'GET')).toBe(false)
  })

  it.each(['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'])(
    'متدِ idempotent %s تلاش می‌شود',
    (method) => {
      expect(isRetryable(new ApiError('سرور', 503), method)).toBe(true)
    },
  )

  it.each(['POST', 'PATCH'])('متدِ غیرidempotent %s تلاش نمی‌شود', (method) => {
    // مهم‌ترین تستِ این فایل: تکرارِ POST می‌تواند رکوردِ دوم بسازد
    expect(isRetryable(new ApiError('سرور', 503), method)).toBe(false)
  })

  it('خطایی که ApiError نیست تلاش نمی‌شود', () => {
    expect(isRetryable(new Error('چیزِ ناشناس'), 'GET')).toBe(false)
    expect(isRetryable('رشته', 'GET')).toBe(false)
  })
})

describe('parseRetryAfter', () => {
  it('قالبِ ثانیه را می‌خواند', () => {
    expect(parseRetryAfter('30')).toBe(30_000)
  })

  it('قالبِ تاریخِ HTTP را می‌خواند', () => {
    const now = Date.UTC(2026, 0, 1, 12, 0, 0)
    const later = new Date(now + 45_000).toUTCString()
    // به‌خاطر گردکردنِ ثانیه در قالبِ HTTP، بازه را می‌سنجیم
    expect(parseRetryAfter(later, now)).toBeGreaterThan(43_000)
    expect(parseRetryAfter(later, now)).toBeLessThanOrEqual(45_000)
  })

  it('مقدارِ غایب یا بی‌معنا نادیده گرفته می‌شود', () => {
    expect(parseRetryAfter(undefined)).toBeUndefined()
    expect(parseRetryAfter(null)).toBeUndefined()
    expect(parseRetryAfter('')).toBeUndefined()
    expect(parseRetryAfter('چرت')).toBeUndefined()
    // منفی یا صفر نباید به انتظارِ عجیب تبدیل شود
    expect(parseRetryAfter('-5')).toBeUndefined()
    expect(parseRetryAfter('0')).toBeUndefined()
  })

  it('تاریخِ گذشته نادیده گرفته می‌شود', () => {
    const now = Date.UTC(2026, 0, 1, 12, 0, 0)
    expect(parseRetryAfter(new Date(now - 60_000).toUTCString(), now)).toBeUndefined()
  })
})

describe('backoffDelay', () => {
  it('سقفِ نمایی رشد می‌کند (با random = ۱ سقف دیده می‌شود)', () => {
    const max = () => 1
    expect(backoffDelay(0, undefined, max)).toBe(300)
    expect(backoffDelay(1, undefined, max)).toBe(600)
    expect(backoffDelay(2, undefined, max)).toBe(1200)
  })

  it('jitter فاصله را پخش می‌کند، نه اینکه همه هم‌زمان برگردند', () => {
    // با random = ۰ کمینه و با random = ۱ سقف؛ یعنی بازه واقعاً تصادفی است
    expect(backoffDelay(3, undefined, () => 0)).toBe(0)
    expect(backoffDelay(3, undefined, () => 1)).toBe(2400)
  })

  it('از سقفِ ۸ ثانیه رد نمی‌شود', () => {
    expect(backoffDelay(20, undefined, () => 1)).toBe(8000)
  })

  it('اگر سرور Retry-After داده باشد، حرفِ سرور مقدم است و jitter نمی‌خورد', () => {
    expect(backoffDelay(0, 5_000, () => 0.5)).toBe(5_000)
  })

  it('Retry-Afterِ بزرگ هم به سقف بریده می‌شود', () => {
    expect(backoffDelay(0, 600_000)).toBe(8_000)
  })

  it('حداکثر سه تلاش کل انجام می‌شود', () => {
    expect(MAX_ATTEMPTS).toBe(3)
  })
})

describe('sleep — انتظارِ لغوشدنی', () => {
  it('پس از مدتِ خواسته‌شده تمام می‌شود', async () => {
    vi.useFakeTimers()
    try {
      const done = vi.fn()
      const promise = sleep(1000).then(done)
      expect(done).not.toHaveBeenCalled()
      await vi.advanceTimersByTimeAsync(1000)
      await promise
      expect(done).toHaveBeenCalled()
    } finally {
      vi.useRealTimers()
    }
  })

  it('با abort فوراً رد می‌شود و منتظرِ پایانِ تایمر نمی‌ماند', async () => {
    const controller = new AbortController()
    const promise = sleep(10_000, controller.signal)
    controller.abort()
    // اگر انتظار لغو نشود، این تست با timeout شکست می‌خورد
    await expect(promise).rejects.toThrow()
  })

  it('اگر سیگنال از قبل لغو شده باشد، تایمری ساخته نمی‌شود', async () => {
    const controller = new AbortController()
    controller.abort()
    await expect(sleep(10_000, controller.signal)).rejects.toThrow()
  })
})
