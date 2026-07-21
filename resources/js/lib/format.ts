/** قالب‌بندی اعداد و مبالغ با ارقام فارسی، هماهنگ با بقیه‌ی سامانه. */

export function toPersianDigits(value: string | number): string {
  return String(value).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)])
}

/** مبلغ با جداکننده‌ی هزارگان و ارقام فارسی. */
export function formatMoney(amount: number): string {
  return toPersianDigits(Math.round(amount).toLocaleString('en-US'))
}

/** عدد ساده با ارقام فارسی. */
export function formatNumber(value: number): string {
  return toPersianDigits(value.toLocaleString('en-US'))
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
