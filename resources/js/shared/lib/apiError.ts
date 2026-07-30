/**
 * خطای API و طبقه‌بندیِ «قابلِ تلاشِ دوباره».
 *
 * این فایل عمداً از axios و React مستقل است تا هم لایه‌ی حمل‌ونقل و هم
 * لایه‌ی کش (TanStack Query در R6) از **یک** منبعِ حقیقت برای تصمیمِ
 * «دوباره تلاش کنم یا نه؟» استفاده کنند. اگر این دو جدا باشند، دیر یا زود
 * سیاستشان از هم واگرا می‌شود.
 */

/** متدهایی که تکرارشان اثرِ جانبیِ دوباره ندارد (RFC 9110). */
const IDEMPOTENT_METHODS = new Set(['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'])

/**
 * وضعیت‌هایی که «گذرا» شمرده می‌شوند: سرور شلوغ/موقتاً خراب است، نه اینکه
 * درخواستِ ما بد باشد.
 *
 * ۴۰۸ (timeout درخواست) و ۴۲۹ (محدودیت نرخ) هم اینجا هستند چون هرچند 4xx‌اند،
 * تکرارِ بعد از مکث معنادار است.
 */
const TRANSIENT_STATUSES = new Set([408, 425, 429, 500, 502, 503, 504])

export class ApiError extends Error {
  status: number
  /** خطاهای اعتبارسنجی لاراول: { phone: ['...'], password: ['...'] } */
  errors: Record<string, string[]>
  /**
   * اگر سرور هدر `Retry-After` داده باشد، فاصله‌ی خواسته‌شده به میلی‌ثانیه.
   *
   * وقتی سرور صریحاً می‌گوید «۳۰ ثانیه دیگر بیا»، محاسبه‌ی backoff خودمان را
   * نادیده می‌گیریم و به حرفِ او عمل می‌کنیم — این تفاوتِ یک کلاینتِ مؤدب با
   * کلاینتی است که سرورِ زیرِ فشار را بیشتر می‌کوبد.
   */
  retryAfterMs?: number

  constructor(
    message: string,
    status: number,
    errors: Record<string, string[]> = {},
    retryAfterMs?: number,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
    this.retryAfterMs = retryAfterMs
  }

  /** اولین پیام خطای یک فیلد، برای نشاندن زیر همان ورودی در فرم. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

/**
 * آیا این خطا ارزشِ تلاشِ دوباره دارد؟
 *
 * دو شرط باید هم‌زمان برقرار باشد:
 *
 * ۱. خطا گذرا باشد — قطعیِ شبکه (status = 0) یا یکی از وضعیت‌های گذرا.
 *    ۴۲۲ (اعتبارسنجی)، ۴۰۱، ۴۰۳ و ۴۰۴ هرگز؛ تکرارشان همان جواب را می‌دهد و
 *    فقط سرور را الکی می‌زند. **این همان جایی است که پیش‌فرضِ TanStack Query
 *    اشتباه است** و در R6 با همین تابع اصلاح می‌شود.
 *
 * ۲. متد idempotent باشد. یک `POST /payments` که وسطِ راه timeout شده ممکن است
 *    روی سرور **انجام شده باشد**؛ تکرارش پرداختِ دوم می‌سازد. برای POST/PATCH
 *    تلاشِ دوباره تصمیمِ لایه‌ی بالاتر است، نه خودکار.
 *
 * ۴۱۹ عمداً بیرون است: نشانه‌ی توکنِ کهنه‌ی CSRF است و `api.ts` خودش یک‌بار
 * توکن را نو می‌کند و درخواست را می‌فرستد. اگر اینجا هم retryable بود، دو
 * مکانیزم روی هم می‌افتادند.
 */
export function isRetryable(error: unknown, method = 'GET'): boolean {
  if (!IDEMPOTENT_METHODS.has(method.toUpperCase())) return false
  if (!(error instanceof ApiError)) return false

  // status صفر یعنی پاسخی نرسید: قطعیِ شبکه یا timeout
  return error.status === 0 || TRANSIENT_STATUSES.has(error.status)
}

/**
 * هدر `Retry-After` را به میلی‌ثانیه تبدیل می‌کند.
 *
 * دو قالب دارد (RFC 9110): تعداد ثانیه، یا یک تاریخِ HTTP. هر دو پشتیبانی
 * می‌شوند. مقدارِ بی‌معنا (منفی یا غیرقابل‌تفسیر) نادیده گرفته می‌شود تا یک
 * هدرِ خراب باعث انتظارِ بی‌پایان نشود.
 */
export function parseRetryAfter(
  header: string | null | undefined,
  nowMs: number = Date.now(),
): number | undefined {
  if (!header) return undefined

  const seconds = Number(header)
  if (Number.isFinite(seconds)) {
    return seconds > 0 ? seconds * 1000 : undefined
  }

  const dateMs = Date.parse(header)
  if (Number.isNaN(dateMs)) return undefined

  const delta = dateMs - nowMs
  return delta > 0 ? delta : undefined
}
