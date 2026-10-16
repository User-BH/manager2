import type { AxiosError, AxiosInstance } from 'axios'

/**
 * زمینه‌ی مجتمعِ همین نشست، از سرور: «شناسه‌ی مجتمع:نقش» (مرحلهٔ ۱۲ · فاز ۱؛ `SetCurrentComplex`).
 *
 * زمینه از ۱۲/۱ مالِ نشست است و سرور می‌تواند جابه‌جایش کند — عضویتِ این مجتمع پایان یافت یا معلق شد، یا
 * درخواستِ عضویتِ حسابِ اولیه تأیید شد. رابطی که هنوز مجتمعِ قبلی را نشان می‌دهد نباید پاسخِ مجتمعِ تازه را
 * زیرِ کلیدهای قبلی بگذارد؛ `main.tsx` ناهم‌خوانی را با ورودِ کامل به زمینه‌ی تازه پاسخ می‌دهد.
 *
 * جدا از `http.ts` و فقط از ورودیِ داشبورد: `http.ts` در باندلِ صفحه‌های عمومی (پشتیبانی) هم هست و بودجه‌اش
 * تنگ است؛ صفحه‌ی عمومی زمینه‌ی عضویتی ندارد. پاسخِ موفق و پاسخِ خطا هر دو خوانده می‌شوند.
 */
export function watchServerContext(
  instance: AxiosInstance,
  onContext: (context: string) => void,
): void {
  const read = (headers: unknown) => {
    const record = headers as Record<string, unknown> | undefined
    // 🔴 پاسخِ همگانی را `sw.js` کش می‌کند و سربرگش کهنه است (CI ِ `0a427cb`). سرور دیگر رویش زمینه نمی‌گذارد،
    // ولی نسخه‌ای که پیش از این اصلاح در کشِ مرورگری نشسته هنوز دارد.
    const cache = record?.['cache-control']
    if (typeof cache === 'string' && /(^|[\s,])public(\s|,|$)/i.test(cache)) return
    const context = record?.['x-sakena-context']
    if (typeof context === 'string' && context !== '') onContext(context)
  }

  instance.interceptors.response.use(
    (response) => {
      read(response.headers)
      return response
    },
    (error: AxiosError) => {
      read(error.response?.headers)
      return Promise.reject(error)
    },
  )
}
