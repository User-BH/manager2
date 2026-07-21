import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { Plus, Trash2, UserCog, Loader2, Save, AlertCircle, ShieldCheck } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/components/ui/Card'
import { Modal } from '@/components/ui/Modal'
import { TextField } from '@/components/ui/Field'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api, ApiError } from '@/lib/api'

const managerSchema = z.object({
  name: z.string().min(1, 'نام را وارد کنید').max(120, 'نام طولانی است'),
  phone: z
    .string()
    .min(1, 'شماره موبایل را وارد کنید')
    .regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
  password: z.string().min(6, 'رمز عبور باید حداقل ۶ کاراکتر باشد'),
})

type ManagerFormValues = z.infer<typeof managerSchema>

interface Manager {
  id: number
  name: string
  phone: string
  isActive: boolean
  isSelf: boolean
  createdAt: string
}

export function ManagersPage() {
  const [creating, setCreating] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  useDocumentTitle('مدیران مجتمع')

  const { data, error, isLoading, reload } = useApi<{ data: Manager[]; complexName: string }>('/managers')

  async function handleDelete(manager: Manager) {
    if (!confirm(`${manager.name} از مدیران مجتمع حذف شود؟`)) return

    setActionError(null)
    try {
      await api(`/managers/${manager.id}`, { method: 'DELETE' })
      reload()
    } catch (err) {
      // «آخرین مدیر» و «حذف خود» با ۴۲۲ برمی‌گردند و باید دیده شوند
      setActionError(err instanceof ApiError ? err.message : 'حذف ناموفق بود.')
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            مدیران مجتمع
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {data ? `${data.complexName} — دسترسی کامل مدیریتی` : 'در حال بارگذاری…'}
          </p>
        </div>

        <button
          onClick={() => setCreating(true)}
          className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white transition-transform hover:scale-[1.03]"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <Plus size={16} />
          مدیر جدید
        </button>
      </header>

      {actionError && (
        <div
          className="flex items-center gap-2 rounded-xl px-4 py-3 text-sm"
          style={{
            backgroundColor: 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
            color: 'var(--color-danger)',
          }}
        >
          <AlertCircle size={16} />
          {actionError}
        </div>
      )}

      {isLoading && <LoadingState rows={3} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <Card>
          {data.data.length === 0 ? (
            <EmptyState message="مدیری ثبت نشده است." />
          ) : (
            <ul className="flex flex-col gap-2">
              {data.data.map((manager, index) => (
                <motion.li
                  key={manager.id}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.25, delay: Math.min(index * 0.04, 0.3) }}
                  className="flex items-center justify-between rounded-xl px-4 py-3"
                  style={{ backgroundColor: 'var(--surface-sunken)' }}
                >
                  <div className="flex items-center gap-3">
                    <span
                      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white"
                      style={{ backgroundColor: 'var(--color-brand-500)' }}
                    >
                      <UserCog size={16} />
                    </span>

                    <div>
                      <p className="flex items-center gap-2 text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                        {manager.name}
                        {manager.isSelf && (
                          <span
                            className="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium"
                            style={{
                              backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 15%, transparent)',
                              color: 'var(--color-brand-600)',
                            }}
                          >
                            <ShieldCheck size={10} />
                            شما
                          </span>
                        )}
                      </p>
                      <p className="mt-0.5 text-[11px] tabular-nums" dir="ltr" style={{ color: 'var(--text-tertiary)' }}>
                        {manager.phone}
                      </p>
                    </div>
                  </div>

                  {!manager.isSelf && (
                    <button
                      onClick={() => handleDelete(manager)}
                      aria-label={`حذف ${manager.name}`}
                      className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-base)"
                      style={{ color: 'var(--color-danger)' }}
                    >
                      <Trash2 size={15} />
                    </button>
                  )}
                </motion.li>
              ))}
            </ul>
          )}
        </Card>
      )}

      <Modal open={creating} title="مدیر جدید" onClose={() => setCreating(false)}>
        <ManagerForm
          onSaved={() => {
            setCreating(false)
            reload()
          }}
          onCancel={() => setCreating(false)}
        />
      </Modal>
    </div>
  )
}

function ManagerForm({ onSaved, onCancel }: { onSaved: () => void; onCancel: () => void }) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ManagerFormValues>({
    resolver: zodResolver(managerSchema),
    defaultValues: { name: '', phone: '', password: '' },
  })

  async function onSubmit(values: ManagerFormValues) {
    setFormError(null)

    try {
      await api('/managers', { method: 'POST', body: values })
      onSaved()
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          setError(field as keyof ManagerFormValues, { message: messages[0] })
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

      <TextField label="نام و نام خانوادگی" error={errors.name?.message} {...register('name')} />
      <TextField
        label="شماره موبایل"
        dir="ltr"
        inputMode="numeric"
        error={errors.phone?.message}
        {...register('phone')}
      />
      <TextField label="رمز عبور" type="password" dir="ltr" error={errors.password?.message} {...register('password')} />

      <div className="mt-2 flex items-center gap-2">
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-70"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          افزودن مدیر
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
