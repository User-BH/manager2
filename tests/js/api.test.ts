import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * تستِ یکپارچگیِ لایه‌ی API — همان چیدمانِ dedupe → retry → csrf → http.
 *
 * تست‌های واحدِ `retry.test.ts` و `dedupe.test.ts` هر قطعه را جدا می‌سنجند؛
 * اینجا سنجیده می‌شود که **به‌درستی به هم وصل شده‌اند**. اشتباهِ ترتیب (مثلاً
 * dedupe درونِ retry) در تستِ واحد دیده نمی‌شود.
 */

const request = vi.fn()
const refreshCsrfToken = vi.fn(() => Promise.resolve())

vi.mock('@/shared/lib/http', () => ({
  http: { request: (...args: unknown[]) => request(...args) },
  refreshCsrfToken: () => refreshCsrfToken(),
  setCsrfToken: vi.fn(),
  TIMEOUT_MS: 20_000,
  UPLOAD_TIMEOUT_MS: 120_000,
}))

const { api } = await import('@/shared/lib/api')
const { resetInflight } = await import('@/shared/lib/dedupe')

/** پاسخِ موفقِ axios-مانند. */
const ok = (data: unknown = { ok: true }) => ({ status: 200, data })

/** خطای axios-مانند با وضعیتِ دلخواه. */
function httpError(status: number, data: unknown = { message: 'خطا' }, headers = {}) {
  return { response: { status, data, headers }, isAxiosError: true }
}

beforeEach(() => {
  request.mockReset()
  refreshCsrfToken.mockClear()
  resetInflight()
  // backoff را صفر می‌کنیم تا تست سریع بماند؛ خودِ محاسبه‌اش جدا تست شده
  vi.spyOn(Math, 'random').mockReturnValue(0)
})

afterEach(() => vi.restoreAllMocks())

describe('api — تلاشِ دوباره', () => {
  it('خطای گذرا روی GET تلاشِ دوباره می‌شود و بار دوم موفق می‌شود', async () => {
    request.mockRejectedValueOnce(httpError(503)).mockResolvedValueOnce(ok({ value: 42 }))

    await expect(api('/things')).resolves.toEqual({ value: 42 })
    expect(request).toHaveBeenCalledTimes(2)
  })

  it('پس از سه تلاشِ ناموفق تسلیم می‌شود (بی‌پایان نمی‌چرخد)', async () => {
    request.mockRejectedValue(httpError(503))

    await expect(api('/things')).rejects.toThrow()
    expect(request).toHaveBeenCalledTimes(3)
  })

  it('خطای اعتبارسنجیِ ۴۲۲ هرگز تلاشِ دوباره نمی‌شود', async () => {
    request.mockRejectedValue(httpError(422, { message: 'نادرست', errors: { phone: ['بد'] } }))

    await expect(api('/things')).rejects.toThrow('نادرست')
    expect(request).toHaveBeenCalledTimes(1)
  })

  /*
   * حساس‌ترین قید: یک POSTِ پرداخت که timeout شده ممکن است روی سرور انجام شده
   * باشد. تلاشِ دوباره‌ی خودکار پرداختِ دوم می‌سازد.
   */
  it('POST با خطای گذرا هم تلاشِ دوباره نمی‌شود', async () => {
    request.mockRejectedValue(httpError(503))

    await expect(api('/payments', { method: 'POST', body: { amount: 1000 } })).rejects.toThrow()
    expect(request).toHaveBeenCalledTimes(1)
  })

  it('خطای فیلد از پاسخِ لاراول به ApiError می‌رسد', async () => {
    const { ApiError } = await import('@/shared/lib/api')
    request.mockRejectedValue(
      httpError(422, { message: 'خطای اعتبارسنجی', errors: { phone: ['شماره نادرست است'] } }),
    )

    await expect(api('/register', { method: 'POST' })).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof ApiError && error.fieldError('phone') === 'شماره نادرست است',
    )
  })
})

describe('api — توکنِ CSRF', () => {
  it('۴۱۹ یک‌بار توکن را نو می‌کند و درخواست را می‌فرستد', async () => {
    request.mockRejectedValueOnce(httpError(419)).mockResolvedValueOnce(ok())

    await expect(api('/things', { method: 'POST' })).resolves.toEqual({ ok: true })
    expect(refreshCsrfToken).toHaveBeenCalledTimes(1)
    expect(request).toHaveBeenCalledTimes(2)
  })

  it('۴۱۹ِ مکرر حلقه نمی‌سازد', async () => {
    request.mockRejectedValue(httpError(419))

    await expect(api('/things', { method: 'POST' })).rejects.toThrow()
    // یک تلاشِ اصلی + یک تلاش پس از نوکردنِ توکن، و تمام
    expect(request).toHaveBeenCalledTimes(2)
    expect(refreshCsrfToken).toHaveBeenCalledTimes(1)
  })
})

describe('api — یکی‌کردنِ درخواست', () => {
  it('دو GETِ هم‌زمانِ یکسان یک درخواستِ شبکه می‌شوند', async () => {
    let release!: (value: unknown) => void
    request.mockReturnValue(
      new Promise((resolve) => {
        release = resolve
      }),
    )

    const both = Promise.all([api('/notifications'), api('/notifications')])
    expect(request).toHaveBeenCalledTimes(1)

    release(ok({ items: [] }))
    await expect(both).resolves.toEqual([{ items: [] }, { items: [] }])
  })

  it('دو POSTِ یکسان یکی نمی‌شوند — هر دو باید فرستاده شوند', async () => {
    let calls = 0
    request.mockImplementation(() => {
      calls += 1
      return Promise.resolve(ok({ n: calls }))
    })

    await Promise.all([api('/messages', { method: 'POST' }), api('/messages', { method: 'POST' })])

    expect(request).toHaveBeenCalledTimes(2)
  })

  it('مسیرهای متفاوت با هم قاطی نمی‌شوند', async () => {
    request.mockResolvedValue(ok())

    await Promise.all([api('/a'), api('/b')])

    expect(request).toHaveBeenCalledTimes(2)
  })
})

describe('api — جزئیاتِ درخواست', () => {
  it('بدنه‌ی FormData را دست نمی‌زند و timeoutِ بلندِ آپلود می‌گیرد', async () => {
    request.mockResolvedValue(ok())
    const body = new FormData()

    await api('/receipts', { method: 'POST', body })

    const config = request.mock.calls[0][0] as { headers?: unknown; timeout?: number }
    // Content-Type نباید ست شود، وگرنه boundary درست تولید نمی‌شود
    expect(config.headers).toBeUndefined()
    expect(config.timeout).toBe(120_000)
  })

  it('بدنه‌ی JSON هدرِ Content-Type می‌گیرد', async () => {
    request.mockResolvedValue(ok())

    await api('/things', { method: 'POST', body: { a: 1 } })

    const config = request.mock.calls[0][0] as { headers?: Record<string, string> }
    expect(config.headers?.['Content-Type']).toBe('application/json')
  })

  it('پاسخِ ۲۰۴ به undefined تبدیل می‌شود، نه رشته‌ی خالی', async () => {
    request.mockResolvedValue({ status: 204, data: '' })

    await expect(api('/things', { method: 'DELETE' })).resolves.toBeUndefined()
  })

  /*
   * وقتی سرور می‌گوید «۵ ثانیه دیگر بیا»، باید ۵ ثانیه صبر کنیم — نه backoffِ
   * خودمان که اینجا حدودِ ۳۰۰ میلی‌ثانیه است. این تفاوتِ یک کلاینتِ مؤدب با
   * کلاینتی است که سرورِ زیرِ فشار را بیشتر می‌کوبد.
   */
  it('به هدرِ Retry-After سرور عمل می‌کند، نه backoffِ خودش', async () => {
    vi.useFakeTimers()
    try {
      request
        .mockRejectedValueOnce(httpError(429, { message: 'زیاد' }, { 'retry-after': '5' }))
        .mockResolvedValueOnce(ok())

      const promise = api('/things')

      await vi.advanceTimersByTimeAsync(4_000)
      expect(request, 'پیش از پایانِ ۵ ثانیه نباید دوباره تلاش کند').toHaveBeenCalledTimes(1)

      await vi.advanceTimersByTimeAsync(1_100)
      await expect(promise).resolves.toEqual({ ok: true })
      expect(request).toHaveBeenCalledTimes(2)
    } finally {
      vi.useRealTimers()
    }
  })
})
