import { useRef, useState } from 'react'
import { type Control, Controller, type FieldValues, type Path } from 'react-hook-form'
import { type LucideIcon } from 'lucide-react'
import { FormField } from './FormField'
import type { FilterResult } from '@/lib/inputFilters'

interface RestrictedFieldProps<T extends FieldValues> {
  control: Control<T>
  name: Path<T>
  label: string
  icon: LucideIcon
  placeholder?: string
  type?: string
  autoComplete?: string
  inputMode?: 'text' | 'numeric'
  dir?: 'rtl' | 'ltr'
  /** خطای اعتبارسنجیِ فرم (از react-hook-form). */
  error?: string
  /** نویسه‌های نامجاز را حذف می‌کند. */
  filter: (value: string) => FilterResult
  /** پیامی که هنگام حذفِ نویسه‌ی نامجاز کوتاه نشان داده می‌شود. */
  hint: string
}

/**
 * ورودیِ محدودشده: نویسه‌ی خلاف الگو اصلاً وارد نمی‌شود و اگر کاربر چیزی
 * نامجاز تایپ کرد، یک پیام کوتاه زیر فیلد نشان داده می‌شود.
 *
 * پیامِ پالایه با خطای اعتبارسنجیِ فرم قاتی نمی‌شود؛ خطای فرم اولویت دارد
 * (مثلاً «شماره باید ۱۱ رقم باشد») و پیام پالایه فقط وقتی می‌آید که خطای
 * فرمی در کار نباشد.
 */
export function RestrictedField<T extends FieldValues>({
  control,
  name,
  filter,
  hint,
  error,
  ...fieldProps
}: RestrictedFieldProps<T>) {
  const [filterHint, setFilterHint] = useState<string | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  function flashHint() {
    setFilterHint(hint)
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => setFilterHint(null), 2200)
  }

  return (
    <Controller
      control={control}
      name={name}
      render={({ field }) => (
        <FormField
          {...fieldProps}
          value={field.value ?? ''}
          onBlur={field.onBlur}
          onChange={(e) => {
            const { value, changed } = filter(e.target.value)
            if (changed) flashHint()
            else setFilterHint(null)
            field.onChange(value)
          }}
          // خطای فرم مقدم است؛ وگرنه پیام لحظه‌ایِ پالایه
          error={error ?? filterHint ?? undefined}
        />
      )}
    />
  )
}
