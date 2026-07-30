/**
 * محاسبه‌ی فاصله‌ی تلاشِ دوباره (backoff نمایی با jitter) و انتظارِ لغوشدنی.
 *
 * چرا jitter؟ اگر سرور یک لحظه بالا نیاید و ۵۰ کلاینت هم‌زمان خطا بگیرند،
 * backoffِ خالص هر ۵۰ تا را **دقیقاً هم‌زمان** برمی‌گرداند و همان موجِ کوبنده
 * تکرار می‌شود (thundering herd). با jitter، تلاش‌ها روی بازه پخش می‌شوند.
 *
 * از الگوی «full jitter» استفاده می‌کنیم: یک عدد تصادفی در بازه‌ی [۰، سقف].
 * در عمل بهترین توزیع را می‌دهد و پیاده‌سازی‌اش هم ساده است.
 */

/** حداکثر تعدادِ کلِ تلاش‌ها (یعنی ۱ تلاشِ اصلی + ۲ تلاشِ دوباره). */
export const MAX_ATTEMPTS = 3

/** فاصله‌ی پایه؛ تلاشِ دومِ یک درخواست حدودِ همین‌قدر بعد می‌رود. */
const BASE_DELAY_MS = 300

/** سقفِ فاصله، تا انتظارِ کاربر بی‌انتها نشود. */
const MAX_DELAY_MS = 8_000

/**
 * فاصله‌ی پیش از تلاشِ شماره‌ی `attempt` (از صفر شمرده می‌شود).
 *
 * اگر سرور `Retry-After` داده باشد همان مقدار برمی‌گردد (بی jitter — سرور عددِ
 * دقیق خواسته)، فقط تا سقفِ منطقی بریده می‌شود.
 *
 * `random` تزریق‌شدنی است تا تست بتواند قطعی باشد؛ در کدِ محصول دست‌نخورده
 * می‌ماند.
 */
export function backoffDelay(
  attempt: number,
  retryAfterMs?: number,
  random: () => number = Math.random,
): number {
  if (retryAfterMs !== undefined) {
    return Math.min(retryAfterMs, MAX_DELAY_MS)
  }

  const ceiling = Math.min(BASE_DELAY_MS * 2 ** attempt, MAX_DELAY_MS)
  return Math.round(random() * ceiling)
}

/**
 * انتظارِ لغوشدنی.
 *
 * نکته‌ی مهم: اگر کاربر وسطِ انتظارِ بینِ دو تلاش صفحه را عوض کند، نباید ۸
 * ثانیه معطل بمانیم و بعد درخواستِ بی‌مصرف بفرستیم. پس همان لحظه با خطای
 * abort بیرون می‌آییم و شنونده هم پاک می‌شود تا نشتی نماند.
 */
export function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  if (signal?.aborted) return Promise.reject(abortError(signal))

  return new Promise<void>((resolve, reject) => {
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort)
      resolve()
    }, ms)

    function onAbort() {
      clearTimeout(timer)
      reject(abortError(signal))
    }

    signal?.addEventListener('abort', onAbort, { once: true })
  })
}

/** خطای لغو، به شکلی که axios.isCancel هم آن را بشناسد. */
export function abortError(signal?: AbortSignal): Error {
  const reason: unknown = signal?.reason
  if (reason instanceof Error) return reason

  const error = new Error('درخواست لغو شد.')
  error.name = 'CanceledError'
  return error
}
