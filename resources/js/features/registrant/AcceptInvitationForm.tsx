import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Check, Loader2 } from 'lucide-react'
import { TextField } from '@/shared/ui/Field'
import { api, ApiError } from '@/shared/lib/api'
import { toAsciiDigits } from '@/shared/lib/inputFilters'
import {
  acceptInvitationSchema,
  type AcceptInvitationValues,
} from './schemas/acceptInvitationSchema'

/**
 * گامِ کدِ پذیرشِ دعوت (مرحلهٔ ۹ · فاز ۶؛ تصمیمِ کارفرما: OTP ِ تازه).
 *
 * کد همین حالا به شماره‌ی خودِ کاربر رفته است. دستگاهِ مورداعتماد این گام را
 * دور نمی‌زند: دعوت به‌تنهایی دسترسی نمی‌دهد و پذیرش اثباتِ تازه‌ی مالکیتِ
 * شماره است.
 */
export function AcceptInvitationForm({
  invitationId,
  message,
  devCode,
  onCancel,
}: {
  invitationId: number
  message: string
  devCode: string | null
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<AcceptInvitationValues>({
    resolver: zodResolver(acceptInvitationSchema),
    defaultValues: { code: '' },
  })

  async function onSubmit(values: AcceptInvitationValues) {
    setFormError(null)

    try {
      await api(`/invitations/${invitationId}/accept`, {
        method: 'POST',
        body: { code: toAsciiDigits(values.code) },
      })
      /*
       * پذیرش، حالتِ حساب را عوض می‌کند و کلِ ناوبری باید از نو ساخته شود.
       * بارگذاریِ کاملِ صفحه ساده‌ترین راهِ درست است.
       */
      window.location.replace('/dashboard')
    } catch (error) {
      if (error instanceof ApiError && error.errors.code) {
        setError('code', { message: error.errors.code[0] })
      } else {
        setFormError(error instanceof ApiError ? error.message : 'ارتباط با سرور برقرار نشد.')
      }
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex w-full flex-col gap-3" noValidate>
      <p className="text-xs leading-6" style={{ color: 'var(--text-secondary)' }}>
        {message}
        {devCode && (
          <span className="ms-1 tabular-nums" dir="ltr">
            ({devCode})
          </span>
        )}
      </p>

      <TextField
        label="کدِ تأیید"
        inputMode="numeric"
        autoComplete="one-time-code"
        dir="ltr"
        maxLength={6}
        error={errors.code?.message}
        {...register('code')}
      />

      {formError && (
        <p className="text-xs" role="alert" style={{ color: 'var(--color-danger)' }}>
          {formError}
        </p>
      )}

      <div className="flex gap-2">
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex flex-1 items-center justify-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold text-white disabled:opacity-60"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
          تأیید و پیوستن
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="rounded-xl border px-3 py-2 text-xs font-semibold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
        >
          انصراف
        </button>
      </div>
    </form>
  )
}
