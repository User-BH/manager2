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

/**
 * نگهبانِ زمانِ اجرا برای تاریخچه‌ای که از `localStorage` می‌آید.
 *
 * ─── 🔴 چرا لازم است ───────────────────────────────────────────────────────
 * ⚠️ `HistoryEntry` فقط در زمانِ کامپایل وجود دارد. آنچه واقعاً از حافظهٔ
 * مرورگر بیرون می‌آید هر چیزی می‌تواند باشد: نسخهٔ قدیمیِ برنامه، ویرایشِ
 * دستیِ کاربر، یا افزونه‌ای که همان کلید را نوشته.
 *
 * بدونِ این بررسی، `history.map(...)` روی چیزی که آرایه نیست اجرا می‌شد و
 * صفحه سفید می‌ماند.
 */
export function isHistory(value: unknown): value is HistoryEntry[] {
  return (
    Array.isArray(value) &&
    value.every((row) => {
      if (typeof row !== 'object' || row === null) return false

      const entry = row as Record<string, unknown>

      return (
        typeof entry.id === 'string' &&
        typeof entry.expression === 'string' &&
        typeof entry.result === 'string' &&
        typeof entry.at === 'number'
      )
    })
  )
}

/** ⚠️ حافظهٔ ماشین‌حساب باید عددِ متناهی باشد؛ `NaN` و `Infinity` هم رد می‌شوند. */
export function isMemory(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value)
}
