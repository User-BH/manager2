import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { Plus, Trash2, BadgePercent, Loader2, Save, AlertCircle, Info } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/components/ui/Card'
import { Modal } from '@/components/ui/Modal'
import { SelectField, TextField } from '@/components/ui/Field'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api, ApiError } from '@/lib/api'
import { formatMoney } from '@/lib/format'

const discountSchema = z.object({
  unit_id: z.string().min(1, 'واحد را انتخاب کنید'),
  amount: z.coerce.number({ message: 'مبلغ تخفیف را وارد کنید' }).min(0, 'مبلغ نمی‌تواند منفی باشد'),
  reason: z.string().max(150).optional(),
})

type DiscountInput = z.input<typeof discountSchema>
type DiscountValues = z.output<typeof discountSchema>

interface Discount {
  id: number
  unitId: number
  unitLabel: string
  amount: number
  reason: string | null
}

interface DiscountsResponse {
  period: string
  periodLabel: string
  periods: { value: string; label: string }[]
  currency: string
  total: number
  data: Discount[]
  units: { value: number; label: string }[]
}

export function DiscountsPage() {
  const [period, setPeriod] = useState('')
  const [creating, setCreating] = useState(false)

  useDocumentTitle('تخفیف و بخشودگی')

  const query = period ? `/discounts?period=${encodeURIComponent(period)}` : '/discounts'
  const { data, error, isLoading, reload } = useApi<DiscountsResponse>(query)

  async function remove(discount: Discount) {
    if (!confirm(`تخفیف ${discount.unitLabel} حذف شود؟`)) return

    await api(`/discounts/${discount.id}`, { method: 'DELETE' })
    reload()
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            تخفیف و بخشودگی
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {data ? data.periodLabel : 'در حال بارگذاری…'}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {data && (
            <select
              value={data.period}
              onChange={(e) => setPeriod(e.target.value)}
              className="rounded-xl border px-3 py-2.5 text-[13px] outline-none"
              style={{
                backgroundColor: 'var(--surface-sunken)',
                borderColor: 'var(--border-subtle)',
                color: 'var(--text-primary)',
              }}
            >
              {data.periods.map((p) => (
                <option key={p.value} value={p.value}>
                  {p.label}
                </option>
              ))}
            </select>
          )}

          <button
            onClick={() => setCreating(true)}
            className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white transition-transform hover:scale-[1.03]"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            <Plus size={16} />
            تخفیف جدید
          </button>
        </div>
      </header>

      <Card>
        <div className="flex items-start gap-2.5">
          <span style={{ color: 'var(--state-info)' }}>
            <Info size={17} />
          </span>
          <p className="text-[13px] leading-7" style={{ color: 'var(--text-secondary)' }}>
            تخفیف هنگام <strong>صدور قبض</strong> از مبلغ کل واحد کسر می‌شود. اگر قبوض این دوره از قبل
            صادر شده‌اند، پس از ثبت تخفیف باید از صفحهٔ «قبوض و شارژ» دوباره صدور بزنید تا اعمال شود.
          </p>
        </div>
      </Card>

      {isLoading && <LoadingState rows={3} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <Card
          title="تخفیف‌های این دوره"
          subtitle={`مجموع: ${formatMoney(data.total)} ${data.currency}`}
          delay={0.05}
        >
          {data.data.length === 0 ? (
            <EmptyState message="برای این دوره تخفیفی ثبت نشده است." />
          ) : (
            <ul className="flex flex-col gap-2">
              {data.data.map((discount, index) => (
                <motion.li
                  key={discount.id}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.25, delay: Math.min(index * 0.04, 0.3) }}
                  className="flex items-center justify-between gap-3 rounded-xl px-4 py-3"
                  style={{ backgroundColor: 'var(--surface-sunken)' }}
                >
                  <div className="flex items-center gap-3">
                    <span
                      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                      style={{
                        backgroundColor: 'color-mix(in srgb, var(--color-accent-500) 15%, transparent)',
                        color: 'var(--color-accent-600)',
                      }}
                    >
                      <BadgePercent size={16} />
                    </span>

                    <div>
                      <p className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>
                        {discount.unitLabel}
                      </p>
                      <p className="mt-0.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                        {discount.reason ?? 'بدون توضیح'}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <span className="tabular-nums text-sm font-bold" style={{ color: 'var(--color-accent-600)' }}>
                      {formatMoney(discount.amount)}
                    </span>
                    <button
                      onClick={() => remove(discount)}
                      aria-label={`حذف تخفیف ${discount.unitLabel}`}
                      className="flex h-8 w-8 items-center justify-center rounded-lg"
                      style={{ color: 'var(--color-danger)' }}
                    >
                      <Trash2 size={15} />
                    </button>
                  </div>
                </motion.li>
              ))}
            </ul>
          )}
        </Card>
      )}

      <Modal open={creating} title="تخفیف جدید" onClose={() => setCreating(false)}>
        {data && (
          <DiscountForm
            period={data.period}
            units={data.units}
            onSaved={() => {
              setCreating(false)
              reload()
            }}
            onCancel={() => setCreating(false)}
          />
        )}
      </Modal>
    </div>
  )
}

function DiscountForm({
  period,
  units,
  onSaved,
  onCancel,
}: {
  period: string
  units: { value: number; label: string }[]
  onSaved: () => void
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<DiscountInput, unknown, DiscountValues>({
    resolver: zodResolver(discountSchema),
    defaultValues: { unit_id: '', reason: '' },
  })

  async function onSubmit(values: DiscountValues) {
    setFormError(null)

    try {
      await api('/discounts', { method: 'POST', body: { ...values, period } })
      onSaved()
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          setError(field as keyof DiscountInput, { message: messages[0] })
          handled = true
        }
        if (!handled) setFormError(err.message)
      } else {
        setFormError('ارتباط با سرور برقرار نشد.')
      }
    }
  }

  return (
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

      <SelectField
        label="واحد"
        placeholder="انتخاب کنید"
        options={units}
        error={errors.unit_id?.message}
        {...register('unit_id')}
      />
      <TextField label="مبلغ تخفیف" type="number" step="0.01" error={errors.amount?.message} {...register('amount')} />
      <TextField label="دلیل (اختیاری)" error={errors.reason?.message} {...register('reason')} />

      <p className="-mt-1 text-[11px] leading-5" style={{ color: 'var(--text-tertiary)' }}>
        هر واحد در هر دوره فقط یک تخفیف دارد؛ ثبت دوباره برای همان واحد، مقدار قبلی را جایگزین می‌کند.
      </p>

      <div className="mt-2 flex items-center gap-2">
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-70"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          ثبت تخفیف
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="rounded-xl border px-5 py-3 text-sm font-semibold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
        >
          انصراف
        </button>
      </div>
    </form>
  )
}
