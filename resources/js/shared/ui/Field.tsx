import { forwardRef, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from 'react'

const baseInput =
  'w-full rounded-xl border py-2.5 px-3 text-[13.5px] outline-none transition-all duration-200 focus:ring-2'

function fieldStyle(hasError: boolean) {
  return {
    backgroundColor: 'var(--surface-sunken)',
    borderColor: hasError ? 'var(--color-danger)' : 'var(--border-subtle)',
    color: 'var(--text-primary)',
    ['--tw-ring-color' as string]: hasError ? 'var(--color-danger)' : 'var(--ring-focus)',
  }
}

function Wrapper({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <label className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
        {label}
      </label>
      {children}
      {error && (
        <p className="text-xs" style={{ color: 'var(--color-danger)' }}>
          {error}
        </p>
      )}
    </div>
  )
}

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  error?: string
}

/** ورودی متنی سازگار با register() از React Hook Form. */
export const TextField = forwardRef<HTMLInputElement, TextFieldProps>(
  ({ label, error, ...props }, ref) => (
    <Wrapper label={label} error={error}>
      <input ref={ref} className={baseInput} style={fieldStyle(Boolean(error))} {...props} />
    </Wrapper>
  ),
)
TextField.displayName = 'TextField'

interface SelectFieldProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string
  error?: string
  options: { value: string | number; label: string }[]
  placeholder?: string
}

export const SelectField = forwardRef<HTMLSelectElement, SelectFieldProps>(
  ({ label, error, options, placeholder, ...props }, ref) => (
    <Wrapper label={label} error={error}>
      <select ref={ref} className={baseInput} style={fieldStyle(Boolean(error))} {...props}>
        {placeholder && <option value="">{placeholder}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </Wrapper>
  ),
)
SelectField.displayName = 'SelectField'

interface CheckFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
}

export const CheckField = forwardRef<HTMLInputElement, CheckFieldProps>(({ label, ...props }, ref) => (
  <label className="flex items-center gap-2 text-[13px]" style={{ color: 'var(--text-secondary)' }}>
    <input ref={ref} type="checkbox" className="h-4 w-4 rounded" {...props} />
    {label}
  </label>
))
CheckField.displayName = 'CheckField'
