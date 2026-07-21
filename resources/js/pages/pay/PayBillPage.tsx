import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowRight, CreditCard, Upload, Loader2, AlertCircle, CheckCircle2 } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/components/ui/Card'
import { TextField } from '@/components/ui/Field'
import { ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api, ApiError } from '@/lib/api'
import { formatMoney } from '@/lib/format'

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
}

export function PayBillPage() {
  const { billId } = useParams()
  const [done, setDone] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  useDocumentTitle('پرداخت قبض')

  const { data, error, isLoading, reload } = useApi<PayResponse>(`/pay/${billId}`)

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

  if (isLoading) return <LoadingState rows={3} />
  if (error) return <ErrorState message={error} onRetry={reload} />
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
          <span className="tabular-nums text-2xl font-extrabold" style={{ color: 'var(--state-success)' }}>
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

      <Card title="ثبت رسید واریز" subtitle="پس از تایید مدیر، از بدهی شما کسر می‌شود" delay={0.1}>
        {done ? (
          <div className="flex flex-col items-center gap-2 py-6 text-center">
            <CheckCircle2 size={34} style={{ color: 'var(--state-success)' }} />
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
              {done}
            </p>
            <Link to="/my-bills" className="mt-2 text-[13px] font-semibold" style={{ color: 'var(--color-brand-600)' }}>
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
              <TextField label="تاریخ واریز" type="date" error={errors.paid_on?.message} {...register('paid_on')} />
            </div>

            <TextField label="توضیحات (اختیاری)" error={errors.description?.message} {...register('description')} />

            <div className="flex flex-col gap-1.5">
              <label className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
                فایل رسید
              </label>
              <input
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
