/** قالب‌بندی اعداد و مبالغ با ارقام فارسی، هماهنگ با بقیه‌ی سامانه. */

export function toPersianDigits(value: string | number): string {
  return String(value).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)])
}

/**
 * ارقام فارسی/عربی را به لاتین برمی‌گرداند.
 *
 * لازم است چون کاربر با صفحه‌کلید فارسی «۰۹۱۲» تایپ می‌کند ولی regexهای
 * اعتبارسنجی و بک‌اند با ارقام لاتین کار می‌کنند. قبل از هر بررسیِ کد ملی یا
 * شماره تلفن باید از این عبور کند.
 */
export function toEnglishDigits(value: string): string {
  return value
    .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
}

/** مبلغ با جداکننده‌ی هزارگان و ارقام فارسی. */
export function formatMoney(amount: number): string {
  return toPersianDigits(Math.round(amount).toLocaleString('en-US'))
}

/** عدد ساده با ارقام فارسی. */
export function formatNumber(value: number): string {
  return toPersianDigits(value.toLocaleString('en-US'))
}

/*
 * تاریخ شمسی سمت کلاینت.
 *
 * سرور برای دادهٔ خودش از morilog/jalali استفاده می‌کند، ولی چیزهایی که
 * فقط در مرورگر زندگی می‌کنند (تاریخچه‌ی ماشین حساب، جستجوهای اخیر) هرگز
 * به سرور نمی‌روند. Intl با تقویم persian همان تبدیل را بدون کتابخانه‌ی
 * اضافه انجام می‌دهد و خودش هم ارقام فارسی می‌دهد.
 */
const jalaliDate = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

const jalaliDateTime = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
})

const clockTime = new Intl.DateTimeFormat('fa-IR', {
  hour: '2-digit',
  minute: '2-digit',
})

export function formatJalaliDate(value: Date | number): string {
  return jalaliDate.format(value)
}

export function formatJalaliDateTime(value: Date | number): string {
  return jalaliDateTime.format(value)
}

export function formatClock(value: Date | number): string {
  return clockTime.format(value)
}

/** «۳ دقیقه پیش» و مانند آن؛ برای چیزهای تازه خواناتر از تاریخ کامل است. */
export function formatRelative(value: Date | number): string {
  const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000)

  if (seconds < 60) return 'همین حالا'
  if (seconds < 3600) return `${toPersianDigits(Math.floor(seconds / 60))} دقیقه پیش`
  if (seconds < 86_400) return `${toPersianDigits(Math.floor(seconds / 3600))} ساعت پیش`
  if (seconds < 604_800) return `${toPersianDigits(Math.floor(seconds / 86_400))} روز پیش`

  return formatJalaliDate(value)
}

/**
 * مبلغ کوتاه‌شده برای محور نمودار — «۱۲.۵ م» به‌جای «۱۲,۵۰۰,۰۰۰».
 * محور با اعداد کامل خیلی شلوغ و ناخوانا می‌شود.
 */
export function formatCompactMoney(amount: number): string {
  const abs = Math.abs(amount)

  if (abs >= 1_000_000_000) return `${toPersianDigits((amount / 1_000_000_000).toFixed(1))} میلیارد`
  if (abs >= 1_000_000) return `${toPersianDigits((amount / 1_000_000).toFixed(1))} م`
  if (abs >= 1_000) return `${toPersianDigits(Math.round(amount / 1_000))} هـ`

  return toPersianDigits(amount)
}
