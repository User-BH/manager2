import { AlertCircle, Loader2, Inbox } from 'lucide-react'

import { ApiError, isRetryable } from '@/shared/lib/apiError'
import { errorUx } from '@/shared/lib/errorUx'

/** اسکلت بارگذاری — به‌جای اسپینر خالی، شکل تقریبی محتوا را نشان می‌دهد. */
export function LoadingState({ rows = 3 }: { rows?: number }) {
  return (
    <div className="flex flex-col gap-3" role="status" aria-label="در حال بارگذاری">
      {Array.from({ length: rows }).map((_, i) => (
        <div
          key={i}
          className="h-16 animate-pulse rounded-2xl"
          style={{ backgroundColor: 'var(--surface-sunken)' }}
        />
      ))}
    </div>
  )
}

export function InlineSpinner() {
  return (
    <div className="flex items-center justify-center py-12">
      <Loader2 size={24} className="animate-spin" style={{ color: 'var(--color-brand-500)' }} />
    </div>
  )
}

/**
 * خطای یک صفحه (مرحلهٔ ۵ · فاز ۶ · بندِ ۵۵: Error UXِ مرکزی).
 *
 * 🔴 پیش از این دو مسیرِ جدا بود: دیالوگ‌ها و توست‌ها از `errorUx` می‌گذشتند
 * و برای هر وضعیت راهنمای خودشان را داشتند، ولی خطای **صفحه** فقط پیامِ خام
 * را نشان می‌داد — با آیکونِ قرمز و دکمه‌ی «تلاش دوباره» برای همه‌چیز. کسی که
 * به صفحه‌ای دسترسی نداشت (۴۰۳) دعوت می‌شد همان کار را تکرار کند.
 *
 * حالا با `error` همان نگاشتِ مرکزی به کار می‌رود:
 * - عنوان از سرور، راهنمای «حالا چه کنم» از `errorUx`؛
 * - «تلاش دوباره» فقط وقتی تکرار ممکن است کمک کند (همان `isRetryable`ِ
 *   لایه‌ی حمل‌ونقل: قطعی، شلوغی، خطای سرور) — نه برای ۴۰۳ و ۴۰۴ و ۴۲۲؛
 * - نشستِ تمام‌شده (۴۰۱) پیوندِ ورود می‌گیرد؛ خودکار نمی‌بَرد (تصمیمِ R61).
 *
 * `message` برای جاهایی می‌ماند که خطا رشته است، نه `ApiError`.
 */
export function ErrorState({
  message,
  error,
  onRetry,
}: {
  message?: string
  error?: unknown
  onRetry?: () => void
}) {
  /*
   * ⚠️ فقط `ApiError` از نگاشت می‌گذرد. خطای دیگر (باگِ خودِ کد، مثلِ
   * TypeError) پیامِ انگلیسیِ درونی دارد و نباید به کاربر برسد — همان
   * رفتارِ `errorMessage`ِ قبلی.
   */
  const ux = error instanceof ApiError ? errorUx(error, 'بارگذاری ممکن نشد.') : null
  const status = error instanceof ApiError ? error.status : null

  const title =
    ux?.title ??
    message ??
    (error === undefined ? 'بارگذاری ممکن نشد.' : 'بارگذاری ممکن نشد. دوباره تلاش کنید.')
  const canRetry = onRetry && (status === null || status === 419 || isRetryable(error))
  const color =
    ux?.icon === 'info'
      ? 'var(--state-info)'
      : ux?.icon === 'warning'
        ? 'var(--color-accent-500)'
        : 'var(--color-danger)'

  return (
    <div
      role="alert"
      className="flex flex-col items-center gap-3 rounded-2xl border p-10 text-center"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      <AlertCircle size={30} style={{ color }} />
      <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
        {title}
      </p>
      {ux?.hint && (
        <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
          {ux.hint}
        </p>
      )}
      {canRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="mt-1 rounded-xl px-4 py-2 text-xs font-semibold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          تلاش دوباره
        </button>
      )}
      {status === 401 && (
        <a
          href="/auth"
          className="mt-1 rounded-xl px-4 py-2 text-xs font-semibold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          ورود دوباره
        </a>
      )}
    </div>
  )
}

export function EmptyState({ message, hint }: { message: string; hint?: string }) {
  return (
    <div className="flex flex-col items-center gap-2 py-12 text-center">
      <Inbox size={28} style={{ color: 'var(--text-tertiary)' }} />
      <p className="text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>
        {message}
      </p>
      {hint && (
        <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
          {hint}
        </p>
      )}
    </div>
  )
}
