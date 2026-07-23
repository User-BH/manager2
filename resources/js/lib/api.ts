/**
 * لایه‌ی ارتباط با API لاراول.
 *
 * احراز هویت با نشست و کوکی انجام می‌شود، نه توکن. پس هر درخواستِ
 * تغییردهنده باید توکن CSRF را همراه ببرد.
 */

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
let csrfToken =
  document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''

export function setCsrfToken(token: string | undefined | null): void {
  if (token) csrfToken = token
}

async function refreshCsrfToken(): Promise<void> {
  try {
    const response = await fetch('/api/csrf-token', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    if (!response.ok) return

    const payload = await response.json()
    setCsrfToken(payload.csrfToken)
  } catch {
    // اگر شبکه هم قطع باشد، خطای اصلی به تماس‌گیرنده برمی‌گردد
  }
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
}

async function send(path: string, options: RequestOptions): Promise<Response> {
  const { method = 'GET', body, signal } = options

  // آپلود فایل باید به‌صورت FormData برود. در آن حالت Content-Type را خودمان
  // ست نمی‌کنیم تا مرورگر boundary درست را اضافه کند.
  const isFormData = body instanceof FormData

  return fetch(`/api${path}`, {
    method,
    signal,
    // کوکی نشست باید همراه درخواست برود
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(body && !isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...(method === 'GET' ? {} : { 'X-CSRF-TOKEN': csrfToken }),
    },
    body: isFormData ? (body as FormData) : body ? JSON.stringify(body) : undefined,
  })
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  let response = await send(path, options)

  // ۴۱۹ یعنی توکن کهنه شده (معمولاً بعد از ورود یا انقضای نشست). یک‌بار توکن
  // را تازه می‌کنیم و دوباره می‌فرستیم تا کاربر مجبور به رفرش دستی نشود.
  if (response.status === 419) {
    await refreshCsrfToken()
    response = await send(path, options)
  }

  if (response.status === 204) {
    return undefined as T
  }

  // پاسخ‌های غیر JSON (مثل صفحه‌ی خطای ۵۰۰) نباید با JSON.parse بترکند و
  // پیام بی‌ربط بدهند.
  const contentType = response.headers.get('content-type') ?? ''
  if (!contentType.includes('application/json')) {
    if (!response.ok) {
      throw new ApiError(
        response.status === 419
          ? 'نشست شما منقضی شده است. صفحه را تازه کنید.'
          : 'پاسخ نامعتبر از سرور دریافت شد.',
        response.status,
      )
    }
    return undefined as T
  }

  const payload = await response.json()

  // هر پاسخی که توکن تازه دارد، نسخه‌ی محلی را به‌روز می‌کند
  setCsrfToken(payload?.csrfToken)

  if (!response.ok) {
    /*
     * حساب کاربر وسط نشست غیرفعال شده. سرور نشست را همان‌جا بسته، پس هر
     * درخواست بعدی هم رد می‌شود و ماندن روی صفحه‌ی داشبورد فقط خطا پشت خطا
     * تولید می‌کند. یک ناوبری کامل (نه ناوبری SPA) هم کاربر را به صفحه‌ی
     * ورود می‌برد و هم کل حالتِ درون‌حافظه‌ای را پاک می‌کند.
     */
    if (payload?.accountDisabled) {
      window.location.href = `/auth?reason=${encodeURIComponent(payload.message ?? '')}`
    }

    throw new ApiError(
      payload.message ?? 'خطایی رخ داد.',
      response.status,
      payload.errors ?? {},
    )
  }

  return payload as T
}
