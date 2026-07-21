/**
 * لایه‌ی ارتباط با API لاراول.
 *
 * احراز هویت با نشست و کوکی انجام می‌شود، نه توکن. پس هر درخواستِ
 * تغییردهنده باید توکن CSRF را همراه ببرد؛ توکن از تگ meta در قالب
 * spa.blade.php خوانده می‌شود.
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

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, signal } = options

  const response = await fetch(`/api${path}`, {
    method,
    signal,
    // کوکی نشست باید همراه درخواست برود
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(method === 'GET' ? {} : { 'X-CSRF-TOKEN': csrfToken() }),
    },
    body: body ? JSON.stringify(body) : undefined,
  })

  if (response.status === 204) {
    return undefined as T
  }

  // پاسخ‌های غیر JSON (مثل صفحه‌ی خطای ۵۰۰ یا ریدایرکت به صفحه‌ی ورود)
  // نباید با JSON.parse بترکند و پیام بی‌ربط بدهند.
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

  if (!response.ok) {
    throw new ApiError(
      payload.message ?? 'خطایی رخ داد.',
      response.status,
      payload.errors ?? {},
    )
  }

  return payload as T
}
