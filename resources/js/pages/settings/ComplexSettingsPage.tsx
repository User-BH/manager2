import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Save, AlertCircle, CheckCircle2 } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/components/ui/Card'
import { CheckField, SelectField, TextField } from '@/components/ui/Field'
import { ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api, ApiError } from '@/lib/api'

const settingsSchema = z.object({
  name: z.string().min(1, 'نام مجتمع را وارد کنید').max(150, 'نام طولانی است'),
  address: z.string().max(255, 'آدرس طولانی است').optional(),
  phone: z.string().max(20, 'شماره طولانی است').optional(),
  currency: z.string().min(1),
  charge_due_day: z.coerce.number({ message: 'روز سررسید را وارد کنید' }).int().min(1, 'بین ۱ تا ۳۱').max(31, 'بین ۱ تا ۳۱'),
  payment_gateway: z.string().min(1),
  gw_terminal_id: z.string().max(50).optional(),
  gw_username: z.string().max(100).optional(),
  gw_password: z.string().max(100).optional(),
  messenger_enabled: z.boolean().optional(),
  good_payer_enabled: z.boolean().optional(),
  penalty_enabled: z.boolean().optional(),
  penalty_type: z.string().min(1),
  penalty_value: z.coerce.number({ message: 'مقدار جریمه را وارد کنید' }).min(0, 'نمی‌تواند منفی باشد'),
  penalty_grace_days: z.coerce.number({ message: 'روزهای مهلت را وارد کنید' }).int().min(0).max(60, 'حداکثر ۶۰ روز'),
})

type SettingsInput = z.input<typeof settingsSchema>
type SettingsValues = z.output<typeof settingsSchema>

interface Option {
  value: string
  label: string
}

interface SettingsResponse {
  settings: Omit<SettingsValues, 'gw_password'> & { gw_password_set: boolean }
  options: { currencies: Option[]; gateways: Option[]; penaltyTypes: Option[] }
}

export function ComplexSettingsPage() {
  const [formError, setFormError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  useDocumentTitle('تنظیمات مجتمع')

  const { data, error, isLoading, reload } = useApi<SettingsResponse>('/settings')

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<SettingsInput, unknown, SettingsValues>({
    resolver: zodResolver(settingsSchema),
  })

  // مقادیر فرم پس از رسیدن پاسخ پر می‌شوند؛ رمز درگاه هرگز از سرور نمی‌آید.
  useEffect(() => {
    if (data) reset({ ...data.settings, gw_password: '' })
  }, [data, reset])

  async function onSubmit(values: SettingsValues) {
    setFormError(null)
    setSaved(false)

    try {
      await api('/settings', { method: 'PUT', body: values })
      setSaved(true)
      reload()
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          setError(field as keyof SettingsInput, { message: messages[0] })
          handled = true
        }
        if (!handled) setFormError(err.message)
      } else {
        setFormError('ارتباط با سرور برقرار نشد.')
      }
    }
  }

  if (isLoading) return <LoadingState rows={5} />
  if (error) return <ErrorState message={error} onRetry={reload} />
  if (!data) return null

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          تنظیمات مجتمع
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          مشخصات، درگاه پرداخت و قواعد جریمه دیرکرد
        </p>
      </header>

      {formError && (
        <div
          className="flex items-center gap-2 rounded-xl px-4 py-3 text-sm"
          style={{
            backgroundColor: 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
            color: 'var(--color-danger)',
          }}
        >
          <AlertCircle size={16} />
          {formError}
        </div>
      )}

      {saved && (
        <div
          className="flex items-center gap-2 rounded-xl px-4 py-3 text-sm"
          style={{
            backgroundColor: 'color-mix(in srgb, var(--state-success) 14%, transparent)',
            color: 'var(--state-success)',
          }}
        >
          <CheckCircle2 size={16} />
          تنظیمات ذخیره شد.
        </div>
      )}

      <Card title="مشخصات مجتمع">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <TextField label="نام مجتمع" error={errors.name?.message} {...register('name')} />
          <TextField label="تلفن" dir="ltr" error={errors.phone?.message} {...register('phone')} />
          <div className="sm:col-span-2">
            <TextField label="آدرس" error={errors.address?.message} {...register('address')} />
          </div>
          <SelectField
            label="واحد پول"
            options={data.options.currencies}
            error={errors.currency?.message}
            {...register('currency')}
          />
          <TextField
            label="روز سررسید شارژ (روز ماه)"
            type="number"
            error={errors.charge_due_day?.message}
            {...register('charge_due_day')}
          />
        </div>
      </Card>

      <Card title="درگاه پرداخت" subtitle="اعتبارنامه فقط روی سرور نگهداری می‌شود و در پاسخ API برنمی‌گردد" delay={0.05}>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <SelectField
            label="درگاه"
            options={data.options.gateways}
            error={errors.payment_gateway?.message}
            {...register('payment_gateway')}
          />
          <TextField label="شناسه ترمینال" dir="ltr" error={errors.gw_terminal_id?.message} {...register('gw_terminal_id')} />
          <TextField label="نام کاربری درگاه" dir="ltr" error={errors.gw_username?.message} {...register('gw_username')} />
          <TextField
            label={data.settings.gw_password_set ? 'رمز درگاه (برای تغییر پر کنید)' : 'رمز درگاه'}
            type="password"
            dir="ltr"
            placeholder={data.settings.gw_password_set ? '••••••••' : ''}
            error={errors.gw_password?.message}
            {...register('gw_password')}
          />
        </div>
      </Card>

      <Card title="جریمه دیرکرد" delay={0.1}>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <SelectField
            label="نوع جریمه"
            options={data.options.penaltyTypes}
            error={errors.penalty_type?.message}
            {...register('penalty_type')}
          />
          <TextField
            label="مقدار"
            type="number"
            step="0.01"
            error={errors.penalty_value?.message}
            {...register('penalty_value')}
          />
          <TextField
            label="روزهای مهلت"
            type="number"
            error={errors.penalty_grace_days?.message}
            {...register('penalty_grace_days')}
          />
        </div>
        <div className="mt-4">
          <CheckField label="اعمال جریمه دیرکرد فعال باشد" {...register('penalty_enabled')} />
        </div>
      </Card>

      <Card title="امکانات" delay={0.15}>
        <div className="flex flex-col gap-3">
          <CheckField label="پیام‌رسان داخلی فعال باشد" {...register('messenger_enabled')} />
          <CheckField label="نمایش ساکنین خوش‌حساب فعال باشد" {...register('good_payer_enabled')} />
        </div>
      </Card>

      <div>
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-bold text-white disabled:opacity-70"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          ذخیره تنظیمات
        </button>
      </div>
    </form>
  )
}
