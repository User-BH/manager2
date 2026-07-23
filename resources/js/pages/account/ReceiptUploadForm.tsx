import { useState } from 'react'
import { Copy, Loader2, Upload } from 'lucide-react'
import { TextField } from '@/components/ui/Field'
import { JalaliDatePicker } from '@/components/ui/JalaliDatePicker'
import { api, ApiError } from '@/lib/api'
import { alertError, toastSuccess } from '@/lib/alert'
import { formatMoney } from '@/lib/format'
import type { BankInfo, SubscriptionPlanOption } from './types'

const MAX_SIZE_MB = 4
const ACCEPTED = ['image/jpeg', 'image/png', 'application/pdf']

/**
 * خرید اشتراک با واریز و آپلود رسید.
 *
 * تا وقتی درگاه آنلاینِ اشتراک فعال نشده، این تنها راه خرید است. فرم عمداً
 * ساده نگه داشته شده: پلن، تاریخ واریز، توضیح و فایل. مبلغ نمایشی است و
 * سمت سرور از روی پلن خوانده می‌شود، نه از این فرم.
 */
export function ReceiptUploadForm({
  plans,
  bank,
  onDone,
}: {
  plans: SubscriptionPlanOption[]
  bank: BankInfo
  onDone: () => void
}) {
  const [planValue, setPlanValue] = useState(plans[0]?.value ?? 'pro')
  const [paidOn, setPaidOn] = useState('')
  const [note, setNote] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const plan = plans.find((p) => p.value === planValue)

  function pickFile(selected: File | null) {
    setFileError(null)

    if (!selected) {
      setFile(null)
      return
    }

    // بررسی سمت کلاینت فقط برای بازخورد سریع است؛ سرور دوباره خودش
    // نوع و حجم را بررسی می‌کند.
    if (!ACCEPTED.includes(selected.type)) {
      setFileError('فقط تصویر (JPG/PNG) یا فایل PDF پذیرفته می‌شود.')
      setFile(null)
      return
    }
    if (selected.size > MAX_SIZE_MB * 1024 * 1024) {
      setFileError(`حجم فایل نباید از ${MAX_SIZE_MB} مگابایت بیشتر باشد.`)
      setFile(null)
      return
    }

    setFile(selected)
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault()

    if (!file) {
      setFileError('تصویر یا فایل رسید را انتخاب کنید.')
      return
    }

    setSubmitting(true)
    try {
      const form = new FormData()
      form.append('plan', planValue)
      if (paidOn) form.append('paid_on', paidOn)
      if (note) form.append('note', note)
      form.append('receipt', file)

      await api('/subscription/receipt', { method: 'POST', body: form })

      toastSuccess('رسید ثبت شد و در انتظار بررسی است.')
      onDone()
    } catch (error) {
      // خطای فیلد فایل زیر همان فیلد بنشیند
      if (error instanceof ApiError && error.fieldError('receipt')) {
        setFileError(error.fieldError('receipt') ?? null)
      }
      alertError(error, 'ثبت رسید ممکن نشد.')
    } finally {
      setSubmitting(false)
    }
  }

  function copy(value: string, label: string) {
    void navigator.clipboard?.writeText(value).then(
      () => toastSuccess(`${label} کپی شد.`),
      () => undefined,
    )
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-4">
      {/* اطلاعات حساب مقصد */}
      <div
        className="rounded-2xl border p-4"
        style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
      >
        <p className="text-[12.5px] font-bold" style={{ color: 'var(--text-primary)' }}>
          مبلغ را به این حساب واریز کنید
        </p>

        <dl className="mt-3 flex flex-col gap-2 text-[12.5px]">
          <BankRow label="به نام" value={bank.holder} />
          <BankRow label="بانک" value={bank.bank_name} />
          <BankRow label="شماره کارت" value={bank.card} ltr onCopy={() => copy(bank.card, 'شماره کارت')} />
          <BankRow label="شبا" value={bank.iban} ltr onCopy={() => copy(bank.iban, 'شماره شبا')} />
        </dl>

        {plan && (
          <p className="mt-3 text-[12.5px] font-bold" style={{ color: 'var(--color-brand-600)' }}>
            مبلغ قابل پرداخت: {formatMoney(plan.price)} تومان
          </p>
        )}
      </div>

      {/* انتخاب پلن */}
      <div className="flex flex-col gap-1.5">
        <label className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
          پلن
        </label>
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
          {plans.map((option) => (
            <button
              key={option.value}
              type="button"
              onClick={() => setPlanValue(option.value)}
              className="rounded-xl border px-3.5 py-3 text-right transition-all duration-200"
              style={{
                borderColor: planValue === option.value ? 'var(--color-brand-500)' : 'var(--border-subtle)',
                backgroundColor:
                  planValue === option.value
                    ? 'color-mix(in srgb, var(--color-brand-500) 8%, transparent)'
                    : 'transparent',
              }}
            >
              <span className="block text-[13px] font-bold" style={{ color: 'var(--text-primary)' }}>
                {option.label}
              </span>
              <span className="mt-0.5 block text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
                {option.priceLabel} تومان
              </span>
            </button>
          ))}
        </div>
      </div>

      <JalaliDatePicker label="تاریخ واریز" value={paidOn} onChange={setPaidOn} maxToday />

      <TextField
        label="توضیح (اختیاری)"
        value={note}
        onChange={(event) => setNote(event.target.value)}
        placeholder="مثلاً شماره پیگیری تراکنش"
      />

      {/* فایل رسید */}
      <div className="flex flex-col gap-1.5">
        <label className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
          تصویر رسید
        </label>

        <label
          className="flex cursor-pointer items-center gap-2.5 rounded-xl border border-dashed px-4 py-4 transition-colors hover:bg-(--surface-sunken)"
          style={{ borderColor: fileError ? 'var(--color-danger)' : 'var(--border-default)' }}
        >
          <Upload size={17} style={{ color: 'var(--color-brand-500)' }} />
          <span className="text-[12.5px]" style={{ color: 'var(--text-secondary)' }}>
            {file ? file.name : 'انتخاب فایل — JPG، PNG یا PDF تا ۴ مگابایت'}
          </span>
          <input
            type="file"
            accept=".jpg,.jpeg,.png,.pdf"
            className="hidden"
            onChange={(event) => pickFile(event.target.files?.[0] ?? null)}
          />
        </label>

        {fileError && (
          <p className="text-xs" style={{ color: 'var(--color-danger)' }}>
            {fileError}
          </p>
        )}
      </div>

      <button
        type="submit"
        disabled={submitting}
        className="flex items-center justify-center gap-2 rounded-xl py-3 text-[13px] font-bold text-white disabled:opacity-60"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        {submitting ? <Loader2 size={16} className="animate-spin" /> : <Upload size={16} />}
        ثبت رسید و درخواست فعال‌سازی
      </button>

      <p className="text-[11.5px] leading-6" style={{ color: 'var(--text-tertiary)' }}>
        پس از ثبت، درخواست شما در صف بررسی پشتیبانی قرار می‌گیرد و با تایید آن، اشتراک
        بلافاصله فعال می‌شود.
      </p>
    </form>
  )
}

function BankRow({
  label,
  value,
  ltr,
  onCopy,
}: {
  label: string
  value: string
  ltr?: boolean
  onCopy?: () => void
}) {
  return (
    <div className="flex items-center gap-2">
      <dt style={{ color: 'var(--text-tertiary)' }}>{label}</dt>
      <dd
        dir={ltr ? 'ltr' : undefined}
        className="mr-auto font-mono font-semibold"
        style={{ color: 'var(--text-primary)' }}
      >
        {value}
      </dd>
      {onCopy && (
        <button
          type="button"
          onClick={onCopy}
          aria-label={`کپی ${label}`}
          className="flex h-6 w-6 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-base)"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <Copy size={12} />
        </button>
      )}
    </div>
  )
}
