/**
 * قاعده‌ی مشترکِ فایلِ آپلودی، سمتِ مرورگر (مرحلهٔ ۵ · فاز ۶ · بندِ ۵۲).
 *
 * ⚠️ این **امنیت نیست**؛ اعتبارسنجیِ اصلی در سرور است. کارِ این‌جا این است
 * که کاربر پیش از فرستادن بفهمد.
 *
 * 🔴 چرا لازم شد: فرمِ رسیدِ ساکن (`PayBillPage`) هیچ سنجشی نداشت. عکسِ
 * دوربینِ گوشی اغلب ۳ تا ۸ مگابایت است و سقفِ سرور ۴؛ ساکن با اینترنتِ همراه
 * کلِ فایل را می‌فرستاد و **پس از آن** «حجم زیاد است» می‌گرفت.
 *
 * ⚠️ دو فهرست، مثلِ خودِ سرور: رسید `webp` نمی‌پذیرد
 * (`mimes:jpg,jpeg,png,pdf`)، پیوستِ پیام و درخواست می‌پذیرد. یک فهرستِ
 * مشترک یعنی رسیدِ webp از مرورگر رد می‌شد و در سرور رد می‌شد.
 */

/** همان `max:4096`ِ سرور برای رسید و پیوست. */
export const MAX_UPLOAD_MB = 4

export const RECEIPT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'] as const
export const ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'] as const

type Extensions = readonly string[]

const MIME: Record<string, string> = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  pdf: 'application/pdf',
}

/** رشته‌ی `accept` برای `<input type="file">`. */
export function acceptFor(extensions: Extensions): string {
  return extensions.map((ext) => `.${ext}`).join(',')
}

/** مشکلِ فایل به زبانِ کاربر، یا `null` اگر مشکلی نیست. */
export function uploadProblem(
  file: File,
  extensions: Extensions,
  maxMb = MAX_UPLOAD_MB,
): string | null {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  // ⚠️ پسوند یا نوع: بعضی مرورگرها نوعِ فایل را خالی می‌دهند
  const typeOk = extensions.includes(extension) || extensions.some((ext) => MIME[ext] === file.type)

  if (!typeOk) {
    const names = extensions.filter((ext) => ext !== 'jpeg').map((ext) => ext.toUpperCase())
    return `فقط این قالب‌ها پذیرفته می‌شوند: ${names.join('، ')}.`
  }

  if (file.size > maxMb * 1024 * 1024) {
    const actual = (file.size / 1024 / 1024).toLocaleString('fa-IR', { maximumFractionDigits: 1 })
    return `حجمِ فایل ${actual} مگابایت است؛ حداکثر ${maxMb.toLocaleString('fa-IR')} مگابایت. عکس را کوچک‌تر کنید.`
  }

  return null
}
