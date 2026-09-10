import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { SearchResponse } from '@/shared/types'

/**
 * جست‌وجوی سراسری: debounce، لغو، و **شرایطِ مسابقه** (مرحلهٔ ۵، فاز ۳ — بندهای ۱۸ و ۱۹).
 *
 * ─── 🔴 چرا این فایل تازه است ──────────────────────────────────────────────
 * `searchStore` از قبل هر سه محافظ را داشت: debounce، `AbortController`، و
 * شناسهٔ صعودیِ درخواست برای «آخرین درخواست برنده است».
 *
 * ⚠️ ولی **هیچ تستی نداشت**. یعنی سه سازوکارِ ظریف که دقیقاً برای شرایطِ
 * مسابقه ساخته شده‌اند، هرگز زیرِ شرایطِ مسابقه اجرا نشده بودند.
 *
 * 🔴 بندِ ۱۹ صریح است: «Race Condition در Search بررسی و این سناریو تست
 * شود». نه «بررسی شود که کدش هست».
 *
 * ─── چرا `api` جعل می‌شود و نه شبکه ────────────────────────────────────────
 * سناریوی مسابقه یعنی **کنترلِ ترتیبِ رسیدنِ پاسخ‌ها**. با شبکهٔ واقعی
 * نمی‌شود تضمین کرد پاسخِ اول دیرتر برسد؛ با وعده‌های دستی می‌شود.
 */

const apiMock = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/api')>('@/shared/lib/api')

  return { ...actual, api: apiMock }
})

const { useSearchStore } = await import('@/shared/stores/searchStore')

function response(label: string): SearchResponse {
  // ⚠️ شیءِ واقعی، نه ادعای نوع — همان قاعده‌ای که خودِ این فاز گذاشت
  return { query: label, total: 1, groups: [] }
}

/** وعده‌ای که خودمان تصمیم می‌گیریم کِی و چطور تمام شود. */
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void

  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })

  return { promise, resolve, reject }
}

const DEBOUNCE_MS = 450

describe('جست‌وجوی سراسری', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    apiMock.mockReset()
    window.localStorage.clear()

    useSearchStore.setState({
      query: '',
      debouncedQuery: '',
      results: null,
      isSearching: false,
      error: null,
    })
  })

  /** ⚠️ تایپِ سریع نباید به‌ازای هر حرف یک درخواست بفرستد. */
  it('تایپِ پیاپی فقط یک درخواست می‌فرستد', async () => {
    apiMock.mockResolvedValue(response('نهایی'))

    const { setQuery } = useSearchStore.getState()

    setQuery('ما')
    setQuery('مال')
    setQuery('مالک')

    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS + 20)

    expect(apiMock).toHaveBeenCalledTimes(1)
    expect(apiMock.mock.calls[0]?.[0]).toContain(encodeURIComponent('مالک'))
  })

  /** ⚠️ عبارتِ کوتاه اصلاً درخواست نمی‌سازد. */
  it('عبارتِ کوتاه‌تر از حدِ مجاز درخواستی نمی‌فرستد', async () => {
    useSearchStore.getState().setQuery('م')

    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS + 20)

    expect(apiMock).not.toHaveBeenCalled()
    expect(useSearchStore.getState().results).toBeNull()
  })

  /**
   * 🔴 قلبِ بندِ ۱۹: پاسخِ **کهنه** نباید روی پاسخِ تازه بنشیند.
   *
   * ─── سناریوی واقعی ─────────────────────────────────────────────────────
   * کاربر «مالک» را می‌زند، شبکه کند است. بعد نظرش عوض می‌شود و «واحد» را
   * می‌زند که سریع جواب می‌گیرد. حالا پاسخِ «مالک» می‌رسد.
   *
   * ⚠️ بدونِ محافظ، کاربر عبارتِ «واحد» را در جعبه می‌بیند و نتایجِ «مالک»
   * را زیرش — و هیچ خطایی هم رخ نداده که کسی متوجه شود.
   */
  it('پاسخِ کهنه روی پاسخِ تازه نمی‌نشیند', async () => {
    const slow = deferred<SearchResponse>()
    const fast = deferred<SearchResponse>()

    apiMock.mockReturnValueOnce(slow.promise).mockReturnValueOnce(fast.promise)

    const { setQuery } = useSearchStore.getState()

    setQuery('مالک')
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS + 20)

    setQuery('واحد')
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS + 20)

    expect(apiMock).toHaveBeenCalledTimes(2)

    // پاسخِ دوم زودتر می‌رسد
    fast.resolve(response('واحد'))
    await vi.advanceTimersByTimeAsync(0)

    expect(useSearchStore.getState().results?.query).toBe('واحد')

    // 🔴 و حالا پاسخِ اول از راه می‌رسد — باید دور ریخته شود
    slow.resolve(response('مالک'))
    await vi.advanceTimersByTimeAsync(0)

    expect(
      useSearchStore.getState().results?.query,
      'پاسخِ کهنه نتیجهٔ تازه را بازنویسی کرد.',
    ).toBe('واحد')
  })

  /**
   * ⚠️ درخواستِ قبلی واقعاً لغو می‌شود، نه اینکه فقط نتیجه‌اش نادیده گرفته شود.
   *
   * 🔴 تفاوتش مهم است: نادیده‌گرفتن یعنی پهنای باند و کارِ سرور هدر رفته.
   */
  it('جست‌وجوی تازه، درخواستِ پیشین را لغو می‌کند', async () => {
    apiMock.mockImplementation(() => new Promise(() => {}))

    const { setQuery } = useSearchStore.getState()

    setQuery('مالک')
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS + 20)

    const firstSignal = (apiMock.mock.calls[0]?.[1] as { signal?: AbortSignal } | undefined)?.signal

    expect(firstSignal?.aborted).toBe(false)

    setQuery('واحد')
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS + 20)

    expect(firstSignal?.aborted, 'درخواستِ پیشین لغو نشد.').toBe(true)
  })

  /**
   * 🔴 خطای درخواستِ کهنه نباید پیامِ خطا نشان بدهد.
   *
   * ⚠️ وقتی درخواستی را خودمان لغو می‌کنیم، شکستش **نتیجهٔ درست** است.
   * نشان‌دادنش به کاربر یعنی خطای کاذب — و خطای کاذب همان چیزی است که
   * آدم‌ها یاد می‌گیرند نادیده بگیرند.
   */
  it('شکستِ درخواستِ کهنه خطا نشان نمی‌دهد', async () => {
    const stale = deferred<SearchResponse>()
    const fresh = deferred<SearchResponse>()

    apiMock.mockReturnValueOnce(stale.promise).mockReturnValueOnce(fresh.promise)

    const { setQuery } = useSearchStore.getState()

    setQuery('مالک')
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS + 20)

    setQuery('واحد')
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS + 20)

    fresh.resolve(response('واحد'))
    await vi.advanceTimersByTimeAsync(0)

    stale.reject(new Error('canceled'))
    await vi.advanceTimersByTimeAsync(0)

    expect(useSearchStore.getState().error).toBeNull()
    expect(useSearchStore.getState().results?.query).toBe('واحد')
  })
})
