import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, ShieldCheck } from 'lucide-react'
import { z } from 'zod'
import { api, ApiError } from '@/shared/lib/api'
import { useReauthStore } from '@/shared/stores/reauthStore'
import { TextField } from './Field'
import { Modal } from './Modal'

/**
 * پنجره‌ی «رمزتان را دوباره بزنید» (مرحلهٔ ۵ · فاز ۱۰).
 *
 * خودش هیچ‌جا باز نمی‌شود: سرور پیش از عملیاتِ حساس `auth.reauth_required`
 * می‌دهد، `api()` این پنجره را از `reauthStore` می‌خواهد، و پس از تأیید همان
 * درخواست را دوباره می‌فرستد. کاربر فقط می‌بیند که کارش پس از زدنِ رمز
 * انجام شد.
 */
export function ReauthDialog() {
  const { open, settle } = useReauthStore()

  if (!open) return null

  return <ReauthForm settle={settle} />
}

const schema = z.object({ password: z.string().min(1, 'رمز عبور را وارد کنید.') })

type ReauthValues = z.infer<typeof schema>

function ReauthForm({ settle }: { settle: (confirmed: boolean) => void }) {
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ReauthValues>({ resolver: zodResolver(schema), defaultValues: { password: '' } })

  async function submit({ password }: ReauthValues) {
    try {
      await api('/profile/confirm-password', { method: 'POST', body: { password } })
      settle(true)
    } catch (caught) {
      setError('password', {
        message:
          caught instanceof ApiError
            ? (caught.fieldError('password') ?? caught.message)
            : 'تأیید ممکن نشد. دوباره تلاش کنید.',
      })
    }
  }

  return (
    <Modal open title="تأییدِ رمز عبور" onClose={() => settle(false)}>
      <form onSubmit={handleSubmit(submit)} className="flex flex-col gap-4 p-5">
        <p
          className="flex items-start gap-2 text-small leading-6"
          style={{ color: 'var(--text-secondary)' }}
        >
          <ShieldCheck
            size={18}
            className="mt-0.5 shrink-0"
            style={{ color: 'var(--color-brand-500)' }}
          />
          این کار حساس است. برای اطمینان از اینکه خودتان هستید، رمز عبورتان را دوباره وارد کنید.
        </p>

        <TextField
          label="رمز عبور"
          type="password"
          autoComplete="current-password"
          {...register('password')}
          error={errors.password?.message}
        />

        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={() => settle(false)}
            className="rounded-xl border px-4 py-2 text-small"
            style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
          >
            انصراف
          </button>
          <button
            type="submit"
            disabled={isSubmitting}
            className="flex items-center gap-2 rounded-xl px-4 py-2 text-small font-semibold text-white disabled:opacity-50"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {isSubmitting && <Loader2 size={16} className="animate-spin" />}
            تأیید
          </button>
        </div>
      </form>
    </Modal>
  )
}
