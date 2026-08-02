import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import {
  ArrowRight,
  CreditCard,
  Upload,
  Loader2,
  AlertCircle,
  CheckCircle2,
  Wallet,
} from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/shared/ui/Card'
import { TextField } from '@/shared/ui/Field'
import { ErrorState } from '@/shared/ui/PageState'
import { FormSkeleton } from '@/shared/ui/Skeleton'
import { useQuery } from '@tanstack/react-query'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { api, ApiError } from '@/shared/lib/api'
import { formatMoney } from '@/shared/lib/format'
import { toastSuccess } from '@/shared/lib/alert'

const receiptSchema = z.object({
  amount: z.coerce.number({ message: 'مبلغ را وارد کنید' }).min(1000, 'مبلغ باید حداقل ۱۰۰۰ باشد'),
  paid_on: z.string().optional(),
  description: z.string().max(500).optional(),
})

type ReceiptInput = z.input<typeof receiptSchema>
type ReceiptValues = z.output<typeof receiptSchema>

interface PayResponse {
  bill: {
    id: number
    unitLabel: string
    periodLabel: string
    totalAmount: number
    paidAmount: number
    remaining: number
    statusLabel: string
    dueDate: string | null
  }
  currency: string
  onlineEnabled: boolean
  onlineAction: string
  /** کارتِ مقصدِ کارت‌به‌کارت؛ اگر مدیر تعریف نکرده باشد null است (R22). */
  card: { number?: string; holder?: string; bank?: string } | null
  /** موجودیِ کیفِ پولِ همین واحد (R22). */
  walletBalance: number
}

export function PayBillPage() {
  const { billId } = useParams()
  const [done, setDone] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  const [payingFromWallet, setPayingFromWallet] = useState(false)

  useDocumentTitle('پرداخت قبض')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.payments.forBill(billId ?? ''),
    queryFn: ({ signal }) => api<PayResponse>(`/pay/${billId}`, { signal }),
  })

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ReceiptInput, unknown, ReceiptValues>({ resolver: zodResolver(receiptSchema) })

  async function onSubmit(values: ReceiptValues) {
    const file = fileRef.current?.files?.[0]

    if (!file) {
      setFormError('فایل رسید را انتخاب کنید.')
      return
    }

    setFormError(null)

    const form = new FormData()
    form.append('amount', String(values.amount))
    if (values.paid_on) form.append('paid_on', values.paid_on)
    if (values.description) form.append('description', values.description)
    form.append('receipt', file)

    try {
      const result = await api<{ message: string }>(`/pay/${billId}/receipt`, {
        method: 'POST',
        body: form,
      })
      setDone(result.message)
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          if (field === 'receipt') {
            setFormError(messages[0])
            handled = true
            continue
          }
          setError(field as keyof ReceiptInput, { message: messages[0] })
          handled = true
        }
        if (!handled) setFormError(err.message)
      } else {
        setFormError('ارتباط با سرور برقرار نشد.')
      }
    }
  }

  /**
   * پرداخت از کیف پول.
   *
   * پس از موفقیت صفحه دوباره خوانده می‌شود تا هم مانده‌ی قبض و هم موجودیِ
   * کیف تازه شوند — هر دو را سرور می‌دهد و اینجا چیزی حساب نمی‌شود.
   */
  async function payFromWallet() {
    setPayingFromWallet(true)
    setFormError(null)
    try {
      const result = await api<{ message: string }>(`/wallet/pay/${billId}`, { method: 'POST' })
      toastSuccess(result.message)
      void refetch()
    } catch (error) {
      setFormError(error instanceof ApiError ? error.message : 'پرداخت از کیف پول ممکن نشد.')
    } finally {
      setPayingFromWallet(false)
    }
  }

  if (isLoading) return <FormSkeleton fields={3} />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data) return null

  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''

  return (
    <div className="flex flex-col gap-5">
      <header>
        <Link
          to="/my-bills"
          className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium"
          style={{ color: 'var(--text-secondary)' }}
        >
          <ArrowRight size={15} />
          بازگشت به صورت‌حساب‌ها
        </Link>

        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          پرداخت قبض {data.bill.periodLabel}
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          {data.bill.unitLabel}
          {data.bill.dueDate && ` · سررسید ${data.bill.dueDate}`}
        </p>
      </header>

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <span className="text-[13px]" style={{ color: 'var(--text-secondary)' }}>
            مبلغ قابل پرداخت
          </span>
          <span
            className="tabular-nums text-2xl font-extrabold"
            style={{ color: 'var(--state-success)' }}
          >
            {formatMoney(data.bill.remaining)}{' '}
            <span className="text-sm font-normal" style={{ color: 'var(--text-tertiary)' }}>
              {data.currency}
            </span>
          </span>
        </div>
      </Card>

      {data.onlineEnabled ? (
        <Card title="پرداخت آنلاین" delay={0.05}>
          {/*
            فرم واقعی به روت وب می‌رود، نه fetch: مرورگر باید از سایت ما خارج
            و به درگاه بانک منتقل شود.
          */}
          <form method="POST" action={data.onlineAction}>
            <input type="hidden" name="_token" value={csrf} />
            <button
              type="submit"
              className="flex w-full items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold text-white transition-transform hover:scale-[1.01]"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              <CreditCard size={17} />
              انتقال به درگاه بانکی
            </button>
          </form>
        </Card>
      ) : (
        <div
          className="flex items-center gap-2 rounded-xl px-4 py-3 text-[13px]"
          style={{
            backgroundColor: 'color-mix(in srgb, var(--color-accent-500) 13%, transparent)',
            color: 'var(--color-accent-600)',
          }}
        >
          <AlertCircle size={16} />
          درگاه پرداخت آنلاین برای این مجتمع فعال نیست. لطفاً رسید واریز خود را آپلود کنید.
        </div>
      )}

      {/*
        پرداخت از کیف پول (R22).
        بی‌درنگ است و منتظرِ تاییدِ مدیر نمی‌ماند، چون پول از قبل در سامانه است.
      */}
      {data.walletBalance > 0 && data.bill.remaining > 0 && (
        <Card title="پرداخت از کیف پول" delay={0.07}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-[13px]" style={{ color: 'var(--text-secondary)' }}>
              موجودی کیف پول شما:{' '}
              <span className="font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>
                {formatMoney(data.walletBalance)} {data.currency}
              </span>
            </p>

            <button
              type="button"
              disabled={payingFromWallet}
              onClick={() => void payFromWallet()}
              className="flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white disabled:opacity-60"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              <Wallet size={16} />
              پرداخت {formatMoney(Math.min(data.walletBalance, data.bill.remaining))}
            </button>
          </div>
        </Card>
      )}

      {/*
        کارتِ مقصد (R22). پیش از این، ساکنِ مجتمعی که درگاه نداشت دکمه‌ی
        «آپلود رسید» را می‌دید ولی هیچ‌جا نمی‌گفت پول را کجا بفرستد.
      */}
      {data.card && (
        <Card
          title="واریز کارت‌به‌کارت"
          subtitle="مبلغ را به این کارت واریز و رسیدش را ثبت کنید"
          delay={0.08}
        >
          <dl className="flex flex-col gap-2 text-[13px]">
            {data.card.number && (
              <div className="flex items-center justify-between gap-3">
                <dt style={{ color: 'var(--text-tertiary)' }}>شماره کارت</dt>
                <dd
                  className="font-bold tabular-nums"
                  dir="ltr"
                  style={{ color: 'var(--text-primary)' }}
                >
                  {data.card.number}
                </dd>
              </div>
            )}
            {data.card.holder && (
              <div className="flex items-center justify-between gap-3">
                <dt style={{ color: 'var(--text-tertiary)' }}>به نام</dt>
                <dd style={{ color: 'var(--text-primary)' }}>{data.card.holder}</dd>
              </div>
            )}
            {data.card.bank && (
              <div className="flex items-center justify-between gap-3">
                <dt style={{ color: 'var(--text-tertiary)' }}>بانک</dt>
                <dd style={{ color: 'var(--text-primary)' }}>{data.card.bank}</dd>
              </div>
            )}
          </dl>
        </Card>
      )}

      <Card title="ثبت رسید واریز" subtitle="پس از تایید مدیر، از بدهی شما کسر می‌شود" delay={0.1}>
        {done ? (
          <div className="flex flex-col items-center gap-2 py-6 text-center">
            <CheckCircle2 size={34} style={{ color: 'var(--state-success)' }} />
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
              {done}
            </p>
            <Link
              to="/my-bills"
              className="mt-2 text-[13px] font-semibold"
              style={{ color: 'var(--color-brand-600)' }}
            >
              بازگشت به صورت‌حساب‌ها
            </Link>
          </div>
        ) : (
          <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
            {formError && (
              <div
                className="flex items-center gap-2 rounded-xl px-3.5 py-2.5 text-xs"
                style={{
                  backgroundColor: 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
                  color: 'var(--color-danger)',
                }}
              >
                <AlertCircle size={15} className="shrink-0" />
                {formError}
              </div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <TextField
                label="مبلغ واریزی"
                type="number"
                defaultValue={data.bill.remaining}
                error={errors.amount?.message}
                {...register('amount')}
              />
              <TextField
                label="تاریخ واریز"
                type="date"
                error={errors.paid_on?.message}
                {...register('paid_on')}
              />
            </div>

            <TextField
              label="توضیحات (اختیاری)"
              error={errors.description?.message}
              {...register('description')}
            />

            <div className="flex flex-col gap-1.5">
              <label
                htmlFor="pay-receipt-file"
                className="text-[13px] font-medium"
                style={{ color: 'var(--text-secondary)' }}
              >
                فایل رسید
              </label>
              <input
                id="pay-receipt-file"
                ref={fileRef}
                type="file"
                accept=".jpg,.jpeg,.png,.pdf"
                className="w-full rounded-xl border px-3 py-2.5 text-[13px] file:ml-3 file:rounded-lg file:border-0 file:px-3 file:py-1.5 file:text-white"
                style={{
                  backgroundColor: 'var(--surface-sunken)',
                  borderColor: 'var(--border-subtle)',
                  color: 'var(--text-primary)',
                }}
              />
              <p className="text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                تصویر یا PDF، حداکثر ۴ مگابایت
              </p>
            </div>

            <button
              type="submit"
              disabled={isSubmitting}
              className="mt-1 flex items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold text-white disabled:opacity-70"
              style={{ backgroundColor: 'var(--color-accent-500)' }}
            >
              {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Upload size={16} />}
              ثبت رسید
            </button>
          </form>
        )}
      </Card>
    </div>
  )
}
