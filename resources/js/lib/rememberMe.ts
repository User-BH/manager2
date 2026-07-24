/**
 * یادآوریِ سمتِ مرورگر برای «مرا به خاطر بسپار».
 *
 * این جای احرازِ هویت را نمی‌گیرد — آن کار کوکیِ امضاشده‌ی «دستگاه مورداعتماد»
 * است که سرور صادر می‌کند و ۱۰ روز اعتبار دارد. کارِ این فایل فقط راحتیِ
 * کاربر است: شماره و وضعیت تیک را نگه می‌دارد تا دفعه‌ی بعد فرم از پیش پر
 * باشد.
 *
 * چون localStorage خودش انقضا ندارد، تاریخِ انقضا کنار داده ذخیره و هنگام
 * خواندن بررسی می‌شود؛ همان ۱۰ روزی که سمت سرور هم اعمال می‌شود.
 */

const KEY = 'sakena.remember'
const DAYS = 10

interface RememberedLogin {
  phone: string
  expiresAt: number
}

export function saveRememberedPhone(phone: string): void {
  try {
    const payload: RememberedLogin = {
      phone,
      expiresAt: Date.now() + DAYS * 24 * 60 * 60 * 1000,
    }
    localStorage.setItem(KEY, JSON.stringify(payload))
  } catch {
    // حالت خصوصیِ مرورگر یا پر بودن فضا؛ یادآوری صرفاً یک راحتی است
  }
}

/** شماره‌ی به‌خاطرسپرده، یا null اگر نبود یا منقضی شده باشد. */
export function loadRememberedPhone(): string | null {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return null

    const payload = JSON.parse(raw) as RememberedLogin
    if (!payload?.phone || typeof payload.expiresAt !== 'number') return null

    if (Date.now() > payload.expiresAt) {
      localStorage.removeItem(KEY)
      return null
    }

    return payload.phone
  } catch {
    return null
  }
}

export function forgetRememberedPhone(): void {
  try {
    localStorage.removeItem(KEY)
  } catch {
    // بی‌اهمیت
  }
}
