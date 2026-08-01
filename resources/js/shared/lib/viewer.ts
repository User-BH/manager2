/**
 * آیا بازدیدکننده‌ی این صفحه‌ی عمومی از قبل وارد شده است؟
 *
 * سرور این را در `<head>` می‌گذارد (`partials/viewer.blade.php`) تا صفحه از
 * همان اولین رنگ‌آمیزی دکمه‌ی درست را نشان بدهد. با پرسیدن از `/api/me` کاربرِ
 * واردشده یک لحظه «ورود» می‌دید و بعد دکمه عوض می‌شد.
 *
 * فقط یک بولین است — نه نام، نه نقش. اگر روزی به چیزِ بیشتری نیاز شد، یادتان
 * باشد که این صفحه ممکن است پشتِ کش باشد.
 */
export function isViewerAuthenticated(): boolean {
  if (typeof document === 'undefined') return false

  const tag = document.getElementById('viewer-state')
  if (!tag?.textContent) return false

  try {
    /*
     * `JSON.parse` نوعِ `any` می‌دهد و هر مقایسه‌ای رویش از دیدِ کامپایلر
     * بی‌پشتوانه است. با نوعِ صریح، مقایسه واقعاً بررسی می‌شود — و چون شرط
     * `=== true` است، مقدارهای truthyِ غیرمنتظره (مثل رشته‌ی "yes") هم
     * «واردشده» حساب نمی‌شوند.
     */
    const parsed = JSON.parse(tag.textContent) as { authenticated?: unknown }
    return parsed.authenticated === true
  } catch {
    // تگِ خراب نباید صفحه را بشکند؛ در بدترین حالت دکمه‌ی «ورود» می‌ماند
    return false
  }
}
