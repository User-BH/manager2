import DatePickerModule, { type DateObject } from 'react-multi-date-picker'
import persian from 'react-date-object/calendars/persian'
import persian_fa from 'react-date-object/locales/persian_fa'

/*
 * react-multi-date-picker یک ماژول CommonJS با `__esModule: true` است و
 * کامپوننت را زیر `.default` می‌گذارد. اینتراپِ rolldown گاهی به‌جای همان
 * default، کل namespace را به import پیش‌فرض می‌دهد؛ آن‌وقت رندرِ
 * `<DatePicker>` با «element type is invalid» می‌ترکد. این خط هر دو حالت را
 * می‌پذیرد: اگر namespace بود default را برمی‌دارد، وگرنه خودش کامپوننت است.
 */
const DatePicker = (
  (DatePickerModule as { default?: typeof DatePickerModule }).default ?? DatePickerModule
) as typeof DatePickerModule

/**
 * انتخابگر تاریخِ شمسی.
 *
 * مقدار در فرم به‌صورت میلادی ISO («YYYY-MM-DD») نگه داشته می‌شود، چون
 * دیتابیس و اعتبارسنجی سرور با همان کار می‌کنند؛ ولی به کاربر تقویم شمسی
 * نشان داده و انتخابش هم شمسی است. تبدیل رفت‌وبرگشت همین‌جا انجام می‌شود، پس
 * بقیه‌ی فرم اصلاً درگیر تقویم نیست.
 */
function toGregorianISO(date: DateObject | null): string {
  if (!date) return ''

  // toDate() معادلِ میلادیِ همان روزِ شمسی را به‌صورت Date جاوااسکریپت می‌دهد.
  // اجزا را دستی می‌چینیم تا منطقه‌ی زمانی روی تاریخِ خالی اثر نگذارد.
  const d = date.toDate()
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

export function JalaliDatePicker({
  label,
  value,
  onChange,
  error,
  placeholder = 'انتخاب تاریخ',
  maxToday = false,
}: {
  label: string
  /** میلادی ISO یا رشته‌ی خالی. */
  value: string
  onChange: (isoDate: string) => void
  error?: string
  placeholder?: string
  /** جلوی انتخاب تاریخِ آینده را می‌گیرد (مثلاً برای تاریخ تولد). */
  maxToday?: boolean
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
        {label}
      </label>

      <DatePicker
        calendar={persian}
        locale={persian_fa}
        // مقدارِ میلادی به‌صورت Date داده می‌شود تا کتابخانه خودش معادل شمسی
        // را نمایش دهد؛ اگر رشته می‌دادیم آن را شمسی تفسیر می‌کرد و غلط می‌شد.
        value={value ? new Date(value) : ''}
        onChange={(date) => onChange(toGregorianISO(date as DateObject | null))}
        format="YYYY/MM/DD"
        maxDate={maxToday ? new Date() : undefined}
        // فقط از تقویم؛ تایپ دستیِ تاریخ خطای فرمت می‌سازد
        editable={false}
        inputClass={error ? 'jalali-input jalali-input-error' : 'jalali-input'}
        containerClassName="jalali-container"
        calendarPosition="bottom-right"
        placeholder={placeholder}
        arrow={false}
      />

      {error && (
        <p className="text-xs" style={{ color: 'var(--color-danger)' }}>
          {error}
        </p>
      )}
    </div>
  )
}
