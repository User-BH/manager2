import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Save, Send, AlertCircle, CheckCircle2 } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/shared/ui/Card'
import { SelectField, TextField } from '@/shared/ui/Field'
import { ErrorState } from '@/shared/ui/PageState'
import { FormSkeleton } from '@/shared/ui/Skeleton'
import { useQuery } from '@tanstack/react-query'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { api, ApiError } from '@/shared/lib/api'

const smsSchema = z.object({
  sms_driver: z.string().min(1, 'سامانه پیامک را انتخاب کنید'),
  apikey: z.string().max(255).optional(),
  sender: z.string().max(30).optional(),
  username: z.string().max(100).optional(),
  password: z.string().max(100).optional(),
  pattern_code: z.string().max(50).optional(),
  pattern_variable: z.string().max(50).optional(),
  phonebook_id: z.string().max(20).optional(),
  otp_disabled: z.boolean().optional(),
})

type SmsFormValues = z.infer<typeof smsSchema>

interface SmsResponse {
  settings: SmsFormValues & { password_set: boolean }
  drivers: { value: string; label: string }[]
}

export function SmsPage() {
  const [formError, setFormError] = useState<string | null>(null)
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)
  const [testPhone, setTestPhone] = useState('')
  const [testing, setTesting] = useState(false)

  useDocumentTitle('پنل پیامک')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.system.sms(),
    queryFn: ({ signal }) => api<SmsResponse>('/system/sms', { signal }),
  })

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<SmsFormValues>({ resolver: zodResolver(smsSchema) })

  useEffect(() => {
    if (data) reset({ ...data.settings, password: '' })
  }, [data, reset])

  const driver = watch('sms_driver')

  async function onSubmit(values: SmsFormValues) {
    setFormError(null)
    setNotice(null)

    try {
      await api('/system/sms', { method: 'PUT', body: values })
      setNotice({ ok: true, text: 'تنظیمات پنل پیامک ذخیره شد.' })
      void refetch()
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          setError(field as keyof SmsFormValues, { message: messages[0] })
          handled = true
        }
        if (!handled) setFormError(err.message)
      } else {
        setFormError('ارتباط با سرور برقرار نشد.')
      }
    }
  }

  async function sendTest() {
    setTesting(true)
    setNotice(null)

    try {
      const result = await api<{ ok: boolean; message: string }>('/system/sms/test', {
        method: 'POST',
        body: { phone: testPhone },
      })
      setNotice({ ok: result.ok, text: result.message })
    } catch (err) {
      setNotice({ ok: false, text: err instanceof ApiError ? err.message : 'ارسال ناموفق بود.' })
    } finally {
      setTesting(false)
    }
  }

  if (isLoading) return <FormSkeleton fields={5} />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data) return null

  // ملی‌پیامک با نام کاربری/رمز کار می‌کند، بقیه با API key
  const usesCredentials = driver === 'melipayamak'
  const usesApiKey = driver === 'kavenegar' || driver === 'ippanel'
  const usesPattern = driver === 'ippanel'

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          پنل پیامک
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          سامانهٔ ارسال پیامک — فقط برای کد یک‌بارمصرفِ ورود و بازیابی رمز (OTP)
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

      {notice && (
        <div
          className="flex items-center gap-2 rounded-xl px-4 py-3 text-sm"
          style={{
            backgroundColor: `color-mix(in srgb, ${notice.ok ? 'var(--state-success)' : 'var(--color-danger)'} 13%, transparent)`,
            color: notice.ok ? 'var(--state-success)' : 'var(--color-danger)',
          }}
        >
          {notice.ok ? <CheckCircle2 size={16} /> : <AlertCircle size={16} />}
          {notice.text}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)}>
        <Card title="سامانهٔ پیامک">
          <div className="flex flex-col gap-4">
            <SelectField
              label="سامانه"
              options={data.drivers}
              error={errors.sms_driver?.message}
              {...register('sms_driver')}
            />

            {driver === 'log' && (
              <p
                className="rounded-xl px-3.5 py-2.5 text-[12px] leading-6"
                style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-tertiary)' }}
              >
                در حالت تست، پیامک واقعی ارسال نمی‌شود و متن آن در
                <span dir="ltr" className="mx-1">
                  storage/logs/laravel.log
                </span>
                ثبت می‌گردد. برای سرویس واقعی یکی از سامانه‌های بالا را انتخاب کنید.
              </p>
            )}

            {(usesApiKey || usesCredentials) && (
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {usesApiKey && (
                  <TextField
                    label="API Key"
                    dir="ltr"
                    error={errors.apikey?.message}
                    {...register('apikey')}
                  />
                )}

                {usesCredentials && (
                  <>
                    <TextField
                      label="نام کاربری"
                      dir="ltr"
                      error={errors.username?.message}
                      {...register('username')}
                    />
                    <TextField
                      label={
                        data.settings.password_set
                          ? 'رمز وب‌سرویس (برای تغییر پر کنید)'
                          : 'رمز وب‌سرویس'
                      }
                      type="password"
                      dir="ltr"
                      placeholder={data.settings.password_set ? '••••••••' : ''}
                      error={errors.password?.message}
                      {...register('password')}
                    />
                  </>
                )}

                <TextField
                  label="شماره خط ارسال"
                  dir="ltr"
                  error={errors.sender?.message}
                  {...register('sender')}
                />
              </div>
            )}

            {/* پترنِ ایپ‌پنل برای ارسالِ کدِ ورود (OTP سریع‌تر می‌رسد). خالی
                بگذارید تا پیامکِ متنیِ ساده فرستاده شود. */}
            {usesPattern && (
              <div
                className="grid grid-cols-1 gap-4 rounded-2xl border p-4 sm:grid-cols-2"
                style={{
                  borderColor: 'var(--border-subtle)',
                  backgroundColor: 'var(--surface-sunken)',
                }}
              >
                <div
                  className="sm:col-span-2 -mb-1 text-[12.5px] font-semibold"
                  style={{ color: 'var(--text-secondary)' }}
                >
                  ارسال کد ورود با پترن (اختیاری، فقط برای OTP)
                </div>
                <TextField
                  label="کد پترن"
                  dir="ltr"
                  placeholder="مثلاً 123456"
                  error={errors.pattern_code?.message}
                  {...register('pattern_code')}
                />
                <TextField
                  label="نام متغیر کد در پترن"
                  dir="ltr"
                  placeholder="code"
                  error={errors.pattern_variable?.message}
                  {...register('pattern_variable')}
                />
                <TextField
                  label="شناسه دفترچه تلفن (اختیاری)"
                  dir="ltr"
                  placeholder="مثلاً 1234"
                  inputMode="numeric"
                  error={errors.phonebook_id?.message}
                  {...register('phonebook_id')}
                />
                <p
                  className="sm:col-span-2 -mt-1 text-[11.5px] leading-6"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  اگر شناسه‌ی یک دفترچه تلفن را وارد کنید، شماره‌ی هر گیرنده هنگام ارسالِ کد در همان
                  دفترچه ذخیره می‌شود.
                </p>
                <p
                  className="sm:col-span-2 text-[11.5px] leading-6"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  در پنل ایپ‌پنل یک پترن با متغیرِ <span dir="ltr">%code%</span> بسازید و «نام
                  متغیر» را همان <span dir="ltr">code</span> بگذارید. برای پرشدنِ خودکارِ کد روی{' '}
                  <b>اندروید</b>، آخرین خطِ پترن باید دقیقاً به شکلِ{' '}
                  <span dir="ltr" className="mx-1">
                    @دامنه‌ی‌سایت #%code%
                  </span>{' '}
                  باشد (مثلاً
                  <span dir="ltr" className="mx-1">
                    @sakena.app #%code%
                  </span>
                  )؛ روی <b>iOS</b> بدونِ این هم کد پیشنهاد می‌شود.
                </p>
              </div>
            )}

            {/*
              ورودِ بدونِ کدِ پیامکی: مرحله‌ی دومِ ورود کلاً حذف می‌شود و کاربر
              پس از رمز مستقیم وارد داشبورد می‌شود. برای وقتی پنلِ پیامک هنوز
              آماده نیست؛ امنیتِ ورود را پایین می‌آورد.
            */}
            <label
              className="flex cursor-pointer items-start gap-3 rounded-2xl border p-4"
              style={{
                borderColor: 'color-mix(in srgb, var(--color-warning) 45%, var(--border-subtle))',
                backgroundColor: 'color-mix(in srgb, var(--color-warning) 8%, transparent)',
              }}
            >
              <input
                type="checkbox"
                className="mt-0.5 h-4 w-4 rounded"
                {...register('otp_disabled')}
              />
              <span>
                <span className="text-[13px] font-bold" style={{ color: 'var(--text-primary)' }}>
                  ورود بدون کد یک‌بارمصرف (OTP)
                </span>
                <span
                  className="mt-1 block text-[11.5px] leading-6"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  با روشن‌کردنِ این گزینه، مرحله‌ی دومِ ورود حذف می‌شود و کاربر پس از واردکردنِ رمز
                  مستقیم وارد داشبورد می‌شود. فقط زمانی که پنلِ پیامک هنوز آماده نیست از آن استفاده
                  کنید.
                </span>
              </span>
            </label>

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
          </div>
        </Card>
      </form>

      <Card
        title="ارسال آزمایشی"
        subtitle="پیش از استفادهٔ واقعی، اتصال را بررسی کنید"
        delay={0.05}
      >
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[220px] flex-1">
            <TextField
              label="شماره موبایل"
              dir="ltr"
              inputMode="numeric"
              placeholder="۰۹xxxxxxxxx"
              value={testPhone}
              onChange={(e) => setTestPhone(e.target.value)}
            />
          </div>

          <button
            type="button"
            onClick={sendTest}
            disabled={testing || !testPhone}
            className="flex items-center gap-1.5 rounded-xl px-5 py-3 text-[13px] font-bold text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-accent-500)' }}
          >
            {testing ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
            ارسال آزمایشی
          </button>
        </div>
      </Card>
    </div>
  )
}
