import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { alertError } from '@/shared/lib/alert'
import { formatNumber } from '@/shared/lib/format'
import { JALALI_MONTHS, faDigits, fromIso, nowInTehran } from '@/shared/lib/jalali'
import { SelectField, TextAreaField, TextField } from '@/shared/ui/Field'
import { JalaliDatePicker } from '@/shared/ui/JalaliDatePicker'
import { leadDaysOf, reminderSchema, type ReminderValues } from './schema'
import type { ReminderDetail, ReminderScope } from './types'

/** «۳۰ مهر ۱۴۰۵» از «YYYY-MM-DD» ِ میلادی */
export function jalaliLabel(iso: string): string {
  const { jy, jm, jd } = fromIso(iso)
  return `${faDigits(jd)} ${JALALI_MONTHS[jm - 1]} ${faDigits(jy)}`
}

/** «۰۹:۳۰» — رقمِ فارسی، صفرِ آغاز سرِ جایش */
export function faTime(time: string): string {
  return faDigits(time)
}

/** همان روز منهای چند روز — تاریخِ تقویمیِ خالص، بی اثرِ منطقه */
function minusDays(iso: string, days: number): string {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d - days)).toISOString().slice(0, 10)
}

const LEADS = [
  { value: '0', label: 'همان روز' },
  { value: '1', label: '۱ روز قبل' },
  { value: '2', label: '۲ روز قبل' },
  { value: '3', label: '۳ روز قبل' },
  { value: '7', label: '۷ روز قبل' },
  { value: 'custom', label: 'چند روز قبل (دلخواه)' },
]

const SCOPES: { value: ReminderScope; label: string }[] = [
  { value: 'this', label: 'فقط همین یادآور' },
  { value: 'following', label: 'همین و همه‌ی تکرارهای بعدی' },
  { value: 'all', label: 'همه‌ی تکرارها' },
]

interface ReminderFormProps {
  /** تاریخِ رویداد — روزی که در تقویم برگزیده شده */
  targetIso: string
  /** ویرایش: یادآورِ موجود و نمونه‌ای که از آن باز شد */
  editing?: { reminder: ReminderDetail; occurrence: string }
  onDone: () => void
  onCancel: () => void
}

/**
 * ساخت و ویرایشِ یادآور (ایرادات ۱۰، بندهای ۷۷ تا ۸۵): دو گام — فرم، سپس پیش‌نمایشِ روشن با «ثبت یادآور» و «لغو».
 * تاریخِ رویداد همان روزِ برگزیده‌ی تقویم است و جدا از زمانِ یادآوری نمایش داده می‌شود (بندِ ۷۸).
 */
export function ReminderForm({ targetIso, editing, onDone, onCancel }: ReminderFormProps) {
  const reminder = editing?.reminder
  const recurring = reminder?.repeatEveryDays != null
  const [step, setStep] = useState<'form' | 'preview'>('form')
  const [scope, setScope] = useState<ReminderScope>('this')
  const [saving, setSaving] = useState(false)

  const lead = reminder ? String(reminder.leadDays) : '1'
  const known = LEADS.some((option) => option.value === lead)
  const {
    register,
    control,
    handleSubmit,
    setValue,
    getValues,
    setError,
    formState: { errors },
  } = useForm<ReminderValues>({
    resolver: zodResolver(reminderSchema),
    defaultValues: {
      title: reminder?.title ?? '',
      description: reminder?.description ?? '',
      lead: known ? (lead as ReminderValues['lead']) : 'custom',
      customLead: known ? '' : lead,
      remindTime: reminder?.remindTime ?? '09:00',
      repeat: recurring ? 'every' : 'once',
      every: reminder?.repeatEveryDays ? String(reminder.repeatEveryDays) : '7',
      end: reminder?.repeatEnd ?? 'none',
      until: reminder?.repeatUntil ?? '',
      count: reminder?.repeatCount ? String(reminder.repeatCount) : '',
    },
  })
  const [leadValue, repeat, end, until] = useWatch({
    control,
    name: ['lead', 'repeat', 'end', 'until'],
  })

  /** یادآورِ یک‌باره‌ای که لحظه‌اش رفته هرگز نمی‌رسد؛ همان قاعده‌ی سرور، پیش از پیش‌نمایش */
  function toPreview(values: ReminderValues) {
    const moment = `${minusDays(targetIso, leadDaysOf(values))} ${values.remindTime}`
    if (values.repeat === 'once' && moment <= nowInTehran()) {
      setError('lead', {
        message: 'این زمانِ یادآوری گذشته است؛ روزِ نزدیک‌تر یا ساعتِ دیرتری انتخاب کنید.',
      })
      return
    }
    setStep('preview')
  }

  async function save() {
    const values = getValues()
    setSaving(true)
    const body = {
      title: values.title,
      description: values.description || null,
      target_date: targetIso,
      lead_days: leadDaysOf(values),
      remind_time: values.remindTime,
      repeat_every_days: values.repeat === 'every' ? Number(values.every) : null,
      repeat_end: values.repeat === 'every' ? values.end : null,
      repeat_until: values.repeat === 'every' && values.end === 'until' ? values.until : null,
      repeat_count:
        values.repeat === 'every' && values.end === 'count' ? Number(values.count) : null,
      ...(reminder && recurring ? { scope, occurrence: editing.occurrence } : {}),
    }
    try {
      await api(reminder ? `/reminders/${reminder.id}` : '/reminders', {
        method: reminder ? 'PUT' : 'POST',
        body,
      })
      onDone()
    } catch (error) {
      // خطای قاعده‌ی سرور (مثلاً «زمانِ یادآوری گذشته است») کنارِ همان فرم، با بازگشت به گامِ فرم
      setStep('form')
      setError('root', {
        message: error instanceof Error ? error.message : 'ثبتِ یادآور ممکن نشد.',
      })
      alertError(error, 'ثبتِ یادآور ممکن نشد.')
    } finally {
      setSaving(false)
    }
  }

  if (step === 'preview') {
    const values = getValues()
    const days = leadDaysOf(values)
    const rows: [string, string][] = [
      ['تاریخِ رویداد', jalaliLabel(targetIso)],
      ['یادآوری', days === 0 ? 'همان روز' : `${formatNumber(days)} روز قبل`],
      [
        'زمانِ یادآوری',
        `${jalaliLabel(minusDays(targetIso, days))} ساعتِ ${faTime(values.remindTime)}`,
      ],
      [
        'تکرار',
        values.repeat === 'once'
          ? 'یک‌باره'
          : `هر ${formatNumber(Number(values.every))} روز${
              values.end === 'until'
                ? ` تا ${jalaliLabel(values.until)}`
                : values.end === 'count'
                  ? `، ${formatNumber(Number(values.count))} بار`
                  : '، بی‌پایان'
            }`,
      ],
    ]
    if (values.description) rows.push(['توضیحات', values.description])

    return (
      <div className="flex flex-col gap-4">
        <h3 className="text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
          {values.title}
        </h3>
        <dl
          className="flex flex-col gap-2 rounded-2xl border p-4"
          style={{ borderColor: 'var(--border-default)' }}
        >
          {rows.map(([label, value]) => (
            <div key={label} className="flex flex-wrap justify-between gap-2 text-body">
              <dt style={{ color: 'var(--text-tertiary)' }}>{label}</dt>
              <dd className="font-bold" style={{ color: 'var(--text-primary)' }}>
                {value}
              </dd>
            </div>
          ))}
        </dl>

        {reminder && recurring && (
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-body font-bold" style={{ color: 'var(--text-primary)' }}>
              این تغییر روی کدام یادآورها اعمال شود؟
            </legend>
            {SCOPES.map((option) => (
              <label
                key={option.value}
                className="flex items-center gap-2 text-body"
                style={{ color: 'var(--text-secondary)' }}
              >
                <input
                  type="radio"
                  name="scope"
                  checked={scope === option.value}
                  onChange={() => setScope(option.value)}
                />
                {option.label}
              </label>
            ))}
          </fieldset>
        )}

        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => void save()}
            disabled={saving}
            className="flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-brand-600)' }}
          >
            {saving && <Loader2 size={15} className="animate-spin" />}
            {reminder ? 'ثبتِ تغییرات' : 'ثبت یادآور'}
          </button>
          <button
            type="button"
            onClick={() => setStep('form')}
            className="rounded-xl border px-5 py-2.5 text-sm font-semibold"
            style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
          >
            بازگشت به فرم
          </button>
          <button
            type="button"
            onClick={onCancel}
            className="rounded-xl px-5 py-2.5 text-sm font-semibold"
            style={{ color: 'var(--text-tertiary)' }}
          >
            لغو
          </button>
        </div>
      </div>
    )
  }

  return (
    <form
      onSubmit={(event) => void handleSubmit(toPreview)(event)}
      noValidate
      className="flex flex-col gap-3"
    >
      <p className="text-body" style={{ color: 'var(--text-secondary)' }}>
        تاریخِ رویداد:{' '}
        <strong style={{ color: 'var(--text-primary)' }}>{jalaliLabel(targetIso)}</strong> — برای
        تاریخِ دیگر، روزِ دیگری را در تقویم انتخاب کنید.
      </p>
      <TextField
        label="موضوع"
        placeholder="مثلاً تماس با پیمانکار"
        maxLength={150}
        error={errors.title?.message}
        {...register('title')}
      />
      <TextAreaField
        label="توضیحات (اختیاری)"
        rows={2}
        maxLength={1000}
        error={errors.description?.message}
        {...register('description')}
      />

      <div className="grid gap-3 sm:grid-cols-2">
        <SelectField
          label="زمانِ یادآوری"
          options={LEADS}
          error={errors.lead?.message}
          {...register('lead')}
        />
        <TextField
          label="ساعتِ یادآوری"
          type="time"
          dir="ltr"
          error={errors.remindTime?.message}
          {...register('remindTime')}
        />
        {leadValue === 'custom' && (
          <TextField
            label="چند روز پیش از رویداد"
            inputMode="numeric"
            dir="ltr"
            error={errors.customLead?.message}
            {...register('customLead')}
          />
        )}
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <SelectField
          label="تکرار"
          options={[
            { value: 'once', label: 'یک‌باره' },
            { value: 'every', label: 'هر چند روز یک‌بار' },
          ]}
          {...register('repeat')}
        />
        {repeat === 'every' && (
          <TextField
            label="هر چند روز"
            inputMode="numeric"
            dir="ltr"
            error={errors.every?.message}
            {...register('every')}
          />
        )}
        {repeat === 'every' && (
          <SelectField
            label="پایانِ تکرار"
            options={[
              { value: 'none', label: 'بی‌پایان' },
              { value: 'until', label: 'تا تاریخِ مشخص' },
              { value: 'count', label: 'تعدادِ دفعاتِ مشخص' },
            ]}
            {...register('end')}
          />
        )}
        {repeat === 'every' && end === 'until' && (
          <JalaliDatePicker
            label="تا تاریخ"
            value={until}
            onChange={(iso) => setValue('until', iso, { shouldValidate: true })}
            error={errors.until?.message}
          />
        )}
        {repeat === 'every' && end === 'count' && (
          <TextField
            label="چند بار"
            inputMode="numeric"
            dir="ltr"
            error={errors.count?.message}
            {...register('count')}
          />
        )}
      </div>

      {errors.root?.message && (
        <p role="alert" className="text-body" style={{ color: 'var(--color-danger)' }}>
          {errors.root.message}
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        <button
          type="submit"
          className="rounded-xl px-5 py-2.5 text-sm font-bold text-white"
          style={{ backgroundColor: 'var(--color-brand-600)' }}
        >
          پیش‌نمایش
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="rounded-xl border px-5 py-2.5 text-sm font-semibold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
        >
          لغو
        </button>
      </div>
    </form>
  )
}
