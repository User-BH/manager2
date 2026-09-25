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
import { definitelyOffline, markOffline } from '@/shared/pwa/connection'
import { dedupe } from './dedupe'
import { leaveToAuth } from './leaveToAuth'
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
  /** پیشرفتِ ارسالِ بدنه، از ۰ تا ۱ (مرحلهٔ ۵ · فاز ۶). فقط برای آپلود معنا دارد. */
  onUploadProgress?: (fraction: number) => void
}

/**
 * جلوگیری از نوشتنِ محکوم‌به‌شکست وقتی مرورگر قطعاً می‌گوید آفلاینیم (R62).
 *
 * ─── چرا فقط نوشتن ─────────────────────────────────────────────────────────
 * ⚠️ خواندنِ ناموفق فقط یعنی «الان نمی‌بینی». نوشتنِ ناموفق یعنی **کاری
 * که کاربر خواسته انجام نشده** — و اگر بیست ثانیه پشتِ timeout بماند،
 * کاربر یا دوباره می‌زند (و بدترش می‌کند) یا صفحه را می‌بندد و متنش را
 * از دست می‌دهد.
 *
 * شکستِ **فوری با پیامِ روشن** یعنی فرم دست‌نخورده می‌ماند و کاربر
 * می‌داند چرا.
 *
 * ⚠️ فقط به جهتِ منفیِ `navigator.onLine` اعتماد می‌شود. `true`ِ آن پرچم
 * چیزی را ثابت نمی‌کند (مودمی که خودش قطع است هم `true` می‌دهد)، پس
 * هرگز مبنای «بفرست» نیست — فقط مبنای «نفرست».
 */
function refuseWriteWhileOffline(method: Method): void {
  if (method === 'GET' || !definitelyOffline()) return

  markOffline(true)

  throw new ApiError(
    'اتصال اینترنت برقرار نیست. نوشته‌ی شما همین‌جا می‌ماند؛ پس از وصل‌شدن دوباره تلاش کنید.',
    0,
  )
}

function toConfig(path: string, options: RequestOptions, signal?: AbortSignal): AxiosRequestConfig {
  const { method = 'GET', body, onUploadProgress } = options

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
    ...(onUploadProgress
      ? {
          onUploadProgress: (event: { loaded: number; total?: number }) => {
            if (event.total) onUploadProgress(Math.min(1, event.loaded / event.total))
          },
        }
      : {}),
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
      maintenance?: boolean
      requestId?: string
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
      leaveToAuth(payload.message ?? '')
    }

    // حالتِ تعمیر — فقط اگر نقطه‌ی ورود رفتاری ثبت کرده باشد (`onMaintenance`)
    if (payload?.maintenance) maintenanceHandler?.()

    const retryAfterMs = parseRetryAfter(
      axiosError.response?.headers?.['retry-after'] as string | undefined,
    )

    /*
     * شناسه‌ی درخواست، از بدنه یا از هدر (R63).
     *
     * ⚠️ هر دو منبع لازم‌اند: پاسخ‌های خودمان فیلدِ `requestId` دارند، ولی
     * پاسخی که JSON نیست — صفحه‌ی HTMLِ ۵۰۰، یا ۵۰۲ی که پیش از رسیدن به
     * PHP ساخته شده — فقط هدر دارد. و دقیقاً همان دسته است که کاربر
     * درباره‌اش زنگ می‌زند.
     */
    const requestId =
      payload?.requestId ?? (axiosError.response?.headers?.['x-request-id'] as string | undefined)

    // خطای اعتبارسنجی/تجاریِ JSON از لاراول
    if (payload && typeof payload === 'object' && ('message' in payload || 'errors' in payload)) {
      throw new ApiError(
        payload.message ?? 'خطایی رخ داد.',
        status,
        payload.errors ?? {},
        retryAfterMs,
        requestId,
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
      requestId,
    )
  }
}

let maintenanceHandler: (() => void) | null = null

/**
 * واکنش به حالتِ تعمیرِ سرور (مرحلهٔ ۵ · فاز ۹).
 *
 * ⚠️ عمداً پیش‌فرض ندارد و فقط داشبورد ثبتش می‌کند. واکنشِ درست «بارگذاریِ
 * دوباره» است تا سرور صفحه‌ی ۵۰۳ را نشان دهد — ولی صفحه‌های عمومی و ورود
 * در حالتِ تعمیر **باز** می‌مانند؛ اگر آن‌ها هم بار می‌شدند، بارگذاریِ
 * دوباره همان صفحه را می‌آورد و همان درخواست دوباره ۵۰۳ می‌گرفت: حلقه‌ی
 * بی‌پایان. آن‌جا کاربر پیامِ خودِ سرور را می‌بیند.
 */
export function onMaintenance(handler: () => void): void {
  maintenanceHandler = handler
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

  // ⚠️ پیش از هر لایه‌ای: نوشتنِ محکوم‌به‌شکست اصلاً فرستاده نمی‌شود (R62)
  refuseWriteWhileOffline(method)

  const run = (signal: AbortSignal | undefined) => sendWithRetry<T>(path, options, signal)

  // فقط خواندن یکی می‌شود؛ نوشتن هرگز (دو پرداختِ یکسان ممکن است واقعاً دو
  // قصدِ جدا باشند).
  if (method !== 'GET') return run(options.signal)

  return dedupe<T>(`GET ${path}`, (signal) => run(signal), options.signal)
}
