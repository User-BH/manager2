/**
 * نقطه‌ی ورودِ همه‌ی درخواست‌های API.
 *
 * احراز هویت با نشست و کوکی انجام می‌شود، نه توکن. پس هر درخواستِ تغییردهنده
 * باید توکن CSRF را همراه ببرد. کلِ برنامه از همین `api` استفاده می‌کند، پس
 * همه‌ی درخواست‌ها از میانِ همین مسیر می‌گذرند.
 *
 * ─── ترتیبِ لایه‌ها (از بیرون به درون) ─────────────────────────────────────
 *
 *   api()
 *     └─ dedupe        فقط GET — درخواست‌های هم‌زمانِ یکسان یکی می‌شوند
 *         └─ retry     فقط خطای گذرا و متدِ idempotent، با backoff + jitter
 *             └─ csrf  ۴۱۹ ⇒ یک‌بار توکن نو + یک تلاشِ دوباره
 *                 └─ http   نمونه‌ی axios (timeout، کوکی، هدر)
 *
 * چرا dedupe بیرون از retry است؟ چون اگر برعکس بود، هر تلاشِ دوباره کلیدِ
 * تازه‌ای می‌ساخت و اشتراکِ مصرف‌کننده‌ها می‌شکست. با این ترتیب، چند مصرف‌کننده
 * یک درخواست را می‌بینند و آن یک درخواست خودش تلاش‌های دوباره‌اش را مدیریت
 * می‌کند.
 *
 * این ماژول نمای عمومی است و بقیه‌ی برنامه فقط همین را می‌شناسد؛ `http.ts`،
 * `retry.ts` و `dedupe.ts` جزئیاتِ درونی‌اند.
 */

import axios, { type AxiosError, type AxiosRequestConfig } from 'axios'

import { ApiError, isRetryable, parseRetryAfter } from './apiError'
import { dedupe } from './dedupe'
import { MAX_ATTEMPTS, backoffDelay, sleep } from './retry'
import { UPLOAD_TIMEOUT_MS, http, refreshCsrfToken, setCsrfToken } from './http'

export { ApiError } from './apiError'
export { setCsrfToken } from './http'
export { isRetryable } from './apiError'

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface RequestOptions {
  method?: Method
  body?: unknown
  signal?: AbortSignal
}

function toConfig(path: string, options: RequestOptions, signal?: AbortSignal): AxiosRequestConfig {
  const { method = 'GET', body } = options

  // آپلود فایل به‌صورت FormData می‌رود؛ در آن حالت Content-Type را دست نمی‌زنیم
  // تا axios خودش boundary درست را بگذارد.
  const isFormData = body instanceof FormData

  return {
    url: path,
    method,
    signal,
    data: body,
    headers: body && !isFormData ? { 'Content-Type': 'application/json' } : undefined,
    ...(isFormData ? { timeout: UPLOAD_TIMEOUT_MS } : {}),
  }
}

/** یک رفت‌وبرگشتِ تکی، به‌همراه منطقِ تازه‌کردنِ توکن CSRF. */
async function send<T>(
  path: string,
  options: RequestOptions,
  signal: AbortSignal | undefined,
  csrfRetried: boolean,
): Promise<T> {
  try {
    const response = await http.request(toConfig(path, options, signal))

    // هر پاسخی که توکن تازه دارد، نسخه‌ی محلی را به‌روز می‌کند
    setCsrfToken((response.data as { csrfToken?: string })?.csrfToken)

    // ۲۰۴ یا بدنه‌ی خالی
    if (response.status === 204 || response.data === '' || response.data == null) {
      return undefined as T
    }

    return response.data as T
  } catch (error) {
    // لغو درخواست (unmount یا AbortController) را دست‌نخورده رد می‌کنیم
    if (axios.isCancel(error)) throw error

    const axiosError = error as AxiosError<{
      message?: string
      errors?: Record<string, string[]>
      csrfToken?: string
      accountDisabled?: boolean
    }>

    const status = axiosError.response?.status ?? 0

    // ۴۱۹ یعنی توکن کهنه شده (معمولاً بعد از ورود یا انقضای نشست). یک‌بار توکن
    // را تازه می‌کنیم و دوباره می‌فرستیم تا کاربر مجبور به رفرش دستی نشود.
    if (status === 419 && !csrfRetried) {
      await refreshCsrfToken()
      return send<T>(path, options, signal, true)
    }

    const payload = axiosError.response?.data
    setCsrfToken(payload?.csrfToken)

    /*
     * حساب کاربر وسط نشست غیرفعال شده. سرور نشست را همان‌جا بسته، پس یک ناوبری
     * کامل (نه SPA) کاربر را به صفحه‌ی ورود می‌برد و حالتِ درون‌حافظه‌ای را هم
     * پاک می‌کند.
     */
    if (payload?.accountDisabled) {
      window.location.href = `/auth?reason=${encodeURIComponent(payload.message ?? '')}`
    }

    const retryAfterMs = parseRetryAfter(
      axiosError.response?.headers?.['retry-after'] as string | undefined,
    )

    // خطای اعتبارسنجی/تجاریِ JSON از لاراول
    if (payload && typeof payload === 'object' && ('message' in payload || 'errors' in payload)) {
      throw new ApiError(
        payload.message ?? 'خطایی رخ داد.',
        status,
        payload.errors ?? {},
        retryAfterMs,
      )
    }

    // پاسخ غیرJSON (مثل صفحه‌ی خطای ۵۰۰) یا قطع شبکه
    throw new ApiError(
      status === 419
        ? 'نشست شما منقضی شده است. صفحه را تازه کنید.'
        : status
          ? 'پاسخ نامعتبر از سرور دریافت شد.'
          : 'ارتباط با سرور برقرار نشد.',
      status,
      {},
      retryAfterMs,
    )
  }
}

/** حلقه‌ی تلاشِ دوباره. تصمیمِ «آیا تلاش کنم؟» یک‌جا در `isRetryable` است. */
async function sendWithRetry<T>(
  path: string,
  options: RequestOptions,
  signal: AbortSignal | undefined,
): Promise<T> {
  const method = options.method ?? 'GET'

  for (let attempt = 0; ; attempt++) {
    try {
      return await send<T>(path, options, signal, false)
    } catch (error) {
      if (axios.isCancel(error)) throw error

      const isLastAttempt = attempt >= MAX_ATTEMPTS - 1
      if (isLastAttempt || !isRetryable(error, method)) throw error

      const retryAfterMs = error instanceof ApiError ? error.retryAfterMs : undefined
      await sleep(backoffDelay(attempt, retryAfterMs), signal)
    }
  }
}

/**
 * درخواست به API.
 *
 * امضای این تابع عمداً از R1 تا حالا عوض نشده تا ۴۲ فایلی که صدایش می‌زنند
 * دست‌نخورده بمانند؛ همه‌ی قابلیت‌های تازه در لایه‌های درونی اضافه شده‌اند.
 */
export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET'

  const run = (signal: AbortSignal | undefined) => sendWithRetry<T>(path, options, signal)

  // فقط خواندن یکی می‌شود؛ نوشتن هرگز (دو پرداختِ یکسان ممکن است واقعاً دو
  // قصدِ جدا باشند).
  if (method !== 'GET') return run(options.signal)

  return dedupe<T>(`GET ${path}`, (signal) => run(signal), options.signal)
}
