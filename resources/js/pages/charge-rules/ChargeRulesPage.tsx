import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { Plus, Trash2, ScrollText, ToggleLeft, ToggleRight, Loader2, Save, AlertCircle } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/components/ui/Card'
import { Modal } from '@/components/ui/Modal'
import { CheckField, SelectField, TextField } from '@/components/ui/Field'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api, ApiError } from '@/lib/api'
import { formatMoney } from '@/lib/format'

const ruleSchema = z.object({
  name: z.string().min(1, 'نام قانون را وارد کنید').max(120),
  type: z.string().min(1, 'نوع را انتخاب کنید'),
  category: z.string().min(1, 'دسته را انتخاب کنید'),
  amount: z.coerce.number().min(0).optional(),
  base: z.coerce.number().min(0).optional(),
  per_area_rate: z.coerce.number().min(0).optional(),
  per_person_rate: z.coerce.number().min(0).optional(),
  pool_amount: z.coerce.number().min(0).optional(),
  exempt_ground_floor: z.boolean().optional(),
})

type RuleInput = z.input<typeof ruleSchema>
type RuleValues = z.output<typeof ruleSchema>

interface RuleType {
  value: string
  label: string
  isPoolBased: boolean
  fields: string[]
}

interface ChargeRule {
  id: number
  name: string
  type: string
  typeLabel: string
  isPoolBased: boolean
  category: string
  categoryLabel: string
  config: Record<string, number | boolean>
  poolAmount: number | null
  isActive: boolean
}

interface RulesResponse {
  data: ChargeRule[]
  types: RuleType[]
  categories: { value: string; label: string }[]
}

/** برچسب فارسی هر پارامتر، برای نمایش خلاصه‌ی قانون در فهرست. */
const FIELD_LABEL: Record<string, string> = {
  amount: 'مبلغ',
  base: 'پایه',
  per_area_rate: 'نرخ هر متر',
  per_person_rate: 'نرخ هر نفر',
  pool_amount: 'مبلغ کل',
  exempt_ground_floor: 'معافیت همکف',
}

export function ChargeRulesPage() {
  const [creating, setCreating] = useState(false)

  useDocumentTitle('قوانین شارژ')

  const { data, error, isLoading, reload } = useApi<RulesResponse>('/charge-rules')

  async function toggle(rule: ChargeRule) {
    await api(`/charge-rules/${rule.id}/toggle`, { method: 'PATCH' })
    reload()
  }

  async function remove(rule: ChargeRule) {
    if (!confirm(`قانون «${rule.name}» حذف شود؟`)) return

    await api(`/charge-rules/${rule.id}`, { method: 'DELETE' })
    reload()
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            قوانین شارژ
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            پایهٔ محاسبهٔ شارژ ماهانه؛ هنگام صدور قبض اعمال می‌شوند
          </p>
        </div>

        <button
          onClick={() => setCreating(true)}
          className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white transition-transform hover:scale-[1.03]"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <Plus size={16} />
          قانون جدید
        </button>
      </header>

      {isLoading && <LoadingState rows={4} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <Card>
          {data.data.length === 0 ? (
            <EmptyState
              message="هنوز قانون شارژی تعریف نشده است."
              hint="بدون قانون، صدور قبض مبلغی تولید نمی‌کند."
            />
          ) : (
            <ul className="flex flex-col gap-2">
              {data.data.map((rule, index) => (
                <motion.li
                  key={rule.id}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.25, delay: Math.min(index * 0.04, 0.3) }}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3.5"
                  style={{ backgroundColor: 'var(--surface-sunken)', opacity: rule.isActive ? 1 : 0.55 }}
                >
                  <div className="flex items-start gap-3">
                    <span
                      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                      style={{
                        backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 13%, transparent)',
                        color: 'var(--color-brand-600)',
                      }}
                    >
                      <ScrollText size={16} />
                    </span>

                    <div>
                      <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                        {rule.name}
                      </p>
                      <p className="mt-0.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                        {rule.typeLabel} · {rule.categoryLabel}
                        {rule.poolAmount !== null && ` · مبلغ کل ${formatMoney(rule.poolAmount)}`}
                        {Object.entries(rule.config)
                          .filter(([, v]) => typeof v === 'number')
                          .map(([k, v]) => ` · ${FIELD_LABEL[k] ?? k} ${formatMoney(v as number)}`)
                          .join('')}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-1">
                    <button
                      onClick={() => toggle(rule)}
                      aria-label={rule.isActive ? 'غیرفعال کردن' : 'فعال کردن'}
                      title={rule.isActive ? 'غیرفعال کردن' : 'فعال کردن'}
                      className="flex h-8 w-8 items-center justify-center rounded-lg"
                      style={{ color: rule.isActive ? 'var(--state-success)' : 'var(--text-tertiary)' }}
                    >
                      {rule.isActive ? <ToggleRight size={18} /> : <ToggleLeft size={18} />}
                    </button>
                    <button
                      onClick={() => remove(rule)}
                      aria-label={`حذف ${rule.name}`}
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

      <Modal open={creating} title="قانون شارژ جدید" onClose={() => setCreating(false)}>
        {data && (
          <RuleForm
            types={data.types}
            categories={data.categories}
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

function RuleForm({
  types,
  categories,
  onSaved,
  onCancel,
}: {
  types: RuleType[]
  categories: { value: string; label: string }[]
  onSaved: () => void
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    watch,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RuleInput, unknown, RuleValues>({
    resolver: zodResolver(ruleSchema),
    defaultValues: {
      name: '',
      type: types[0]?.value ?? '',
      category: categories[0]?.value ?? 'tenant',
      exempt_ground_floor: true,
    },
  })

  // هر نوع قانون پارامترهای خودش را دارد؛ فقط همان‌ها نمایش داده می‌شوند تا
  // کاربر فیلدی پر نکند که در محاسبه اصلاً استفاده نمی‌شود.
  const selectedType = types.find((t) => t.value === watch('type'))
  const fields = selectedType?.fields ?? []

  async function onSubmit(values: RuleValues) {
    setFormError(null)

    try {
      await api('/charge-rules', { method: 'POST', body: values })
      onSaved()
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          setError(field as keyof RuleInput, { message: messages[0] })
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

      <TextField label="نام قانون" error={errors.name?.message} {...register('name')} />

      <SelectField label="نوع محاسبه" options={types} error={errors.type?.message} {...register('type')} />
      <SelectField label="دسته" options={categories} error={errors.category?.message} {...register('category')} />

      {fields.includes('amount') && (
        <TextField label="مبلغ" type="number" step="0.01" error={errors.amount?.message} {...register('amount')} />
      )}
      {fields.includes('base') && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <TextField label="مبلغ پایه" type="number" step="0.01" error={errors.base?.message} {...register('base')} />
          <TextField
            label="نرخ هر متر مربع"
            type="number"
            step="0.01"
            error={errors.per_area_rate?.message}
            {...register('per_area_rate')}
          />
          <TextField
            label="نرخ هر نفر"
            type="number"
            step="0.01"
            error={errors.per_person_rate?.message}
            {...register('per_person_rate')}
          />
        </div>
      )}
      {fields.includes('pool_amount') && (
        <TextField
          label="مبلغ کل (بین واحدها تقسیم می‌شود)"
          type="number"
          step="0.01"
          error={errors.pool_amount?.message}
          {...register('pool_amount')}
        />
      )}
      {fields.includes('exempt_ground_floor') && (
        <CheckField label="طبقهٔ همکف از این هزینه معاف باشد" {...register('exempt_ground_floor')} />
      )}

      <div className="mt-2 flex items-center gap-2">
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-70"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          افزودن قانون
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
