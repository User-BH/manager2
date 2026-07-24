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
  /** بوردر قرمز بدون هیچ پیامی؛ برای حالتی مثل «شماره/رمز نادرست» که پیامش توست است. */
  invalid?: boolean
  /** نویسه‌های نامجاز را حذف می‌کند. */
  filter: (value: string) => FilterResult
  /** پیامی که هنگام حذفِ نویسه‌ی نامجاز کوتاه نشان داده می‌شود. */
  hint: string
  /** با هر تایپِ کاربر صدا زده می‌شود؛ برای پاک‌کردنِ حالتِ خطای بیرونی. */
  onUserInput?: () => void
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
  invalid,
  onUserInput,
  ...fieldProps
}: RestrictedFieldProps<T>) {
  const [filterHint, setFilterHint] = useState<string | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  function flashHint(message: string) {
    setFilterHint(message)
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
            const result = filter(e.target.value)
            // پیامِ ویژه‌ی همین تغییر مقدم است، وگرنه پیامِ پیش‌فرضِ فیلد
            if (result.changed) flashHint(result.hint ?? hint)
            else setFilterHint(null)
            field.onChange(result.value)
            onUserInput?.()
          }}
          // خطای فرم مقدم است؛ وگرنه پیام لحظه‌ایِ پالایه
          error={error ?? filterHint ?? undefined}
          invalid={invalid}
        />
      )}
    />
  )
}
