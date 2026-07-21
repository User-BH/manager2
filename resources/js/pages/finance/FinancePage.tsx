import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { Plus, Trash2, TrendingDown, TrendingUp, Wallet, Loader2, Save, AlertCircle, Split } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Modal } from '@/components/ui/Modal'
import { SelectField, TextField } from '@/components/ui/Field'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api, ApiError } from '@/lib/api'
import { formatMoney } from '@/lib/format'

const expenseSchema = z.object({
  title: z.string().min(1, 'عنوان را وارد کنید').max(150),
  amount: z.coerce.number({ message: 'مبلغ را وارد کنید' }).min(0, 'مبلغ نمی‌تواند منفی باشد'),
  category: z.string().min(1, 'دسته را انتخاب کنید'),
  split_method: z.string().optional(),
  description: z.string().max(255).optional(),
})

const incomeSchema = z.object({
  title: z.string().min(1, 'عنوان را وارد کنید').max(150),
  amount: z.coerce.number({ message: 'مبلغ را وارد کنید' }).min(0, 'مبلغ نمی‌تواند منفی باشد'),
  source: z.string().max(120).optional(),
})

type ExpenseInput = z.input<typeof expenseSchema>
type ExpenseValues = z.output<typeof expenseSchema>
type IncomeInput = z.input<typeof incomeSchema>
type IncomeValues = z.output<typeof incomeSchema>

interface Option {
  value: string
  label: string
}

interface FinanceResponse {
  period: string
  periodLabel: string
  periods: Option[]
  currency: string
  expenseTotal: number
  incomeTotal: number
  expenses: {
    id: number
    title: string
    amount: number
    categoryLabel: string
    splitLabel: string | null
    isDistributed: boolean
    description: string | null
    spendDate: string | null
  }[]
  incomes: { id: number; title: string; amount: number; source: string | null; receivedDate: string | null }[]
  splitMethods: Option[]
  categories: Option[]
}

export function FinancePage() {
  const [period, setPeriod] = useState('')
  const [adding, setAdding] = useState<'expense' | 'income' | null>(null)

  useDocumentTitle('هزینه‌ها و درآمدها')

  const query = period ? `/finance?period=${encodeURIComponent(period)}` : '/finance'
  const { data, error, isLoading, reload } = useApi<FinanceResponse>(query)

  async function removeExpense(id: number) {
    if (!confirm('این هزینه حذف شود؟')) return
    await api(`/finance/expenses/${id}`, { method: 'DELETE' })
    reload()
  }

  async function removeIncome(id: number) {
    if (!confirm('این درآمد حذف شود؟')) return
    await api(`/finance/incomes/${id}`, { method: 'DELETE' })
    reload()
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            هزینه‌ها و درآمدها
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {data ? data.periodLabel : 'در حال بارگذاری…'}
          </p>
        </div>

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
      </header>

      {isLoading && <LoadingState rows={4} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard label="کل هزینه" value={formatMoney(data.expenseTotal)} unit={data.currency} icon={TrendingDown} tone="warning" />
            <StatCard label="کل درآمد" value={formatMoney(data.incomeTotal)} unit={data.currency} icon={TrendingUp} tone="success" delay={0.05} />
            <StatCard
              label="تراز دوره"
              value={formatMoney(data.incomeTotal - data.expenseTotal)}
              unit={data.currency}
              icon={Wallet}
              tone={data.incomeTotal - data.expenseTotal >= 0 ? 'brand' : 'danger'}
              delay={0.1}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <Card
              title="هزینه‌ها"
              delay={0.15}
              actions={
                <button
                  onClick={() => setAdding('expense')}
                  className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold text-white"
                  style={{ backgroundColor: 'var(--color-accent-500)' }}
                >
                  <Plus size={14} />
                  هزینه جدید
                </button>
              }
            >
              {data.expenses.length === 0 ? (
                <EmptyState message="هزینه‌ای برای این دوره ثبت نشده است." />
              ) : (
                <ul className="flex flex-col gap-2">
                  {data.expenses.map((expense, index) => (
                    <motion.li
                      key={expense.id}
                      initial={{ opacity: 0, y: 6 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ duration: 0.25, delay: Math.min(index * 0.03, 0.25) }}
                      className="flex items-start justify-between gap-3 rounded-xl px-3.5 py-3"
                      style={{ backgroundColor: 'var(--surface-sunken)' }}
                    >
                      <div className="min-w-0">
                        <p className="truncate text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>
                          {expense.title}
                        </p>
                        <p className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                          <span>{expense.categoryLabel}</span>
                          {expense.spendDate && <span>· {expense.spendDate}</span>}
                          {expense.isDistributed && expense.splitLabel && (
                            <span
                              className="flex items-center gap-1 rounded-full px-2 py-0.5"
                              style={{
                                backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 13%, transparent)',
                                color: 'var(--color-brand-600)',
                              }}
                            >
                              <Split size={9} />
                              {expense.splitLabel}
                            </span>
                          )}
                        </p>
                      </div>

                      <div className="flex shrink-0 items-center gap-2">
                        <span className="tabular-nums text-[13px] font-bold" style={{ color: 'var(--color-accent-600)' }}>
                          {formatMoney(expense.amount)}
                        </span>
                        <button
                          onClick={() => removeExpense(expense.id)}
                          aria-label={`حذف ${expense.title}`}
                          className="flex h-7 w-7 items-center justify-center rounded-lg"
                          style={{ color: 'var(--color-danger)' }}
                        >
                          <Trash2 size={14} />
                        </button>
                      </div>
                    </motion.li>
                  ))}
                </ul>
              )}
            </Card>

            <Card
              title="درآمدها"
              delay={0.2}
              actions={
                <button
                  onClick={() => setAdding('income')}
                  className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold text-white"
                  style={{ backgroundColor: 'var(--color-brand-500)' }}
                >
                  <Plus size={14} />
                  درآمد جدید
                </button>
              }
            >
              {data.incomes.length === 0 ? (
                <EmptyState message="درآمدی برای این دوره ثبت نشده است." />
              ) : (
                <ul className="flex flex-col gap-2">
                  {data.incomes.map((income, index) => (
                    <motion.li
                      key={income.id}
                      initial={{ opacity: 0, y: 6 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ duration: 0.25, delay: Math.min(index * 0.03, 0.25) }}
                      className="flex items-start justify-between gap-3 rounded-xl px-3.5 py-3"
                      style={{ backgroundColor: 'var(--surface-sunken)' }}
                    >
                      <div className="min-w-0">
                        <p className="truncate text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>
                          {income.title}
                        </p>
                        <p className="mt-0.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                          {income.source ?? 'بدون منبع'}
                          {income.receivedDate && ` · ${income.receivedDate}`}
                        </p>
                      </div>

                      <div className="flex shrink-0 items-center gap-2">
                        <span className="tabular-nums text-[13px] font-bold" style={{ color: 'var(--state-success)' }}>
                          {formatMoney(income.amount)}
                        </span>
                        <button
                          onClick={() => removeIncome(income.id)}
                          aria-label={`حذف ${income.title}`}
                          className="flex h-7 w-7 items-center justify-center rounded-lg"
                          style={{ color: 'var(--color-danger)' }}
                        >
                          <Trash2 size={14} />
                        </button>
                      </div>
                    </motion.li>
                  ))}
                </ul>
              )}
            </Card>
          </div>
        </>
      )}

      <Modal
        open={adding !== null}
        title={adding === 'expense' ? 'هزینه جدید' : 'درآمد جدید'}
        onClose={() => setAdding(null)}
      >
        {data && adding === 'expense' && (
          <ExpenseForm
            period={data.period}
            categories={data.categories}
            splitMethods={data.splitMethods}
            onSaved={() => {
              setAdding(null)
              reload()
            }}
            onCancel={() => setAdding(null)}
          />
        )}
        {data && adding === 'income' && (
          <IncomeForm
            period={data.period}
            onSaved={() => {
              setAdding(null)
              reload()
            }}
            onCancel={() => setAdding(null)}
          />
        )}
      </Modal>
    </div>
  )
}

function FormError({ message }: { message: string }) {
  return (
    <div
      className="flex items-center gap-2 rounded-xl px-3.5 py-2.5 text-xs"
      style={{
        backgroundColor: 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
        color: 'var(--color-danger)',
      }}
    >
      <AlertCircle size={15} className="shrink-0" />
      {message}
    </div>
  )
}

function Actions({ submitting, label, onCancel }: { submitting: boolean; label: string; onCancel: () => void }) {
  return (
    <div className="mt-2 flex items-center gap-2">
      <button
        type="submit"
        disabled={submitting}
        className="flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-70"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        {submitting ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
        {label}
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
  )
}

function ExpenseForm({
  period,
  categories,
  splitMethods,
  onSaved,
  onCancel,
}: {
  period: string
  categories: Option[]
  splitMethods: Option[]
  onSaved: () => void
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ExpenseInput, unknown, ExpenseValues>({
    resolver: zodResolver(expenseSchema),
    defaultValues: { title: '', category: categories[0]?.value ?? 'tenant', split_method: '' },
  })

  async function onSubmit(values: ExpenseValues) {
    setFormError(null)

    try {
      await api('/finance/expenses', {
        method: 'POST',
        body: { ...values, period, split_method: values.split_method || null },
      })
      onSaved()
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          setError(field as keyof ExpenseInput, { message: messages[0] })
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
      {formError && <FormError message={formError} />}

      <TextField label="عنوان هزینه" error={errors.title?.message} {...register('title')} />
      <TextField label="مبلغ" type="number" step="0.01" error={errors.amount?.message} {...register('amount')} />
      <SelectField label="دسته" options={categories} error={errors.category?.message} {...register('category')} />

      <SelectField
        label="روش تقسیم بین واحدها"
        placeholder="تقسیم نشود (فقط ثبت هزینه)"
        options={splitMethods}
        error={errors.split_method?.message}
        {...register('split_method')}
      />
      <p className="-mt-2 text-[11px] leading-5" style={{ color: 'var(--text-tertiary)' }}>
        اگر روش تقسیم انتخاب شود، این هزینه هنگام صدور قبضِ همین دوره بین واحدها سرشکن می‌شود.
      </p>

      <TextField label="توضیحات (اختیاری)" error={errors.description?.message} {...register('description')} />

      <Actions submitting={isSubmitting} label="ثبت هزینه" onCancel={onCancel} />
    </form>
  )
}

function IncomeForm({
  period,
  onSaved,
  onCancel,
}: {
  period: string
  onSaved: () => void
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<IncomeInput, unknown, IncomeValues>({
    resolver: zodResolver(incomeSchema),
    defaultValues: { title: '', source: '' },
  })

  async function onSubmit(values: IncomeValues) {
    setFormError(null)

    try {
      await api('/finance/incomes', { method: 'POST', body: { ...values, period } })
      onSaved()
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          setError(field as keyof IncomeInput, { message: messages[0] })
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
      {formError && <FormError message={formError} />}

      <TextField label="عنوان درآمد" error={errors.title?.message} {...register('title')} />
      <TextField label="مبلغ" type="number" step="0.01" error={errors.amount?.message} {...register('amount')} />
      <TextField label="منبع (اختیاری)" error={errors.source?.message} {...register('source')} />

      <Actions submitting={isSubmitting} label="ثبت درآمد" onCancel={onCancel} />
    </form>
  )
}
