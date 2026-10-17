import { useAuthStore } from '@/shared/stores/authStore'
import type { CurrentUser, RecentSearch } from '@/shared/types'

/**
 * «جست‌وجوهای اخیر» در مرورگر — کلید، خواندنِ بررسی‌شده، نوشتن و پاک‌سازی.
 *
 * از `searchStore` جدا شد (مرحلهٔ ۱۲ · فاز ۸): استور سقفِ اندازه دارد (`stateBoundaries`) و این بخش با پاک‌سازیِ پایانِ
 * نشست (بندِ ۵۷) از آن گذشت. فقط `searchStore` واردش می‌کند، پس همراهِ همان چانکِ داشبورد است — نه مسیرِ همه‌ی صفحه‌ها.
 */

const RECENT_KEY = 'app:recent-searches'

/**
 * ⚠️ جست‌وجوهای اخیر از `localStorage` می‌آیند — یعنی دادهٔ **خارجی**.
 *
 * ─── 🔴 چه چیزی باز بود (مرحلهٔ ۵، فاز ۳) ─────────────────────────────────
 * نسخهٔ قبلی `JSON.parse(raw) as RecentSearch[]` بود: یک ادعا، نه یک
 * بررسی. هر چیزی که در آن کلید می‌نشست مستقیم وارد رندر می‌شد.
 *
 * ⚠️ و نکتهٔ گویا این بود که `rememberMe.ts` — درست کنارِ همین فایل —
 * **بررسی می‌کرد**. یعنی الگوی درست در پروژه وجود داشت و اینجا رعایت
 * نشده بود.
 */
export function isRecent(value: unknown): value is RecentSearch[] {
  return (
    Array.isArray(value) &&
    value.every((row) => {
      if (typeof row !== 'object' || row === null) return false

      const item = row as Record<string, unknown>

      return (
        typeof item.query === 'string' &&
        typeof item.total === 'number' &&
        typeof item.at === 'number'
      )
    })
  )
}

/**
 * کلیدِ تاریخچه برای **همین حساب در همین مجتمع** (مرحلهٔ ۱۰ · فاز ۸؛ بندهای ۸۲ و ۸۳ ِ فایلِ معماری).
 *
 * ⚠️ تا ۱۰/۸ یک کلیدِ سراسری بود: «علی احمدی — ۳ نتیجه» که مدیرِ مجتمعِ الف جست‌وجو کرده بود در مجتمعِ ب
 * هم دیده می‌شد، و برای حسابِ بعدی روی همان مرورگر هم. عبارت و شمارِ نتیجه خودشان داده‌ی آن مجتمع‌اند.
 * بی کاربر (پیش از ورود) هیچ چیز خوانده یا نوشته نمی‌شود.
 */
export function recentKey(user: CurrentUser | null): string | null {
  return user ? `${RECENT_KEY}:${user.id}:${user.complex?.id ?? 0}` : null
}

export function readRecent(): RecentSearch[] {
  const key = recentKey(useAuthStore.getState().user)
  if (!key) return []

  try {
    const raw = window.localStorage.getItem(key)
    if (!raw) return []

    const parsed: unknown = JSON.parse(raw)

    // 🔴 دادهٔ نامعتبر یعنی «تاریخچه‌ای نداریم»، نه «هرچه بود را نشان بده»
    return isRecent(parsed) ? parsed : []
  } catch {
    return []
  }
}

/** همه‌ی تاریخچه‌ی یک حساب روی این مرورگر — هر مجتمعی */
export function forgetRecent(userId: number): void {
  try {
    const prefix = `${RECENT_KEY}:${userId}:`
    Object.keys(window.localStorage)
      .filter((key) => key.startsWith(prefix))
      .forEach((key) => window.localStorage.removeItem(key))
  } catch {
    // فضای ذخیره‌سازی در دسترس نیست؛ چیزی هم نمانده
  }
}

export function writeRecent(list: RecentSearch[]): void {
  const key = recentKey(useAuthStore.getState().user)
  if (!key) return

  try {
    window.localStorage.setItem(key, JSON.stringify(list))
  } catch {
    // فضای ذخیره‌سازی در دسترس نیست؛ بی‌خطر
  }
}

// تاریخچه‌ی بی‌صاحبِ نسخه‌های پیش از ۱۰/۸ — معلوم نیست مالِ کدام حساب و مجتمع بود
try {
  window.localStorage.removeItem(RECENT_KEY)
} catch {
  // فضای ذخیره‌سازی در دسترس نیست؛ بی‌خطر
}
