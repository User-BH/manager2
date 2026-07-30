/**
 * لایه‌ی ارتباط با API لاراول (بر پایه‌ی axios).
 *
 * احراز هویت با نشست و کوکی انجام می‌شود، نه توکن. پس هر درخواستِ تغییردهنده
 * باید توکن CSRF را همراه ببرد. کلِ برنامه از همین `api` استفاده می‌کند، پس
 * همه‌ی درخواست‌ها از میانِ همین نمونه‌ی axios می‌گذرند.
 */

import axios, { AxiosError, type AxiosInstance, type AxiosRequestConfig } from 'axios'

export class ApiError extends Error {
  status: number
  /** خطاهای اعتبارسنجی لاراول: { phone: ['...'], password: ['...'] } */
  errors: Record<string, string[]>

  constructor(message: string, status: number, errors: Record<string, string[]> = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }

  /** اولین پیام خطای یک فیلد، برای نشاندن زیر همان ورودی در فرم. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

/*
 * توکن CSRF در متغیر نگه داشته می‌شود، نه اینکه هر بار از متاتگ خوانده شود.
 *
 * دلیلش مهم است: هنگام ورود، لاراول نشست را regenerate می‌کند و توکن CSRF هم
 * عوض می‌شود. چون این یک SPA است و صفحه رفرش نمی‌شود، متاتگ همان توکن قدیمی
 * را نگه می‌دارد و اولین درخواست نوشتنیِ بعد از ورود با ۴۱۹ رد می‌شد.
 */
let csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''

export function setCsrfToken(token: string | undefined | null): void {
  if (token) csrfToken = token
}

const http: AxiosInstance = axios.create({
  baseURL: '/api',
  // کوکی نشست باید همراه هر درخواست برود
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  // ما خودمان بر اساس بدنه‌ی پاسخ تصمیم می‌گیریم، پس همه‌ی وضعیت‌ها را می‌گیریم
  // و در catch نگاشتشان می‌کنیم به ApiError.
})

// توکن CSRF فقط روی درخواست‌های تغییردهنده لازم است
http.interceptors.request.use((config) => {
  const method = (config.method ?? 'get').toUpperCase()
  if (method !== 'GET') {
    config.headers.set('X-CSRF-TOKEN', csrfToken)
  }
  return config
})

async function refreshCsrfToken(): Promise<void> {
  try {
    const { data } = await http.get<{ csrfToken?: string }>('/csrf-token')
    setCsrfToken(data?.csrfToken)
  } catch {
    // اگر شبکه هم قطع باشد، خطای اصلی به تماس‌گیرنده برمی‌گردد
  }
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
}

function toConfig(path: string, options: RequestOptions): AxiosRequestConfig {
  const { method = 'GET', body, signal } = options

  // آپلود فایل به‌صورت FormData می‌رود؛ در آن حالت Content-Type را دست نمی‌زنیم
  // تا axios خودش boundary درست را بگذارد.
  const isFormData = body instanceof FormData

  return {
    url: path,
    method,
    signal,
    data: body,
    headers: body && !isFormData ? { 'Content-Type': 'application/json' } : undefined,
  }
}

async function request<T>(path: string, options: RequestOptions, retried: boolean): Promise<T> {
  try {
    const response = await http.request(toConfig(path, options))

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
    if (status === 419 && !retried) {
      await refreshCsrfToken()
      return request<T>(path, options, true)
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

    // خطای اعتبارسنجی/تجاریِ JSON از لاراول
    if (payload && typeof payload === 'object' && ('message' in payload || 'errors' in payload)) {
      throw new ApiError(payload.message ?? 'خطایی رخ داد.', status, payload.errors ?? {})
    }

    // پاسخ غیرJSON (مثل صفحه‌ی خطای ۵۰۰) یا قطع شبکه
    throw new ApiError(
      status === 419
        ? 'نشست شما منقضی شده است. صفحه را تازه کنید.'
        : status
          ? 'پاسخ نامعتبر از سرور دریافت شد.'
          : 'ارتباط با سرور برقرار نشد.',
      status,
    )
  }
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  return request<T>(path, options, false)
}
