import { Controller, useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Copy, Loader2, Upload } from 'lucide-react'
import { TextField } from '@/shared/ui/Field'
import { JalaliDatePicker } from '@/shared/ui/JalaliDatePicker'
import { api, ApiError } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { formatMoney } from '@/shared/lib/format'
import { MAX_RECEIPT_MB, receiptSchema, type ReceiptFormValues } from './schemas/receiptSchema'
import type { BankInfo, SubscriptionPlanOption } from './types'

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
  /*
   * ⚠️ شش `useState` جای خود را به یک فرمِ RHF داد (R40).
   *
   * نکته‌ی مهم‌تر از یکسان‌سازی: قاعده‌های اعتبارسنجی حالا در
   * `receiptSchema` هستند و بدونِ رندرکردنِ فرم قابلِ آزمون‌اند. ترتیبشان
   * هم دیگر تصادفی نیست — پیش از این «فایل انتخاب نشده» **بعد از** «نوعِ
   * فایل نامعتبر» بررسی می‌شد.
   */
  const {
    control,
    handleSubmit,
    setValue,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ReceiptFormValues>({
    resolver: zodResolver(receiptSchema),
    defaultValues: {
      plan: plans[0]?.value ?? 'pro',
      paidOn: '',
      note: '',
      receipt: undefined as unknown as File,
    },
  })

  /*
   * ⚠️ `useWatch` و نه `watch()`.
   *
   * `watch()` بیرون از مدلِ رندرِ React اشتراک می‌گیرد و کامپایلرِ React
   * نمی‌تواند کامپوننت را بهینه کند — لینتر همان‌جا
   * `react-hooks/incompatible-library` می‌دهد. `useWatch` همان کار را با
   * اشتراکِ درست انجام می‌دهد.
   */
  const planValue = useWatch({ control, name: 'plan' })
  const file = useWatch({ control, name: 'receipt' })
  const plan = plans.find((p) => p.value === planValue)

  async function submit(values: ReceiptFormValues) {
    try {
      const form = new FormData()
      form.append('plan', values.plan)
      if (values.paidOn) form.append('paid_on', values.paidOn)
      if (values.note) form.append('note', values.note)
      form.append('receipt', values.receipt)

      await api('/subscription/receipt', { method: 'POST', body: form })

      toastSuccess('رسید ثبت شد و در انتظار بررسی است.')
      onDone()
    } catch (error) {
      /*
       * خطای فیلدِ سرور زیر همان فیلد می‌نشیند.
       *
       * ⚠️ سرور همه‌ی این قاعده‌ها را **دوباره** می‌سنجد؛ اسکیما جایگزینش
       * نیست، فقط بازخوردِ فوری می‌دهد پیش از آنکه کاربر چهار مگابایت
       * آپلود کند و بعد رد شود.
       */
      if (error instanceof ApiError) {
        const fieldError = error.fieldError('receipt')

        if (fieldError) setError('receipt', { message: fieldError })
      }

      alertError(error, 'ثبت رسید ممکن نشد.')
    }
  }

  function copy(value: string, label: string) {
    void navigator.clipboard?.writeText(value).then(
      () => toastSuccess(`${label} کپی شد.`),
      () => undefined,
    )
  }

  return (
    <form onSubmit={handleSubmit(submit)} className="flex flex-col gap-4" noValidate>
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
          <BankRow
            label="شماره کارت"
            value={bank.card}
            ltr
            onCopy={() => copy(bank.card, 'شماره کارت')}
          />
          <BankRow label="شبا" value={bank.iban} ltr onCopy={() => copy(bank.iban, 'شماره شبا')} />
        </dl>

        {plan && (
          <p className="mt-3 text-[12.5px] font-bold" style={{ color: 'var(--color-brand-600)' }}>
            مبلغ قابل پرداخت: {formatMoney(plan.price)} تومان
          </p>
        )}
      </div>

      {/*
        انتخاب پلن — این «برچسبِ یک ورودی» نیست، عنوانِ یک گروه دکمه است.
        پس به‌جای <label> (که کنترلی برای اشاره‌کردن ندارد) از الگوی استانداردِ
        group + aria-labelledby استفاده می‌کنیم تا صفحه‌خوان عنوان گروه را
        پیش از دکمه‌ها بخواند.
      */}
      <div className="flex flex-col gap-1.5" role="group" aria-labelledby="receipt-plan-label">
        <span
          id="receipt-plan-label"
          className="text-[13px] font-medium"
          style={{ color: 'var(--text-secondary)' }}
        >
          پلن
        </span>
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
          {plans.map((option) => (
            <button
              key={option.value}
              type="button"
              onClick={() => setValue('plan', option.value, { shouldValidate: true })}
              className="rounded-xl border px-3.5 py-3 text-right transition-all duration-200"
              style={{
                borderColor:
                  planValue === option.value ? 'var(--color-brand-500)' : 'var(--border-subtle)',
                backgroundColor:
                  planValue === option.value
                    ? 'color-mix(in srgb, var(--color-brand-500) 8%, transparent)'
                    : 'transparent',
              }}
            >
              <span
                className="block text-[13px] font-bold"
                style={{ color: 'var(--text-primary)' }}
              >
                {option.label}
              </span>
              <span
                className="mt-0.5 block text-[11.5px]"
                style={{ color: 'var(--text-tertiary)' }}
              >
                {option.priceLabel} تومان
              </span>
            </button>
          ))}
        </div>
      </div>

      <Controller
        control={control}
        name="paidOn"
        render={({ field }) => (
          <JalaliDatePicker
            label="تاریخ واریز"
            value={field.value}
            onChange={field.onChange}
            maxToday
          />
        )}
      />

      <Controller
        control={control}
        name="note"
        render={({ field }) => (
          <TextField
            label="توضیح (اختیاری)"
            value={field.value}
            onChange={field.onChange}
            error={errors.note?.message}
            placeholder="مثلاً شماره پیگیری تراکنش"
          />
        )}
      />

      {/*
        عنوانِ بخش است، نه برچسبِ ورودی — خودِ <input> داخلِ <label>ِ پایین‌تر
        قرار دارد و از همان نام می‌گیرد.
      */}
      <div className="flex flex-col gap-1.5">
        <span className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
          تصویر رسید
        </span>

        <label
          className="flex cursor-pointer items-center gap-2.5 rounded-xl border border-dashed px-4 py-4 transition-colors hover:bg-(--surface-sunken)"
          style={{ borderColor: errors.receipt ? 'var(--color-danger)' : 'var(--border-default)' }}
        >
          <Upload size={17} style={{ color: 'var(--color-brand-500)' }} />
          <span className="text-[12.5px]" style={{ color: 'var(--text-secondary)' }}>
            {file ? file.name : `انتخاب فایل — JPG، PNG یا PDF تا ${MAX_RECEIPT_MB} مگابایت`}
          </span>
          <Controller
            control={control}
            name="receipt"
            render={({ field }) => (
              <input
                type="file"
                accept=".jpg,.jpeg,.png,.pdf"
                className="hidden"
                onChange={(event) => field.onChange(event.target.files?.[0])}
              />
            )}
          />
        </label>

        {errors.receipt && (
          // `role="alert"` تا صفحه‌خوان همان لحظه بخواند (قاعده‌ی R37)
          <p role="alert" className="text-xs" style={{ color: 'var(--color-danger)' }}>
            {errors.receipt.message}
          </p>
        )}
      </div>

      <button
        type="submit"
        disabled={isSubmitting}
        className="flex items-center justify-center gap-2 rounded-xl py-3 text-[13px] font-bold text-white disabled:opacity-60"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Upload size={16} />}
        ثبت رسید و درخواست فعال‌سازی
      </button>

      <p className="text-[11.5px] leading-6" style={{ color: 'var(--text-tertiary)' }}>
        پس از ثبت، درخواست شما در صف بررسی پشتیبانی قرار می‌گیرد و با تایید آن، اشتراک بلافاصله فعال
        می‌شود.
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
