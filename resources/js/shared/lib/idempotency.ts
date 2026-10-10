/**
 * کلیدِ تکرارِ یک کارِ مالی (`Idempotency-Key`؛ مرحلهٔ ۹).
 *
 * یک کلید برای **یک قصدِ کاربر** ساخته می‌شود و برای هر تلاشِ دوباره‌ی همان قصد
 * (کلیکِ دوباره پس از پاسخِ گم‌شده، retry ِ شبکه) همان می‌ماند؛ سرور با همان
 * کلید فقط یک بار پول برمی‌دارد. شکلش با قاعده‌ی سرور می‌خواند: ۸ تا ۶۴ نویسه
 * از حرف، رقم، `-` و `_`.
 */
export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  return `k-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}
