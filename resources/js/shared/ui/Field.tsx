import {
  forwardRef,
  useId,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react'

/**
 * ورودی‌های فرم.
 *
 * ─── باگی که R37 پیدا کرد ──────────────────────────────────────────────────
 * ⚠️ `<label>` **کنارِ** ورودی رندر می‌شد، نه متصل به آن: نه `htmlFor` داشت
 * نه ورودی را در بر می‌گرفت. یعنی برچسب روی صفحه دیده می‌شد ولی از دیدِ
 * صفحه‌خوان ورودی **بی‌نام** بود. اندازه‌گیری در مرورگر روی `/auth` نشان داد
 * هیچ‌کدام از ورودی‌های شماره و رمز نامِ قابلِ‌دسترس ندارند.
 *
 * پیامِ خطا هم فقط یک `<p>` بود؛ کاربرِ صفحه‌خوان اصلاً نمی‌فهمید فرمش رد
 * شده، چون نه `aria-invalid` بود نه `aria-describedby`.
 *
 * چون همه‌ی فرم‌های پروژه از همین فایل می‌آیند، اصلاح اینجا همه‌جا را با هم
 * درست می‌کند.
 */

/*
 * رنگ، حاشیه و حلقه‌ی فوکوس از کلاسِ `field` در `app.css` می‌آید (مرحلهٔ ۷ ·
 * فاز ۱)؛ اینجا فقط اندازه است. حالت (خنثی/نامعتبر/معتبر) از صفت‌هایی
 * خوانده می‌شود که `useFieldIds` می‌گذارد.
 */
const baseInput = 'field w-full rounded-xl py-2.5 px-3 text-[13.5px]'

/**
 * شناسه‌های یک فیلد و صفاتِ ARIAِ متناظرشان.
 *
 * `useId` شناسه‌ی پایدار و یکتا می‌سازد؛ نوشتنِ دستیِ `id` باعث می‌شد دو
 * نمونه از یک فرم روی صفحه (مثلاً مودالِ ویرایش کنارِ فرمِ افزودن) شناسه‌ی
 * تکراری بگیرند و برچسب به ورودیِ اشتباه بچسبد.
 */
function useFieldIds(hasError: boolean, valid = false) {
  const id = useId()
  const errorId = `${id}-error`

  return {
    id,
    errorId,
    control: {
      id,
      'aria-invalid': hasError || undefined,
      'aria-describedby': hasError ? errorId : undefined,
      // ⚠️ معتبر فقط وقتی خطایی نیست: دو حالتِ متناقض هرگز با هم نمی‌نشینند
      'data-valid': (valid && !hasError) || undefined,
    },
  }
}

function Wrapper({
  id,
  errorId,
  label,
  error,
  children,
}: {
  id: string
  errorId: string
  label: string
  error?: string
  children: ReactNode
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label
        htmlFor={id}
        className="text-[13px] font-medium"
        style={{ color: 'var(--text-secondary)' }}
      >
        {label}
      </label>

      {children}

      {error && (
        <p
          id={errorId}
          /*
           * ⚠️ `role="alert"` یعنی صفحه‌خوان خطا را **همان لحظه** می‌خواند.
           * بدونش، کاربر فقط وقتی می‌فهمید که خودش دوباره روی فیلد برگردد —
           * یعنی معمولاً هیچ‌وقت، چون نمی‌داند جایی خطایی هست.
           */
          role="alert"
          className="text-xs"
          style={{ color: 'var(--color-danger)' }}
        >
          {error}
        </p>
      )}
    </div>
  )
}

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  error?: string
  /** اعتبارسنجی انجام شد و مقدار درست است: حاشیه‌ی سبزِ موفقیت. */
  valid?: boolean
}

/** ورودی متنی سازگار با register() از React Hook Form. */
export const TextField = forwardRef<HTMLInputElement, TextFieldProps>(
  ({ label, error, valid, ...props }, ref) => {
    const { id, errorId, control } = useFieldIds(Boolean(error), valid)

    return (
      <Wrapper id={id} errorId={errorId} label={label} error={error}>
        <input ref={ref} className={baseInput} {...control} {...props} />
      </Wrapper>
    )
  },
)
TextField.displayName = 'TextField'

interface TextAreaFieldProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label: string
  error?: string
}

/** متنِ چندخطی با همان برچسب و اعلامِ خطا (مرحلهٔ ۶ · فاز ۱۱). */
export const TextAreaField = forwardRef<HTMLTextAreaElement, TextAreaFieldProps>(
  ({ label, error, ...props }, ref) => {
    const { id, errorId, control } = useFieldIds(Boolean(error))

    return (
      <Wrapper id={id} errorId={errorId} label={label} error={error}>
        <textarea ref={ref} className={`${baseInput} leading-7`} {...control} {...props} />
      </Wrapper>
    )
  },
)
TextAreaField.displayName = 'TextAreaField'

interface SelectFieldProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string
  error?: string
  options: { value: string | number; label: string }[]
  placeholder?: string
}

export const SelectField = forwardRef<HTMLSelectElement, SelectFieldProps>(
  ({ label, error, options, placeholder, ...props }, ref) => {
    const { id, errorId, control } = useFieldIds(Boolean(error))

    return (
      <Wrapper id={id} errorId={errorId} label={label} error={error}>
        <select ref={ref} className={baseInput} {...control} {...props}>
          {placeholder && <option value="">{placeholder}</option>}
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </Wrapper>
    )
  },
)
SelectField.displayName = 'SelectField'

interface CheckFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
}

export const CheckField = forwardRef<HTMLInputElement, CheckFieldProps>(
  ({ label, ...props }, ref) => (
    /*
     * اینجا برچسب ورودی را **در بر می‌گیرد**، پس اتصال از قبل درست بود.
     *
     * `py-2` و `-my-2` عمدی‌اند: ناحیه‌ی لمسی را به ۴۴ پیکسل می‌رسانند
     * بدونِ اینکه فاصله‌ی بصری‌اش با فیلدهای دیگر به‌هم بخورد. خودِ مربعِ
     * چک‌باکس ۱۶ پیکسل است و انگشت روی موبایل خطا می‌زند.
     */
    <label
      className="-my-2 flex items-center gap-2 py-2 text-[13px]"
      style={{ color: 'var(--text-secondary)' }}
    >
      <input ref={ref} type="checkbox" className="h-4 w-4 rounded" {...props} />
      {label}
    </label>
  ),
)
CheckField.displayName = 'CheckField'
