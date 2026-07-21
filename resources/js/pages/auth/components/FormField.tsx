import { forwardRef, useState, type InputHTMLAttributes } from 'react'
import { Eye, EyeOff, type LucideIcon } from 'lucide-react'
import { cn } from '@/lib/cn'

interface FormFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  icon: LucideIcon
  error?: string
}

export const FormField = forwardRef<HTMLInputElement, FormFieldProps>(
  ({ label, icon: Icon, error, type = 'text', className, ...props }, ref) => {
    const [showPassword, setShowPassword] = useState(false)
    const isPassword = type === 'password'
    const inputType = isPassword && showPassword ? 'text' : type

    return (
      <div className="flex flex-col gap-1.5">
        <label className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
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
            type={inputType}
            className={cn(
              'w-full rounded-xl border py-3 pr-11 text-[13.5px] outline-none transition-all duration-200 focus:ring-2',
              isPassword ? 'pl-11' : 'pl-4',
              className,
            )}
            style={{
              backgroundColor: 'var(--surface-sunken)',
              borderColor: error ? 'var(--color-danger)' : 'var(--border-subtle)',
              color: 'var(--text-primary)',
              ['--tw-ring-color' as string]: error ? 'var(--color-danger)' : 'var(--ring-focus)',
            }}
            {...props}
          />

          {isPassword && (
            <button
              type="button"
              onClick={() => setShowPassword((prev) => !prev)}
              className="absolute left-3.5 top-1/2 -translate-y-1/2"
              style={{ color: 'var(--text-tertiary)' }}
              tabIndex={-1}
              aria-label={showPassword ? 'مخفی کردن رمز' : 'نمایش رمز'}
            >
              {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
            </button>
          )}
        </div>

        {error && (
          <p className="text-xs" style={{ color: 'var(--color-danger)' }}>
            {error}
          </p>
        )}
      </div>
    )
  },
)

FormField.displayName = 'FormField'
