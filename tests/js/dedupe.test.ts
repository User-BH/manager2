import { afterEach, describe, expect, it, vi } from 'vitest'

import { dedupe, inflightCount, resetInflight } from '@/shared/lib/dedupe'

/**
 * یکی‌کردنِ درخواست‌های هم‌زمان.
 *
 * تمرکزِ این تست‌ها روی همان شرایطِ مسابقه‌ای است که در عمل باگ می‌سازد و در
 * مرور کد به چشم نمی‌آید: وقتی یکی از مصرف‌کننده‌ها unmount می‌شود ولی بقیه
 * هنوز منتظرند.
 */

afterEach(() => resetInflight())

/** یک درخواستِ ساختگی که می‌توانیم دستی تمامش کنیم. */
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

describe('dedupe', () => {
  it('دو درخواستِ هم‌زمانِ یکسان فقط یک بار به شبکه می‌روند', async () => {
    const d = deferred<string>()
    const run = vi.fn(() => d.promise)

    const a = dedupe('GET /x', run)
    const b = dedupe('GET /x', run)

    expect(run).toHaveBeenCalledTimes(1)

    d.resolve('نتیجه')
    await expect(a).resolves.toBe('نتیجه')
    await expect(b).resolves.toBe('نتیجه')
  })

  it('کلیدهای متفاوت با هم قاطی نمی‌شوند', async () => {
    const run = vi.fn(() => Promise.resolve('ok'))

    await Promise.all([dedupe('GET /x', run), dedupe('GET /y', run)])

    expect(run).toHaveBeenCalledTimes(2)
  })

  it('پس از پایان، کلید آزاد می‌شود و درخواستِ بعدی تازه می‌رود', async () => {
    const run = vi.fn(() => Promise.resolve('ok'))

    await dedupe('GET /x', run)
    expect(inflightCount()).toBe(0)

    await dedupe('GET /x', run)
    expect(run).toHaveBeenCalledTimes(2)
  })

  it('خطا به همه‌ی مصرف‌کننده‌ها می‌رسد', async () => {
    const d = deferred<string>()
    const run = () => d.promise

    const a = dedupe('GET /x', run)
    const b = dedupe('GET /x', run)

    d.reject(new Error('سرور خطا داد'))

    await expect(a).rejects.toThrow('سرور خطا داد')
    await expect(b).rejects.toThrow('سرور خطا داد')
  })

  it('پس از خطا هم کلید آزاد می‌شود (کلیدِ سوخته نمی‌ماند)', async () => {
    const failing = () => Promise.reject(new Error('خطا'))

    await expect(dedupe('GET /x', failing)).rejects.toThrow()
    expect(inflightCount()).toBe(0)
  })

  /*
   * مهم‌ترین تستِ این فایل. اگر dedupe فقط promise را به اشتراک بگذارد، unmountِ
   * یک کامپوننت درخواستِ مشترک را می‌کشد و کامپوننتِ دیگری که هنوز روی صفحه است
   * بی‌دلیل خطای لغو می‌گیرد.
   */
  it('لغوِ یک مصرف‌کننده، مصرف‌کننده‌ی دیگر را قطع نمی‌کند', async () => {
    const d = deferred<string>()
    const innerSignals: AbortSignal[] = []
    const run = (signal: AbortSignal) => {
      innerSignals.push(signal)
      return d.promise
    }

    const first = new AbortController()
    const a = dedupe('GET /x', run, first.signal)
    const b = dedupe('GET /x', run)

    // اولی می‌رود (مثلاً کامپوننتش unmount شد)
    first.abort()
    await expect(a).rejects.toThrow()

    // درخواستِ زیرین نباید لغو شده باشد چون دومی هنوز منتظر است
    expect(innerSignals[0].aborted).toBe(false)

    d.resolve('نتیجه')
    await expect(b).resolves.toBe('نتیجه')
  })

  it('با رفتنِ آخرین مصرف‌کننده، درخواستِ زیرین واقعاً لغو می‌شود', async () => {
    const d = deferred<string>()
    let inner: AbortSignal | undefined
    const run = (signal: AbortSignal) => {
      inner = signal
      return d.promise
    }

    const first = new AbortController()
    const second = new AbortController()
    const a = dedupe('GET /x', run, first.signal)
    const b = dedupe('GET /x', run, second.signal)

    first.abort()
    await expect(a).rejects.toThrow()
    expect(inner?.aborted).toBe(false)

    second.abort()
    await expect(b).rejects.toThrow()
    // حالا هیچ‌کس منتظر نیست، پس نگه‌داشتنِ اتصال اتلاف است
    expect(inner?.aborted).toBe(true)
  })

  it('سیگنالی که از قبل لغو شده، بی‌درنگ رد می‌شود', async () => {
    const controller = new AbortController()
    controller.abort()
    const run = vi.fn(() => Promise.resolve('ok'))

    await expect(dedupe('GET /x', run, controller.signal)).rejects.toThrow()
  })
})
