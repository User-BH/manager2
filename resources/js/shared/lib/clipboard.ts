/**
 * کپی در کلیپ‌بورد، با راهِ جایگزین (مرحلهٔ ۵ · فاز ۸ · بندِ ۶۶: Clipboard).
 *
 * 🔴 چرا لازم شد: هر دو دکمه‌ی کپی (شماره‌ی کارت در فرمِ رسید، نتیجه‌ی ماشین‌حساب)
 * `navigator.clipboard?.writeText` بودند و شکست را بی‌صدا می‌بلعیدند.
 * `navigator.clipboard` روی http، در WebViewِ برنامه‌های پیام‌رسان و در
 * Safariِ قدیمی وجود ندارد؛ آن‌جا دکمه **هیچ کاری نمی‌کرد** — نه کپی، نه پیام.
 * ساکن فکر می‌کرد شماره‌ی کارت کپی شده و پولِ کارت‌به‌کارت را به حسابی
 * می‌فرستاد که در کلیپ‌بوردش مانده بود.
 *
 * @returns آیا واقعاً کپی شد؛ `false` یعنی باید به کاربر گفت دستی کپی کند.
 */
export async function copyText(value: string): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value)
      return true
    }
  } catch {
    // مجوز رد شد یا زمینه‌ی ناامن؛ راهِ قدیمی را امتحان می‌کنیم
  }

  /*
   * ⚠️ راهِ قدیمی: `execCommand('copy')` روی یک textareaِ موقت. منسوخ است ولی
   * همان جاهایی کار می‌کند که API جدید نیست. `readonly` تا صفحه‌کلیدِ گوشی
   * باز نشود.
   */
  const area = document.createElement('textarea')
  area.value = value
  area.setAttribute('readonly', '')
  area.style.position = 'fixed'
  area.style.opacity = '0'
  document.body.appendChild(area)
  area.select()

  try {
    return document.execCommand('copy')
  } catch {
    return false
  } finally {
    area.remove()
  }
}
