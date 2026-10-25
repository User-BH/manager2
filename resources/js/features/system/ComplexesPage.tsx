import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import {
  Plus,
  Building,
  Check,
  LogOut,
  Loader2,
  Save,
  AlertCircle,
  PauseCircle,
} from 'lucide-react'
import { z } from 'zod'
import { Card } from '@/shared/ui/Card'
import { Modal } from '@/shared/ui/Modal'
import { SelectField, TextField } from '@/shared/ui/Field'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { Pager } from '@/shared/ui/Pager'
import { TableSkeleton } from '@/shared/ui/Skeleton'
import { useQuery } from '@tanstack/react-query'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { useAuth } from '@/shared/stores/authStore'
import { api, ApiError } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { InviteLinkPanel } from '@/shared/ui/InviteLinkPanel'
import { formatNumber } from '@/shared/lib/format'

const complexSchema = z.object({
  name: z.string().min(1, 'نام مجتمع را وارد کنید').max(150, 'نام طولانی است'),
  /*
   * نوعِ مجتمع (R67).
   *
   * ⚠️ اینجا اجباری است هرچند API اختیاری‌اش گرفته: قاعده‌ی سازگاری
   * می‌گوید فیلدِ اجباریِ تازه در API تغییرِ شکننده است، ولی فرمِ ما باید
   * صریح بپرسد — چون تعرفه‌ی اشتراک به همین وابسته است و پیش‌فرضِ خاموش
   * یعنی مجتمعِ تجاری بی‌سروصدا تعرفه‌ی مسکونی بگیرد.
   */
  type: z.enum(['residential', 'commercial']),
  address: z.string().max(255, 'آدرس طولانی است').optional(),
  admin_phone: z
    .string()
    .min(1, 'شماره مدیر را وارد کنید')
    .regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد'),
})

type ComplexFormValues = z.infer<typeof complexSchema>

interface ComplexRow {
  id: number
  name: string
  type: 'residential' | 'commercial'
  typeLabel: string
  address: string | null
  units: number
  users: number
  /** «مجتمعِ انتخاب‌شده‌ی من» — ربطی به تعلیق ندارد. */
  isActive: boolean
  /** تعلیقِ دسترسیِ اعضای مجتمع (R29). */
  isSuspended: boolean
  suspendedAt: string | null
  suspensionReason: string | null
}

export function ComplexesPage() {
  const [creating, setCreating] = useState(false)
  // پیوندِ دعوتِ مدیرِ مجتمعِ تازه — فقط همین یک بار (مرحلهٔ ۹ · فاز ۶)
  const [invited, setInvited] = useState<InviteResult | null>(null)
  const [switching, setSwitching] = useState<number | null>(null)
  /** مجتمعی که در حال تعلیق‌کردنش هستیم؛ مودالِ دلیل روی آن باز می‌شود. */
  const [suspending, setSuspending] = useState<ComplexRow | null>(null)
  const [reason, setReason] = useState('')
  const [reasonError, setReasonError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const { refresh, user } = useAuth()
  // روی Production سرور «شاملِ دادهٔ آزمایشی» را نادیده می‌گیرد؛ پس کلیدش هم نیست
  const demoData = user?.demoData === true

  useDocumentTitle('مدیریت مجتمع‌ها')

  const [page, setPage] = useState(1)

  /*
   * فهرستِ مجتمع‌ها از R13 صفحه‌بندی می‌شود: تنها فهرستی بود که با رشدِ
   * کسب‌وکار بی‌کران می‌شد. `page` جزوِ کلید است، پس هر صفحه کشِ خودش را دارد
   * و برگشتن به صفحه‌ی قبلی آنی است.
   */
  // دادهٔ آزمایشی پیش‌فرض بیرون است و سرور کنارش می‌گذارد (۱۳/۱)؛ انتخاب در نشانی می‌ماند
  const [search, setSearch] = useSearchParams()
  const includeDemo = search.get('include_demo') === '1'

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.system.complexes({ page, includeDemo }),
    queryFn: ({ signal }) =>
      api<{
        data: ComplexRow[]
        activeId: number | null
        meta: { currentPage: number; lastPage: number; total: number }
      }>(`/system/complexes?page=${page}${includeDemo ? '&include_demo=1' : ''}`, { signal }),
  })

  /**
   * تعلیق و بازفعال‌سازی (R29).
   *
   * تعلیق دلیلِ اجباری می‌خواهد چون همان متن مستقیم به اعضای مجتمع نشان
   * داده می‌شود؛ بازفعال‌سازی دلیلی لازم ندارد.
   */
  async function suspend() {
    if (!suspending) return

    /*
     * ⚠️ قاعده اینجا بررسی می‌شود، نه با خاموش‌کردنِ دکمه (R40).
     *
     * پیش از این `disabled={reason.trim().length < 5}` بود: ادمین دلیل را
     * کوتاه می‌نوشت، دکمه خاکستری می‌ماند، و **هیچ پیامی** نمی‌دید — چون
     * پیام فقط پس از ارسال می‌آید و ارسالی در کار نبود. صفحه‌خوان هم فقط
     * «دکمه، غیرفعال» می‌گفت.
     */
    if (reason.trim().length < 5) {
      setReasonError(
        'دلیل تعلیق را دست‌کم با ۵ نویسه بنویسید؛ همین متن به اعضای مجتمع نشان داده می‌شود.',
      )

      return
    }

    setReasonError(null)
    setBusy(true)

    try {
      await api(`/system/complexes/${suspending.id}/suspend`, {
        method: 'POST',
        body: { reason: reason.trim() },
      })

      toastSuccess('دسترسی این مجتمع تعلیق شد.')
      setSuspending(null)
      setReason('')
      await refetch()
    } catch (err) {
      alertError(err, 'تعلیق مجتمع ممکن نشد.')
    } finally {
      setBusy(false)
    }
  }

  async function activate(complex: ComplexRow) {
    setBusy(true)

    try {
      await api(`/system/complexes/${complex.id}/activate`, { method: 'POST' })
      toastSuccess('دسترسی این مجتمع بازگردانده شد.')
      await refetch()
    } catch (err) {
      alertError(err, 'بازگرداندن دسترسی ممکن نشد.')
    } finally {
      setBusy(false)
    }
  }

  async function select(complex: ComplexRow) {
    setSwitching(complex.id)
    try {
      await api(`/system/complexes/${complex.id}/select`, { method: 'POST' })
      // مجتمع فعال روی نشست ذخیره می‌شود و در پاسخ /api/me می‌آید، پس
      // اطلاعات کاربر باید تازه شود تا سایدبار و داشبورد به‌روز شوند.
      await refresh()
      void refetch()
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
      void refetch()
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
          {demoData && (
            <label
              className="flex items-center gap-1.5 text-[12px]"
              style={{ color: 'var(--text-secondary)' }}
            >
              <input
                type="checkbox"
                className="h-4 w-4 rounded"
                checked={includeDemo}
                onChange={(e) => {
                  setSearch(e.target.checked ? { include_demo: '1' } : {})
                  setPage(1)
                }}
              />
              شامل داده‌ی آزمایشی
            </label>
          )}
          {data?.activeId && (
            <button
              onClick={clear}
              disabled={switching !== null}
              className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-[13px] font-semibold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
            >
              {switching === -1 ? (
                <Loader2 size={15} className="animate-spin" />
              ) : (
                <LogOut size={15} />
              )}
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

      {isLoading && <TableSkeleton rows={5} columns={4} />}
      {error && <ErrorState error={error} onRetry={() => void refetch()} />}

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
                    borderColor: complex.isActive
                      ? 'var(--color-brand-300)'
                      : 'var(--border-subtle)',
                    backgroundColor: complex.isActive
                      ? 'color-mix(in srgb, var(--color-brand-500) 7%, transparent)'
                      : 'var(--surface-sunken)',
                  }}
                >
                  <div className="flex items-center gap-3">
                    <span
                      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                      style={{
                        backgroundColor:
                          'color-mix(in srgb, var(--color-brand-500) 13%, transparent)',
                        color: 'var(--color-brand-600)',
                      }}
                    >
                      <Building size={17} />
                    </span>

                    <div>
                      <p
                        className="flex items-center gap-2 text-sm font-bold"
                        style={{ color: 'var(--text-primary)' }}
                      >
                        {complex.name}
                        {complex.isActive && (
                          <span
                            className="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium"
                            style={{
                              backgroundColor:
                                'color-mix(in srgb, var(--state-success) 16%, transparent)',
                              color: 'var(--state-success)',
                            }}
                          >
                            <Check size={10} />
                            فعال
                          </span>
                        )}

                        {/* تعلیق باید در یک نگاه دیده شود، نه پشتِ کلیک */}
                        {complex.isSuspended && (
                          <span
                            className="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium"
                            style={{
                              backgroundColor: 'rgba(244,63,94,0.14)',
                              color: 'var(--state-danger)',
                            }}
                          >
                            <PauseCircle size={10} />
                            تعلیق‌شده
                          </span>
                        )}
                      </p>

                      {complex.isSuspended && complex.suspensionReason && (
                        <p className="mt-0.5 text-[11px]" style={{ color: 'var(--state-danger)' }}>
                          {complex.suspensionReason}
                          {complex.suspendedAt && ` — ${complex.suspendedAt}`}
                        </p>
                      )}
                      <p className="mt-0.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                        {complex.typeLabel} · {formatNumber(complex.units)} واحد ·{' '}
                        {formatNumber(complex.users)} کاربر
                        {complex.address && ` · ${complex.address}`}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    {complex.isSuspended ? (
                      <button
                        onClick={() => void activate(complex)}
                        disabled={busy}
                        className="rounded-lg border px-3 py-2 text-xs font-bold disabled:opacity-60"
                        style={{
                          borderColor: 'var(--border-subtle)',
                          color: 'var(--state-success)',
                        }}
                      >
                        بازگرداندن دسترسی
                      </button>
                    ) : (
                      <button
                        onClick={() => {
                          setSuspending(complex)
                          setReason('')
                        }}
                        disabled={busy}
                        className="rounded-lg border px-3 py-2 text-xs disabled:opacity-60"
                        style={{
                          borderColor: 'var(--border-subtle)',
                          color: 'var(--color-danger)',
                        }}
                      >
                        تعلیق
                      </button>
                    )}

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
                  </div>
                </motion.li>
              ))}
            </ul>
          )}

          {/* صفحه‌بندِ مشترک (مرحلهٔ ۵ · فاز ۶) — پیش‌تر همین‌جا درون‌خطی بود */}
          <Pager meta={data.meta} onChange={setPage} noun="مجتمع" />
        </Card>
      )}

      <Modal
        open={creating || invited !== null}
        title={invited ? 'پیوندِ دعوتِ مدیر' : 'مجتمع جدید'}
        onClose={() => {
          setCreating(false)
          setInvited(null)
        }}
      >
        {invited ? (
          <InviteLinkPanel {...invited} onDone={() => setInvited(null)} />
        ) : (
          <ComplexForm
            onSaved={(result) => {
              setCreating(false)
              setInvited(result)
              void refetch()
            }}
            onCancel={() => setCreating(false)}
          />
        )}
      </Modal>
      {/*
        مودالِ دلیلِ تعلیق (R29).

        دلیل اجباری است چون همان متن مستقیم روی صفحه‌ی تعلیق به اعضای
        مجتمع نشان داده می‌شود؛ متنِ خالی یعنی پشتیبانی هم نمی‌داند چه شده.
      */}
      <Modal
        open={suspending !== null}
        title={suspending ? `تعلیق «${suspending.name}»` : ''}
        onClose={() => setSuspending(null)}
      >
        <div className="flex flex-col gap-3">
          <p className="text-[13px] leading-7" style={{ color: 'var(--text-secondary)' }}>
            پس از تعلیق، هیچ‌یک از اعضای این مجتمع نمی‌تواند وارد پنل شود و همین متن به آن‌ها نشان
            داده می‌شود.
          </p>

          <textarea
            value={reason}
            onChange={(event) => {
              setReason(event.target.value)
              setReasonError(null)
            }}
            aria-invalid={reasonError ? true : undefined}
            aria-describedby={reasonError ? 'suspend-reason-error' : undefined}
            rows={3}
            maxLength={255}
            placeholder="مثلاً: عدم تمدید اشتراک از مهر ۱۴۰۵"
            className="field resize-none rounded-xl px-3 py-2 text-[13px]"
          />

          {reasonError && (
            <p
              id="suspend-reason-error"
              role="alert"
              className="text-xs"
              style={{ color: 'var(--color-danger)' }}
            >
              {reasonError}
            </p>
          )}

          <button
            type="button"
            onClick={() => void suspend()}
            disabled={busy}
            className="rounded-xl py-2.5 text-[13px] font-bold text-white disabled:opacity-50"
            style={{ backgroundColor: 'var(--color-danger)' }}
          >
            تعلیق دسترسی
          </button>
        </div>
      </Modal>
    </div>
  )
}

interface InviteResult {
  link: string
  phone: string | null
  expiresAt: string | null
}

function ComplexForm({
  onSaved,
  onCancel,
}: {
  onSaved: (result: InviteResult) => void
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ComplexFormValues>({
    resolver: zodResolver(complexSchema),
    defaultValues: {
      name: '',
      type: 'residential',
      address: '',
      admin_phone: '',
    },
  })

  async function onSubmit(values: ComplexFormValues) {
    setFormError(null)

    try {
      const result = await api<{
        link: string
        invitation: { phone: string | null; expiresAt: string | null }
      }>('/system/complexes', { method: 'POST', body: values })
      onSaved({
        link: result.link,
        phone: result.invitation.phone,
        expiresAt: result.invitation.expiresAt,
      })
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
      <SelectField
        label="نوع مجتمع"
        error={errors.type?.message}
        options={[
          { value: 'residential', label: 'مسکونی' },
          { value: 'commercial', label: 'تجاری' },
        ]}
        {...register('type')}
      />
      <TextField label="آدرس (اختیاری)" error={errors.address?.message} {...register('address')} />

      <div
        className="mt-1 rounded-xl px-3.5 py-2.5 text-[11px] leading-6"
        style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-tertiary)' }}
      >
        برای شماره‌ی مدیر دعوت ساخته می‌شود؛ حسابی ساخته نمی‌شود. مدیر خودش با پیوندِ دعوت ثبت‌نام
        می‌کند، نام و رمزش را خودش می‌گذارد و با کدِ پیامکیِ ثبت‌نام مستقیم مدیر می‌شود.
      </div>

      <TextField
        label="شماره موبایل مدیر"
        dir="ltr"
        inputMode="numeric"
        error={errors.admin_phone?.message}
        {...register('admin_phone')}
      />

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
