import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Building2, Check, X } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useAction } from '@/shared/hooks/useAction'
import type { Invitation } from '@/shared/types'
import { AcceptInvitationForm } from './AcceptInvitationForm'

/*
 * دعوت‌های دریافتیِ خودِ حساب (R21؛ از مرحلهٔ ۱۰ · فاز ۴ جدا از داشبوردِ اولیه).
 *
 * حسابِ اولیه آن‌ها را در داشبوردِ خودش می‌بیند؛ عضوِ مجتمعی که به مجتمعِ دیگری دعوت
 * شده، در «پروفایلِ من» — پذیرش عضویتِ **دوم** است و عضویتِ فعلی دست نمی‌خورد.
 */

/** گامِ کدِ پذیرش برای یک دعوت (مرحلهٔ ۹ · فاز ۶) */
interface CodeStep {
  id: number
  message: string
  devCode: string | null
}

export function ReceivedInvitations() {
  const { run, pendingKey } = useAction()
  const [codeStep, setCodeStep] = useState<CodeStep | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.invitations.all(),
    // ⚠️ `signal` تنها چیزی است که با unmount یا تغییرِ مسیر درخواست را لغو می‌کند (فاز ۳)
    queryFn: ({ signal }) => api<{ data: Invitation[] }>('/invitations', { signal }),
  })

  /*
   * «پیوستن» اول کدِ تازه به شماره‌ی خودِ کاربر می‌فرستد (مرحلهٔ ۹ · فاز ۶) و پذیرش در
   * `AcceptInvitationForm` با همان کد است. از ۱۲/۳ (بندِ ۱۵) همیشه — کلیدِ `otp_disabled` ِ
   * ادمینِ کل فقط گامِ دومِ ورود را برمی‌دارد، نه کدِ پذیرش را.
   */
  const accept = (id: number) =>
    run(
      async () => {
        const step = await api<{ message?: string; devCode?: string | null }>(
          `/invitations/${id}/code`,
          { method: 'POST' },
        )

        setCodeStep({
          id,
          message: step.message ?? 'کدِ تأیید فرستاده شد.',
          devCode: step.devCode ?? null,
        })
      },
      { key: id },
    )

  const decline = (id: number) =>
    run(() => api(`/invitations/${id}/decline`, { method: 'POST' }), {
      key: id,
      success: 'دعوت رد شد.',
      invalidate: [queryKeys.invitations.all()],
    })

  if (isLoading || !data?.data.length) return null

  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
        دعوت‌های شما
      </h2>

      {data.data.map((invitation) => (
        <div
          key={invitation.id}
          className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-4"
          style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-base)' }}
        >
          <div className="flex items-start gap-3">
            <Building2
              size={18}
              className="mt-0.5 shrink-0"
              style={{ color: 'var(--color-brand-500)' }}
            />
            <div>
              <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                {invitation.complexName}
              </p>
              <p className="mt-0.5 text-xs" style={{ color: 'var(--text-tertiary)' }}>
                {[invitation.unitLabel, `به عنوان ${invitation.roleLabel}`, invitation.invitedBy]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
            </div>
          </div>

          {codeStep?.id === invitation.id ? (
            <AcceptInvitationForm
              invitationId={invitation.id}
              message={codeStep.message}
              devCode={codeStep.devCode}
              onCancel={() => setCodeStep(null)}
            />
          ) : (
            <div className="flex gap-2">
              <button
                onClick={() => void accept(invitation.id)}
                disabled={pendingKey !== null}
                className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold text-white disabled:opacity-60"
                style={{ backgroundColor: 'var(--color-brand-500)' }}
              >
                <Check size={14} />
                پیوستن
              </button>
              <button
                onClick={() => void decline(invitation.id)}
                disabled={pendingKey !== null}
                className="flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold disabled:opacity-60"
                style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
              >
                <X size={14} />
                رد
              </button>
            </div>
          )}
        </div>
      ))}
    </section>
  )
}
