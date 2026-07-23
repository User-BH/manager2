import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { Plus, Building, Check, LogOut, Loader2, Save, AlertCircle } from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/components/ui/Card'
import { Modal } from '@/components/ui/Modal'
import { TextField } from '@/components/ui/Field'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { useAuth } from '@/context/AuthContext'
import { api, ApiError } from '@/lib/api'
import { alertError } from '@/lib/alert'
import { formatNumber } from '@/lib/format'

const complexSchema = z.object({
  name: z.string().min(1, 'نام مجتمع را وارد کنید').max(150, 'نام طولانی است'),
  address: z.string().max(255, 'آدرس طولانی است').optional(),
  admin_name: z.string().min(1, 'نام مدیر را وارد کنید').max(120),
  admin_phone: z
    .string()
    .min(1, 'شماره مدیر را وارد کنید')
    .regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
  admin_email: z.union([z.literal(''), z.string().email('ایمیل معتبر نیست')]).optional(),
  admin_password: z.string().min(6, 'رمز عبور باید حداقل ۶ کاراکتر باشد'),
})

type ComplexFormValues = z.infer<typeof complexSchema>

interface ComplexRow {
  id: number
  name: string
  address: string | null
  units: number
  users: number
  isActive: boolean
}

export function ComplexesPage() {
  const [creating, setCreating] = useState(false)
  const [switching, setSwitching] = useState<number | null>(null)
  const { refresh } = useAuth()

  useDocumentTitle('مدیریت مجتمع‌ها')

  const { data, error, isLoading, reload } = useApi<{ data: ComplexRow[]; activeId: number | null }>(
    '/system/complexes',
  )

  async function select(complex: ComplexRow) {
    setSwitching(complex.id)
    try {
      await api(`/system/complexes/${complex.id}/select`, { method: 'POST' })
      // مجتمع فعال روی نشست ذخیره می‌شود و در پاسخ /api/me می‌آید، پس
      // اطلاعات کاربر باید تازه شود تا سایدبار و داشبورد به‌روز شوند.
      await refresh()
      reload()
    } catch (error) {
      alertError(error, 'انتخاب مجتمع ممکن نشد.')
    } finally {
      setSwitching(null)
    }
  }

  async function clear() {
    setSwitching(-1)
    try {
      await api('/system/complexes/clear', { method: 'POST' })
      await refresh()
      reload()
    } catch (error) {
      alertError(error, 'خروج از مجتمع ممکن نشد.')
    } finally {
      setSwitching(null)
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            مدیریت مجتمع‌ها
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            برای ورود به دادهٔ هر مجتمع، آن را انتخاب کنید
          </p>
        </div>

        <div className="flex items-center gap-2">
          {data?.activeId && (
            <button
              onClick={clear}
              disabled={switching !== null}
              className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-[13px] font-semibold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
            >
              {switching === -1 ? <Loader2 size={15} className="animate-spin" /> : <LogOut size={15} />}
              خروج از مجتمع
            </button>
          )}

          <button
            onClick={() => setCreating(true)}
            className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white transition-transform hover:scale-[1.03]"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            <Plus size={16} />
            مجتمع جدید
          </button>
        </div>
      </header>

      {isLoading && <LoadingState rows={3} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && !isLoading && (
        <Card>
          {data.data.length === 0 ? (
            <EmptyState
              message="هنوز مجتمعی ثبت نشده است."
              hint="با دکمهٔ «مجتمع جدید» اولین مجتمع و حساب مدیرش را بسازید."
            />
          ) : (
            <ul className="flex flex-col gap-2">
              {data.data.map((complex, index) => (
                <motion.li
                  key={complex.id}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.25, delay: Math.min(index * 0.04, 0.3) }}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3.5"
                  style={{
                    borderColor: complex.isActive ? 'var(--color-brand-300)' : 'var(--border-subtle)',
                    backgroundColor: complex.isActive
                      ? 'color-mix(in srgb, var(--color-brand-500) 7%, transparent)'
                      : 'var(--surface-sunken)',
                  }}
                >
                  <div className="flex items-center gap-3">
                    <span
                      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                      style={{
                        backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 13%, transparent)',
                        color: 'var(--color-brand-600)',
                      }}
                    >
                      <Building size={17} />
                    </span>

                    <div>
                      <p className="flex items-center gap-2 text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                        {complex.name}
                        {complex.isActive && (
                          <span
                            className="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium"
                            style={{
                              backgroundColor: 'color-mix(in srgb, var(--state-success) 16%, transparent)',
                              color: 'var(--state-success)',
                            }}
                          >
                            <Check size={10} />
                            فعال
                          </span>
                        )}
                      </p>
                      <p className="mt-0.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                        {formatNumber(complex.units)} واحد · {formatNumber(complex.users)} کاربر
                        {complex.address && ` · ${complex.address}`}
                      </p>
                    </div>
                  </div>

                  {!complex.isActive && (
                    <button
                      onClick={() => select(complex)}
                      disabled={switching !== null}
                      className="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold text-white disabled:opacity-60"
                      style={{ backgroundColor: 'var(--color-brand-500)' }}
                    >
                      {switching === complex.id && <Loader2 size={13} className="animate-spin" />}
                      انتخاب
                    </button>
                  )}
                </motion.li>
              ))}
            </ul>
          )}
        </Card>
      )}

      <Modal open={creating} title="مجتمع جدید" onClose={() => setCreating(false)}>
        <ComplexForm
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

function ComplexForm({ onSaved, onCancel }: { onSaved: () => void; onCancel: () => void }) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ComplexFormValues>({
    resolver: zodResolver(complexSchema),
    defaultValues: { name: '', address: '', admin_name: '', admin_phone: '', admin_email: '', admin_password: '' },
  })

  async function onSubmit(values: ComplexFormValues) {
    setFormError(null)

    try {
      await api('/system/complexes', {
        method: 'POST',
        body: { ...values, admin_email: values.admin_email || null },
      })
      onSaved()
    } catch (err) {
      if (err instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(err.errors)) {
          setError(field as keyof ComplexFormValues, { message: messages[0] })
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

      <TextField label="نام مجتمع" error={errors.name?.message} {...register('name')} />
      <TextField label="آدرس (اختیاری)" error={errors.address?.message} {...register('address')} />

      <div
        className="mt-1 rounded-xl px-3.5 py-2.5 text-[11px] leading-6"
        style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-tertiary)' }}
      >
        همراه مجتمع، یک حساب «مدیر مجتمع» ساخته می‌شود. ورود به سامانه با شماره موبایل انجام
        می‌شود، پس شماره الزامی است.
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <TextField label="نام مدیر" error={errors.admin_name?.message} {...register('admin_name')} />
        <TextField
          label="شماره موبایل مدیر"
          dir="ltr"
          inputMode="numeric"
          error={errors.admin_phone?.message}
          {...register('admin_phone')}
        />
        <TextField label="ایمیل مدیر (اختیاری)" type="email" dir="ltr" error={errors.admin_email?.message} {...register('admin_email')} />
        <TextField label="رمز عبور مدیر" type="password" dir="ltr" error={errors.admin_password?.message} {...register('admin_password')} />
      </div>

      <div className="mt-2 flex items-center gap-2">
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-70"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          ساخت مجتمع
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
