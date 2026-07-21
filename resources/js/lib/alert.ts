import Swal, { type SweetAlertOptions } from 'sweetalert2'
import { ApiError } from './api'

/**
 * تمام هشدارهای سایت از اینجا رد می‌شوند.
 *
 * دلیل داشتن این لایه به‌جای صدا زدن مستقیم Swal: رنگ‌ها و جهت و فونت باید
 * با بقیه‌ی داشبورد یکی باشند و با تغییر تم هم عوض شوند. SweetAlert2 استایلش
 * را با متغیرهای CSS خودش می‌گیرد، پس در app.css به توکن‌های خودمان وصل شده
 * و اینجا فقط رفتار تنظیم می‌شود.
 */

const base: SweetAlertOptions = {
  // RTL بودن کل داشبورد باید در دیالوگ هم صدق کند وگرنه دکمه‌ها برعکس می‌شوند
  ...({ target: 'body' } as SweetAlertOptions),
  buttonsStyling: false,
  reverseButtons: true,
  customClass: {
    popup: 'swal-app',
    title: 'swal-app-title',
    htmlContainer: 'swal-app-text',
    confirmButton: 'swal-app-confirm',
    cancelButton: 'swal-app-cancel',
    actions: 'swal-app-actions',
    icon: 'swal-app-icon',
  },
}

const app = Swal.mixin(base)

interface ConfirmOptions {
  title: string
  text?: string
  confirmLabel?: string
  cancelLabel?: string
  /** عملیات ویرانگر (حذف) دکمه‌ی قرمز می‌گیرد. */
  danger?: boolean
}

/**
 * جایگزین confirm() بومی.
 *
 * برخلاف confirm() مرورگر، این تابع رشته‌ی خالی و «لغو» را از هم جدا
 * می‌کند و همیشه boolean برمی‌گرداند، پس الگوی `if (!(await confirm(...))) return`
 * در همه‌ی صفحه‌ها یکسان می‌ماند.
 */
export async function confirmAction({
  title,
  text,
  confirmLabel = 'تایید',
  cancelLabel = 'انصراف',
  danger = false,
}: ConfirmOptions): Promise<boolean> {
  const result = await app.fire({
    title,
    text,
    icon: danger ? 'warning' : 'question',
    showCancelButton: true,
    confirmButtonText: confirmLabel,
    cancelButtonText: cancelLabel,
    customClass: {
      ...(base.customClass as object),
      confirmButton: danger ? 'swal-app-confirm swal-app-danger' : 'swal-app-confirm',
    },
  })

  return result.isConfirmed
}

interface PromptOptions {
  title: string
  text?: string
  placeholder?: string
  defaultValue?: string
  confirmLabel?: string
  /** اگر true باشد، کاربر نمی‌تواند مقدار خالی بفرستد. */
  required?: boolean
}

/**
 * جایگزین prompt() بومی.
 *
 * null یعنی «انصراف» و رشته (حتی خالی) یعنی «تایید»؛ همان تفکیکی که
 * prompt() داشت، تا صداکننده بتواند لغو را از پاسخِ خالی جدا کند.
 */
export async function promptText({
  title,
  text,
  placeholder,
  defaultValue = '',
  confirmLabel = 'ثبت',
  required = false,
}: PromptOptions): Promise<string | null> {
  const result = await app.fire({
    title,
    text,
    input: 'text',
    inputValue: defaultValue,
    inputPlaceholder: placeholder,
    inputAttributes: { dir: 'rtl' },
    showCancelButton: true,
    confirmButtonText: confirmLabel,
    cancelButtonText: 'انصراف',
    inputValidator: required
      ? (value) => (value.trim() ? undefined : 'این فیلد را پر کنید.')
      : undefined,
  })

  return result.isConfirmed ? String(result.value ?? '') : null
}

/** پیام موفقیت گوشه‌ی صفحه — جریان کار کاربر را قطع نمی‌کند. */
export function toastSuccess(title: string): void {
  void app.fire({
    toast: true,
    position: 'top-start',
    icon: 'success',
    title,
    showConfirmButton: false,
    timer: 2600,
    timerProgressBar: true,
    customClass: { ...(base.customClass as object), popup: 'swal-app swal-app-toast' },
  })
}

export function toastError(title: string): void {
  void app.fire({
    toast: true,
    position: 'top-start',
    icon: 'error',
    title,
    showConfirmButton: false,
    timer: 4000,
    timerProgressBar: true,
    customClass: { ...(base.customClass as object), popup: 'swal-app swal-app-toast' },
  })
}

export function alertSuccess(title: string, text?: string): Promise<unknown> {
  return app.fire({ title, text, icon: 'success', confirmButtonText: 'باشه' })
}

export function alertInfo(title: string, text?: string): Promise<unknown> {
  return app.fire({ title, text, icon: 'info', confirmButtonText: 'باشه' })
}

/**
 * خطای گرفته‌شده را به پیام فارسی تبدیل و نمایش می‌دهد.
 *
 * خطاهای اعتبارسنجی لاراول چند فیلدی‌اند؛ همه‌شان با هم نشان داده می‌شوند
 * تا کاربر مجبور نشود فرم را چند بار بفرستد تا همه‌ی ایرادها را ببیند.
 */
export function alertError(error: unknown, fallback = 'انجام این کار ممکن نشد.'): void {
  if (error instanceof ApiError) {
    const fields = Object.values(error.errors).flat()

    void app.fire({
      title: error.message || fallback,
      html: fields.length > 1 ? `<ul class="swal-app-list">${fields.map(li).join('')}</ul>` : undefined,
      text: fields.length === 1 ? fields[0] : undefined,
      icon: 'error',
      confirmButtonText: 'باشه',
    })
    return
  }

  void app.fire({
    title: fallback,
    text: error instanceof Error ? error.message : undefined,
    icon: 'error',
    confirmButtonText: 'باشه',
  })
}

/** جلوگیری از تزریق HTML وقتی پیام خطا از سرور می‌آید. */
function li(message: string): string {
  const escaped = message
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')

  return `<li>${escaped}</li>`
}
