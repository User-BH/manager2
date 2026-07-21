import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { KeyRound, Loader2 } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/components/ui/Card'
import { TextField } from '@/components/ui/Field'
import { api, ApiError } from '@/lib/api'
import { alertError, toastSuccess } from '@/lib/alert'
import { strongPassword } from '@/lib/validation'

const schema = z
  .object({
    current_password: z.string().min(1, 'رمز عبور فعلی را وارد کنید.'),
    password: strongPassword,
    password_confirmation: z.string(),
  })
  // بررسی تطابق سمت کلاینت هم انجام می‌شود تا کاربر بی‌دلیل منتظر سرور نماند
  .refine((values) => values.password === values.password_confirmation, {
    message: 'تکرار رمز عبور مطابقت ندارد.',
    path: ['password_confirmation'],
  })
  // رمز جدید نباید همان رمز فعلی باشد
  .refine((values) => values.password !== values.current_password, {
    message: 'رمز جدید باید با رمز فعلی متفاوت باشد.',
    path: ['password'],
  })

type PasswordValues = z.infer<typeof schema>

export function PasswordCard() {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<PasswordValues>({ resolver: zodResolver(schema) })

  async function onSubmit(values: PasswordValues) {
    try {
      await api('/profile/password', { method: 'PUT', body: values })

      toastSuccess('رمز عبور تغییر کرد.')
      reset()
    } catch (error) {
      if (error instanceof ApiError && error.fieldError('current_password')) {
        setError('current_password', { message: error.fieldError('current_password') })
        return
      }
      alertError(error, 'تغییر رمز عبور ممکن نشد.')
    }
  }

  return (
    <Card title="تغییر رمز عبور" subtitle="پس از تغییر، نشست فعلی باز می‌ماند." delay={0.1}>
      <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
        <TextField
          label="رمز عبور فعلی"
          type="password"
          autoComplete="current-password"
          error={errors.current_password?.message}
          {...register('current_password')}
        />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <TextField
            label="رمز عبور جدید"
            type="password"
            autoComplete="new-password"
            error={errors.password?.message}
            {...register('password')}
          />
          <TextField
            label="تکرار رمز عبور جدید"
            type="password"
            autoComplete="new-password"
            error={errors.password_confirmation?.message}
            {...register('password_confirmation')}
          />
        </div>

        <button
          type="submit"
          disabled={isSubmitting}
          className="flex w-fit items-center gap-1.5 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white disabled:opacity-60"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={15} className="animate-spin" /> : <KeyRound size={15} />}
          تغییر رمز عبور
        </button>
      </form>
    </Card>
  )
}
