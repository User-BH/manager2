import type { SweetAlertOptions, SweetAlertResult } from 'sweetalert2'
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

/**
 * ─── چرا SweetAlert2 تنبل بار می‌شود (R36) ─────────────────────────────────
 * این ماژول ‎۲۱KB فشرده است و پیش از این **ایستا** وارد می‌شد، یعنی در
 * بارگذاریِ اولِ هر صفحه — از جمله صفحه‌ی ورود و صفحه‌ی فرود — دانلود
 * می‌شد. ولی هر ۷۴ نقطه‌ی استفاده‌اش **پس از کنشِ کاربر** است: توستِ بعد از
 * ذخیره، تاییدِ حذف، خطای فرم. هیچ‌کدام در اولین رنگ‌آمیزی لازم نیستند.
 *
 * ⚠️ امضای بیرونیِ این فایل دست‌نخورده ماند تا هیچ‌کدام از ۷۴ صداکننده لازم
 * نباشد عوض شود؛ فقط `fire` پشتِ پرده منتظرِ رسیدنِ ماژول می‌ماند.
 */
let swalReady: Promise<{ fire: (options: SweetAlertOptions) => Promise<SweetAlertResult> }> | null =
  null

function fire(options: SweetAlertOptions): Promise<SweetAlertResult> {
  swalReady ??= import('sweetalert2').then((module) => module.default.mixin(base))

  return swalReady.then((app) => app.fire(options))
}

/**
 * پیش‌گرم‌کردن با اولین نشانه‌ی تعامل.
 *
 * بدونِ این، اولین توستِ کاربر باید منتظرِ دانلودِ ماژول بماند — دقیقاً
 * لحظه‌ای که تازه دکمه‌ای زده و انتظارِ پاسخِ فوری دارد. `pointerdown` و
 * `keydown` **پیش از** کاملِ‌شدنِ آن کنش رخ می‌دهند، پس ماژول معمولاً
 * زودتر از نیاز آماده است.
 */
if (typeof window !== 'undefined') {
  const warm = () => {
    swalReady ??= import('sweetalert2').then((module) => module.default.mixin(base))
  }

  window.addEventListener('pointerdown', warm, { once: true, passive: true })
  window.addEventListener('keydown', warm, { once: true, passive: true })
}

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
  const result = await fire({
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
  const result = await fire({
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

type ToastPosition = 'top-start' | 'top'

function toast(
  icon: 'success' | 'error',
  title: string,
  position: ToastPosition,
  timer: number,
): void {
  void fire({
    toast: true,
    position,
    icon,
    title,
    showConfirmButton: false,
    timer,
    timerProgressBar: true,
    customClass: { ...(base.customClass as object), popup: 'swal-app swal-app-toast' },
  })
}

/** پیام موفقیت گوشه‌ی صفحه — جریان کار کاربر را قطع نمی‌کند. */
export function toastSuccess(title: string): void {
  toast('success', title, 'top-start', 2600)
}

export function toastError(title: string): void {
  toast('error', title, 'top-start', 4000)
}

/**
 * توست‌های بالا-وسطِ صفحه.
 *
 * برای پیام‌هایی که کاربر حتماً باید ببیند و «مرکز دید» او هستند: تاییدِ
 * ثبت‌نام، اخطارِ نبودِ پازل، و خطای «شماره/رمز نادرست». موقعیتِ ثابتِ بالا-وسط
 * یعنی هر جای فرم که باشد، پیام را می‌بیند.
 */
export function toastTopSuccess(title: string): void {
  toast('success', title, 'top', 3200)
}

export function toastTopError(title: string): void {
  toast('error', title, 'top', 4000)
}

export function alertSuccess(title: string, text?: string): Promise<unknown> {
  return fire({ title, text, icon: 'success', confirmButtonText: 'باشه' })
}

export function alertInfo(title: string, text?: string): Promise<unknown> {
  return fire({ title, text, icon: 'info', confirmButtonText: 'باشه' })
}

/**
 * خطای گرفته‌شده را به پیام فارسی تبدیل و نمایش می‌دهد.
 *
 * خطاهای اعتبارسنجی لاراول چند فیلدی‌اند؛ همه‌شان با هم نشان داده می‌شوند
 * تا کاربر مجبور نشود فرم را چند بار بفرستد تا همه‌ی ایرادها را ببیند.
 */
export function alertError(error: unknown, fallback = 'انجام این کار ممکن نشد.'): void {
  /*
   * ۴۰۲ یعنی محدودیت پلن، نه خطا. به‌جای پیام قرمزِ «خطا»، پیشنهاد ارتقا
   * با دکمه‌ی رفتن به صفحه‌ی اشتراک نشان داده می‌شود.
   */
  if (error instanceof ApiError && error.status === 402) {
    void fire({
      title: 'نیازمند اشتراک پرو',
      text: error.message,
      icon: 'info',
      showCancelButton: true,
      confirmButtonText: 'مشاهده پلن‌ها',
      cancelButtonText: 'بستن',
    }).then((result) => {
      if (result.isConfirmed) window.location.assign('/account')
    })
    return
  }

  if (error instanceof ApiError) {
    const fields = Object.values(error.errors).flat()

    void fire({
      title: error.message || fallback,
      html:
        fields.length > 1 ? `<ul class="swal-app-list">${fields.map(li).join('')}</ul>` : undefined,
      text: fields.length === 1 ? fields[0] : undefined,
      icon: 'error',
      confirmButtonText: 'باشه',
    })
    return
  }

  void fire({
    title: fallback,
    text: error instanceof Error ? error.message : undefined,
    icon: 'error',
    confirmButtonText: 'باشه',
  })
}

/** جلوگیری از تزریق HTML وقتی پیام خطا از سرور می‌آید. */
function li(message: string): string {
  const escaped = message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

  return `<li>${escaped}</li>`
}
