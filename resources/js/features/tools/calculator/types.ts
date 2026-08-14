/**
 * تکه‌ای از ماشین‌حساب که از `CalculatorPage.tsx` بیرون کشیده شد (R39 · آیتم ⑤).
 *
 * ⚠️ این جابه‌جایی عمداً **هیچ منطقی را عوض نمی‌کند**: فایلِ اصلی ۹۶۸ خط بود
 * و همه‌ی زیرکامپوننت‌هایش را هم داخلِ خودش داشت. تجزیه از قبل انجام شده
 * بود؛ چیزی که نبود، مرزِ فایلی بود.
 */

import type { AngleMode } from '@/shared/lib/calculator'

export interface HistoryEntry {
  id: string
  expression: string
  result: string
  angleMode: AngleMode
  /** میلی‌ثانیه‌ی یونیکس؛ تاریخ شمسی هنگام نمایش ساخته می‌شود. */
  at: number
}

export interface KeySpec {
  label: string
  /** متنی که به عبارت اضافه می‌شود؛ اگر نبود از label استفاده می‌شود. */
  insert?: string
  variant?: 'function' | 'operator' | 'digit' | 'danger' | 'equals'
  title?: string
}

/** کلیدهای علمی؛ ردیف اول با دکمه‌ی 2nd جای خود را به معکوس‌ها می‌دهد. */
