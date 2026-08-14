import { forwardRef, useId, useState, type InputHTMLAttributes } from 'react'
import { Eye, EyeOff, type LucideIcon } from 'lucide-react'
import { cn } from '@/shared/lib/cn'

interface FormFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  icon: LucideIcon
  error?: string
  /** بوردر/رینگِ قرمز بدون نمایشِ پیام (پیام جای دیگری، مثلاً توست، نشان داده می‌شود). */
  invalid?: boolean
}

export const FormField = forwardRef<HTMLInputElement, FormFieldProps>(
  ({ label, icon: Icon, error, invalid, type = 'text', className, ...props }, ref) => {
    const [showPassword, setShowPassword] = useState(false)
    const isPassword = type === 'password'
    const inputType = isPassword && showPassword ? 'text' : type
    // قرمزشدن با خطای متنی یا پرچمِ invalid؛ ولی پیام فقط وقتی error هست
    const hasError = Boolean(error) || Boolean(invalid)

    /*
     * ⚠️ برچسب پیش از این فقط **کنارِ** ورودی بود، نه متصل به آن.
     *
     * اندازه‌گیری در مرورگر روی `/auth` نشان داد هر دو ورودیِ شماره و رمز از
     * دیدِ صفحه‌خوان **بی‌نام** بودند: نه `htmlFor` بود نه `aria-label`.
     * `useId` شناسه‌ی یکتا می‌دهد، پس دو نمونه از فرم روی یک صفحه (ورود و
     * ثبت‌نام کنارِ هم) شناسه‌ی تکراری نمی‌گیرند.
     */
    const id = useId()
    const errorId = `${id}-error`

    /*
     * جلوگیری از کپیِ رمز از داخل اینپوت.
     *
     * کپی/برش/منوی راست‌کلیک/کشیدن روی فیلدِ رمز بسته می‌شود تا رمزِ تایپ‌شده
     * از فیلد بیرون کشیده نشود. جای‌گذاری (paste) آزاد است، چون کاربر ممکن است
     * بخواهد رمزش را از یک نگه‌دارنده‌ی رمز بچسباند.
     */
    const blockCopy = isPassword
      ? {
          onCopy: preventEvent,
          onCut: preventEvent,
          onContextMenu: preventEvent,
          onDragStart: preventEvent,
        }
      : {}

    return (
      <div className="flex flex-col gap-1.5">
        <label
          htmlFor={id}
          className="text-[13px] font-medium"
          style={{ color: 'var(--text-secondary)' }}
        >
          {label}
        </label>

        <div className="relative">
          <Icon
            size={17}
            className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2"
            style={{ color: 'var(--text-tertiary)' }}
          />

          <input
            ref={ref}
            id={id}
            type={inputType}
            aria-invalid={hasError || undefined}
            aria-describedby={error ? errorId : undefined}
            className={cn(
              'w-full rounded-xl border py-3 pr-11 text-[13.5px] outline-none transition-all duration-200 focus:ring-2',
              isPassword ? 'pl-11' : 'pl-4',
              className,
            )}
            style={{
              backgroundColor: 'var(--surface-sunken)',
              borderColor: hasError ? 'var(--color-danger)' : 'var(--border-subtle)',
              color: 'var(--text-primary)',
              ['--tw-ring-color' as string]: hasError ? 'var(--color-danger)' : 'var(--ring-focus)',
            }}
            {...props}
            {...blockCopy}
          />

          {isPassword && (
            <button
              type="button"
              onClick={() => setShowPassword((prev) => !prev)}
              /*
               * ⚠️ `tabIndex={-1}` برداشته شد.
               *
               * کاربری که با کیبورد کار می‌کند هم حق دارد رمزش را ببیند —
               * و برای او این تنها راهِ بررسیِ چیزی است که تایپ کرده.
               * `p-3` ناحیه‌ی لمسی را از ۱۷ به ۴۱ پیکسل می‌رساند.
               */
              className="absolute left-1 top-1/2 -translate-y-1/2 rounded-lg p-3"
              style={{ color: 'var(--text-tertiary)' }}
              aria-label={showPassword ? 'مخفی کردن رمز' : 'نمایش رمز'}
            >
              {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
            </button>
          )}
        </div>

        {error && (
          /*
           * `role="alert"` یعنی صفحه‌خوان خطا را همان لحظه می‌خواند. بدونش،
           * کاربر فقط وقتی می‌فهمید که خودش دوباره روی فیلد برگردد.
           */
          <p id={errorId} role="alert" className="text-xs" style={{ color: 'var(--color-danger)' }}>
            {error}
          </p>
        )}
      </div>
    )
  },
)

FormField.displayName = 'FormField'

function preventEvent(event: { preventDefault: () => void }) {
  event.preventDefault()
}
