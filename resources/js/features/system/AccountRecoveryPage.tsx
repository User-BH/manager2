import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, Smartphone, X } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { TextAreaField } from '@/shared/ui/Field'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { TableSkeleton } from '@/shared/ui/Skeleton'
import { useApiAction } from '@/shared/hooks/useAction'
import { useDocumentTitle } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'

interface RecoveryRequest {
  id: number
  status: 'pending' | 'approved' | 'rejected'
  decidable: boolean
  oldPhone: string
  newPhone: string
  explanation: string
  nationalIdMatches: boolean | null
  account: {
    id: number
    name: string
    role: string
    complex: string | null
    isActive: boolean
    phoneStillMatches: boolean
  } | null
  createdAt: string | null
  decidedAt: string | null
  decidedBy: string | null
  decisionNote: string | null
}

const STATUS_LABEL: Record<RecoveryRequest['status'], string> = {
  pending: 'در انتظارِ بررسی',
  approved: 'تأییدشده',
  rejected: 'ردشده',
}

/**
 * صفِ بازیابیِ حساب (مرحلهٔ ۹ · تصمیمِ کارفرما) — فقط ادمینِ کل.
 *
 * شواهد کنارِ هم: حسابِ منطبق با شماره‌ی قبلی، انطباقِ کدِ ملی، و شرحِ
 * درخواست‌کننده. «بررسیِ مالکیت» (تماس، مدرک، تأییدِ مدیرِ مجتمع) بیرون از
 * سامانه است؛ تصمیم و دلیلش این‌جا ثبت و در لاگِ فعالیت ماندگار می‌شود.
 */
export function AccountRecoveryPage() {
  useDocumentTitle('بازیابی حساب‌ها')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.system.accountRecovery(),
    queryFn: ({ signal }) =>
      api<{ data: RecoveryRequest[] }>('/system/account-recovery', { signal }),
  })

  if (isLoading) return <TableSkeleton rows={4} columns={4} />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />

  const requests = data?.data ?? []

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          بازیابی حساب‌ها
        </h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-tertiary)' }}>
          درخواست‌های کسانی که به شماره‌ی قبلیِ حسابشان دسترسی ندارند. شماره‌ی تازه با کدِ پیامکی
          ثابت شده؛ مالکیتِ حساب را پیش از تأیید بیرون از سامانه بسنجید.
        </p>
      </header>

      {requests.length === 0 ? (
        <EmptyState message="درخواستی برای بازیابی ثبت نشده است." />
      ) : (
        requests.map((request) => <RecoveryCard key={request.id} request={request} />)
      )}
    </div>
  )
}

function RecoveryCard({ request }: { request: RecoveryRequest }) {
  const { call, isPending } = useApiAction()
  const [note, setNote] = useState('')
  const busy = isPending(request.id)
  const invalidate = [queryKeys.system.accountRecovery()]

  const approve = () =>
    void call(
      `/system/account-recovery/${request.id}/approve`,
      { method: 'POST', body: { note: note.trim() || null } },
      {
        key: request.id,
        confirm: {
          title: 'تأییدِ بازیابی',
          text: `شماره‌ی حساب از ${request.oldPhone} به ${request.newPhone} عوض و همه‌ی نشست‌هایش بسته می‌شود.`,
        },
        success: 'شماره‌ی حساب تغییر کرد.',
        invalidate,
      },
    )

  const reject = () =>
    void call(
      `/system/account-recovery/${request.id}/reject`,
      { method: 'POST', body: { note: note.trim() } },
      { key: request.id, success: 'درخواست رد شد.', invalidate },
    )

  return (
    <Card
      title={`${request.oldPhone} ← ${request.newPhone}`}
      subtitle={`${STATUS_LABEL[request.status]} · ${request.createdAt ?? ''}`}
    >
      <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
        <Fact label="حسابِ منطبق">
          {request.account
            ? `${request.account.name} · ${request.account.role}${request.account.complex ? ` · ${request.account.complex}` : ''}${request.account.isActive ? '' : ' · غیرفعال'}`
            : 'هیچ حسابی با این شماره نیست'}
        </Fact>
        <Fact label="کد ملی">
          {request.nationalIdMatches === null
            ? 'قابلِ سنجش نیست'
            : request.nationalIdMatches
              ? 'با حساب می‌خواند'
              : 'با حساب نمی‌خواند'}
        </Fact>
        <Fact label="شرحِ درخواست‌کننده" wide>
          {request.explanation}
        </Fact>
        {request.decidedAt && (
          <Fact label="تصمیم" wide>
            {`${request.decidedBy ?? ''} · ${request.decidedAt}${request.decisionNote ? ` — ${request.decisionNote}` : ''}`}
          </Fact>
        )}
      </dl>

      {request.decidable && request.account && (
        <div className="mt-4 flex flex-col gap-3">
          <TextAreaField
            label="یادداشتِ تصمیم (برای رد الزامی)"
            rows={2}
            value={note}
            onChange={(event) => setNote(event.target.value)}
          />
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              disabled={busy}
              onClick={approve}
              className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-bold text-white disabled:opacity-60"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              <Check size={15} />
              تأیید و تغییرِ شماره
            </button>
            <button
              type="button"
              disabled={busy}
              onClick={reject}
              className="flex items-center gap-1.5 rounded-xl border px-4 py-2 text-sm font-bold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--color-danger)' }}
            >
              <X size={15} />
              رد
            </button>
          </div>
        </div>
      )}
      {request.status === 'pending' && !request.decidable && (
        <p
          className="mt-3 flex items-center gap-1.5 text-xs"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <Smartphone size={13} />
          {request.account
            ? 'اثباتِ شماره‌ی تازه کهنه شده؛ درخواست‌کننده باید دوباره درخواست دهد.'
            : 'حسابی برای این شماره نیست؛ فقط می‌توان رد کرد.'}
        </p>
      )}
      {request.status === 'pending' && !request.account && (
        <div className="mt-3 flex flex-col gap-3">
          <TextAreaField
            label="دلیلِ رد"
            rows={2}
            value={note}
            onChange={(event) => setNote(event.target.value)}
          />
          <button
            type="button"
            disabled={busy}
            onClick={reject}
            className="flex w-fit items-center gap-1.5 rounded-xl border px-4 py-2 text-sm font-bold disabled:opacity-60"
            style={{ borderColor: 'var(--border-default)', color: 'var(--color-danger)' }}
          >
            <X size={15} />
            رد
          </button>
        </div>
      )}
    </Card>
  )
}

function Fact({
  label,
  children,
  wide,
}: {
  label: string
  children: React.ReactNode
  wide?: boolean
}) {
  return (
    <div className={wide ? 'sm:col-span-2' : undefined}>
      <dt className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
        {label}
      </dt>
      <dd className="mt-0.5 leading-6" style={{ color: 'var(--text-primary)' }}>
        {children}
      </dd>
    </div>
  )
}
