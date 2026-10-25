import { z } from 'zod/mini'
import type { FilterResult } from './inputFilters'

/**
 * نامِ فارسیِ یک شخص — یک قاعده برای ثبت‌نام، پروفایل و فرمِ بازخورد.
 *
 * همتای `App\Support\PersianName` در سرور (مرحلهٔ ۹ · فاز ۳). تا پیش از این فقط
 * فرمِ بازخورد آن را داشت (مرحلهٔ ۸ · فاز ۵)؛ ثبت‌نام `filterPersianLetters` ِ
 * مشترک را داشت که کلِ بلوکِ عربی — «،»، «؛»، «؟»، «٪» و اعراب — را راه می‌داد.
 *
 * ⚠️ فایلِ جدا و نه `inputFilters`: آن در بارگذاریِ اولِ صفحه‌ی پشتیبانی هست.
 *
 * ⚠️ فرمِ بازخورد عمداً **نسخه‌ی خودش** را در `feedbackSchema` دارد: اشتراکِ
 * همین فایل یک چانکِ ‎۷۰۶ بایتیِ مشترک ساخت و صفحه‌ی پشتیبانی از سقفِ بودجه‌ی
 * باندل و تعدادِ فایل گذشت (اندازه‌گیری‌شده). هم‌سانیِ دو نسخه را
 * `personName.test.ts` می‌سنجد، و سرور فقط یک قاعده دارد (`PersianName`).
 *
 * ⚠️ `zod/mini` و نه `zod` (به‌روزرسانیِ امنیتیِ zod ۴.۶.۶، ۲۰۲۶-۱۰-۲۵): این فایل در بارگذاریِ اولِ صفحه است و
 * نسخه‌ی کاملِ zod از ۴.۵ به بعد مولدِ JSON Schema و پیام‌های انگلیسی را همیشه با خود می‌آورد (حدودِ ۶٫۵ کیلوبایتِ
 * gzip). قاعده‌ها و پیام‌ها عیناً همان‌اند؛ صفحه‌های تنبل همچنان `zod` ِ کامل را به کار می‌برند.
 */
export const PERSIAN_NAME_PATTERN = /^[ء-غف-يپچژکگی‌ ]+$/

export const PERSIAN_NAME_HINT = 'نام فقط با حروف فارسی؛ عدد، حرفِ انگلیسی و نماد پذیرفته نیست.'

/** سقفِ نام یا نام خانوادگی — همان `PersianName::PART_MAX` */
export const NAME_PART_MAX = 60

/**
 * فقط **حرف**، فاصله و نیم‌فاصله؛ «ي/ك/ى» ِ عربی به «ی/ک» ِ فارسی برمی‌گردند
 * (تغییرِ نامجاز حساب نمی‌شود) و فاصله‌ی پشتِ‌هم یکی می‌شود.
 */
export function filterPersianName(value: string): FilterResult {
  const normalized = value.replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
  const cleaned = normalized
    .replace(/[^ء-غف-يپچژکگی‌ ]/g, '')
    .replace(/ {2,}/g, ' ')
    .replace(/^ /, '')

  // فاصله‌ی تکراری و حرفِ عربی خطا نیستند؛ فقط نویسه‌ی حذف‌شده هشدار دارد
  const removed = normalized.replace(/ {2,}/g, ' ').replace(/^ /, '') !== cleaned
  return removed
    ? { value: cleaned, changed: true, hint: PERSIAN_NAME_HINT }
    : { value: cleaned, changed: false }
}

/** یک بخشِ نامِ شخص، با همان قاعده‌ی سرور (`PersianName::rules`) */
export function personNamePart(label: 'نام' | 'نام خانوادگی') {
  return z
    .string()
    .check(
      z.trim(),
      z.minLength(1, `${label} را وارد کنید.`),
      z.minLength(2, `${label} دست‌کم ۲ حرف است.`),
      z.maxLength(NAME_PART_MAX, `${label} بیش از ${NAME_PART_MAX} حرف است.`),
      z.regex(PERSIAN_NAME_PATTERN, `${label} فقط با حروف فارسی نوشته شود.`),
    )
}
