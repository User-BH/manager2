/**
 * پالایه‌ی ورودیِ فرم‌ها.
 *
 * هدف این است که نویسه‌ی نامجاز اصلاً وارد فیلد نشود (نه اینکه بعد رد شود)،
 * و اگر کاربر چیزی خلاف الگو تایپ کرد، یک پیام کوتاه دلیلش را بگوید.
 *
 * هر پالایه دو چیز برمی‌گرداند: مقدارِ پاک‌شده، و اینکه آیا چیزی حذف شد
 * (تا پیام هشدار نشان داده شود).
 */

export interface FilterResult {
  value: string
  changed: boolean
  /**
   * پیامِ ویژه‌ی همین تغییر (مثلاً «بیش از ۱۱ رقم مجاز نیست»)، که جای پیامِ
   * پیش‌فرضِ فیلد را می‌گیرد. اگر تعیین نشود، همان پیامِ پیش‌فرض نشان داده می‌شود.
   */
  hint?: string
}

/** ارقام فارسی و عربی را به لاتین برمی‌گرداند. */
export function toAsciiDigits(input: string): string {
  const map: Record<string, string> = {
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
    '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
    '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
  }
  return input.replace(/[۰-۹٠-٩]/g, (d) => map[d] ?? d)
}

function build(value: string, cleaned: string): FilterResult {
  return { value: cleaned, changed: cleaned !== value }
}

/**
 * فقط حروف فارسی، فاصله و نیم‌فاصله (برای نام و نام خانوادگی).
 *
 * ابتدا هر چیزِ غیرِ خط عربی حذف می‌شود، سپس ارقام (که داخل همان بازه‌اند)
 * هم برداشته می‌شوند تا فقط حرف بماند.
 */
export function filterPersianLetters(value: string): FilterResult {
  const cleaned = value
    .replace(/[^؀-ۿ\s‌]/g, '')
    .replace(/[٠-٩۰-۹]/g, '')
  return build(value, cleaned)
}

/** حروف فارسی به‌علاوه‌ی اعداد و فاصله (برای نام مجتمع). */
export function filterPersianAlphanumeric(value: string): FilterResult {
  const cleaned = value.replace(/[^؀-ۿ\s‌0-9]/g, '')
  return build(value, cleaned)
}

/**
 * فقط رقم؛ ارقام فارسی به لاتین تبدیل و حداکثر ۱۱ رقم (برای شماره موبایل).
 *
 * تبدیلِ ارقام فارسی به لاتین «تغییرِ نامجاز» حساب نمی‌شود، پس بابتش هیچ
 * هشداری نمی‌آید. تنها دو حالت هشدار دارد: نویسه‌ی غیرعددی («فقط عدد») و
 * بیشتر از ۱۱ رقم («بیش از ۱۱ رقم»)، تا پیام دقیقاً با کارِ کاربر بخواند.
 */
export function filterMobile(value: string): FilterResult {
  const ascii = toAsciiDigits(value)
  const digits = ascii.replace(/\D/g, '')
  const cleaned = digits.slice(0, 11)

  const hadNonDigit = digits.length !== ascii.length
  const tooLong = digits.length > 11

  if (!hadNonDigit && !tooLong) {
    // فقط نرمال‌سازیِ ارقام یا بدون تغییر — هشداری لازم نیست
    return { value: cleaned, changed: false }
  }

  return {
    value: cleaned,
    changed: true,
    hint: hadNonDigit ? filterHints.mobile : 'شماره موبایل بیش از ۱۱ رقم نیست.',
  }
}

/**
 * فقط نویسه‌های چاپیِ لاتین: حروف انگلیسی، رقم و نماد (برای رمز عبور).
 *
 * حرف فارسی/عربی و هر نویسه‌ی غیرِ ASCII حذف می‌شود، چون رمزی که فارسی
 * دارد روی کیبوردهای مختلف دردسر می‌سازد.
 */
export function filterAsciiPassword(value: string): FilterResult {
  const cleaned = value.replace(/[^\x20-\x7E]/g, '')
  return build(value, cleaned)
}

/** فقط رقم برای کد تایید. */
export function filterOtp(value: string, length = 6): FilterResult {
  const cleaned = toAsciiDigits(value).replace(/\D/g, '').slice(0, length)
  return build(value, cleaned)
}

/** پیام کوتاهی که هنگام حذفِ نویسه‌ی نامجاز نشان داده می‌شود. */
export const filterHints = {
  persianLetters: 'فقط حروف فارسی مجاز است.',
  persianAlphanumeric: 'فقط حروف فارسی و اعداد مجاز است.',
  mobile: 'فقط عدد وارد کنید.',
  asciiPassword: 'رمز فقط با حروف انگلیسی، عدد و نماد.',
  otp: 'فقط عدد وارد کنید.',
} as const
