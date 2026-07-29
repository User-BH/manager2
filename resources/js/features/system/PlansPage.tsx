import { useState } from 'react'
import { Loader2, Plus, Save, Trash2, X, Gift } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { TextField } from '@/shared/ui/Field'
import { ErrorState, LoadingState } from '@/shared/ui/PageState'
import { useApi } from '@/shared/hooks/useApi'
import { useDocumentTitle } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/shared/lib/alert'

interface Plan {
  id: number
  name: string
  slug: string
  price: number
  priceLabel: string
  months: number
  unit_limit: number | null
  real_gateway: boolean
  excel_export: boolean
  features: string[]
  is_active: boolean
  sort_order: number
}

interface ComplexRow {
  id: number
  name: string
  activePlan: string | null
  activeUntil: string | null
}

interface PlansResponse {
  plans: Plan[]
  complexes: ComplexRow[]
}

type Draft = Omit<Plan, 'id' | 'priceLabel'> & { id?: number }

const BLANK: Draft = {
  name: '',
  slug: '',
  price: 0,
  months: 1,
  unit_limit: null,
  real_gateway: false,
  excel_export: false,
  features: [],
  is_active: true,
  sort_order: 0,
}

export function PlansPage() {
  useDocumentTitle('پکیج‌های اشتراک')

  const { data, error, isLoading, reload } = useApi<PlansResponse>('/system/plans')
  const [draft, setDraft] = useState<Draft | null>(null)
  const [saving, setSaving] = useState(false)

  async function savePlan() {
    if (!draft) return
    setSaving(true)
    const body = { ...draft, unit_limit: draft.unit_limit || null }
    try {
      if (draft.id) await api(`/system/plans/${draft.id}`, { method: 'PUT', body })
      else await api('/system/plans', { method: 'POST', body })
      toastSuccess('پکیج ذخیره شد.')
      setDraft(null)
      reload()
    } catch (err) {
      alertError(err, 'ذخیره‌ی پکیج ممکن نشد.')
    } finally {
      setSaving(false)
    }
  }

  async function toggle(plan: Plan) {
    try {
      await api(`/system/plans/${plan.id}/toggle`, { method: 'PATCH' })
      reload()
    } catch (err) {
      alertError(err)
    }
  }

  async function remove(plan: Plan) {
    const ok = await confirmAction({
      title: 'حذف پکیج',
      text: `پکیج «${plan.name}» حذف شود؟`,
      confirmLabel: 'حذف',
      danger: true,
    })
    if (!ok) return
    try {
      await api(`/system/plans/${plan.id}`, { method: 'DELETE' })
      toastSuccess('پکیج حذف شد.')
      reload()
    } catch (err) {
      alertError(err)
    }
  }

  if (isLoading) return <LoadingState rows={5} />
  if (error) return <ErrorState message={error} onRetry={reload} />
  if (!data) return null

  return (
    <div className="flex flex-col gap-5">
      <header className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            پکیج‌های اشتراک
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            پکیج‌ها را تعریف و امکاناتشان را فعال/غیرفعال کنید؛ یا دستی برای یک مجتمع فعال کنید.
          </p>
        </div>
        <button
          type="button"
          onClick={() => setDraft({ ...BLANK, sort_order: data.plans.length + 1 })}
          className="flex shrink-0 items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-bold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <Plus size={16} />
          پکیج جدید
        </button>
      </header>

      {/* فرمِ ویرایش/ساخت */}
      {draft && (
        <Card title={draft.id ? 'ویرایش پکیج' : 'پکیج جدید'}>
          <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <TextField label="نام پکیج" value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />
              <TextField label="شناسه (انگلیسی)" dir="ltr" placeholder="basic" value={draft.slug} onChange={(e) => setDraft({ ...draft, slug: e.target.value })} />
              <TextField label="قیمت (تومان)" dir="ltr" inputMode="numeric" value={String(draft.price)} onChange={(e) => setDraft({ ...draft, price: Number(e.target.value.replace(/\D/g, '')) || 0 })} />
              <TextField label="مدت (ماه)" dir="ltr" inputMode="numeric" value={String(draft.months)} onChange={(e) => setDraft({ ...draft, months: Number(e.target.value.replace(/\D/g, '')) || 1 })} />
              <TextField
                label="سقف واحد (خالی = نامحدود)"
                dir="ltr"
                inputMode="numeric"
                value={draft.unit_limit === null ? '' : String(draft.unit_limit)}
                onChange={(e) => setDraft({ ...draft, unit_limit: e.target.value.trim() === '' ? null : Number(e.target.value.replace(/\D/g, '')) || null })}
              />
              <TextField label="ترتیب نمایش" dir="ltr" inputMode="numeric" value={String(draft.sort_order)} onChange={(e) => setDraft({ ...draft, sort_order: Number(e.target.value.replace(/\D/g, '')) || 0 })} />
            </div>

            {/* امکاناتِ قابل‌اعمالِ کد */}
            <div className="flex flex-wrap gap-4 rounded-2xl border p-3" style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}>
              <Toggle label="اتصال درگاه بانکی واقعی" checked={draft.real_gateway} onChange={(v) => setDraft({ ...draft, real_gateway: v })} />
              <Toggle label="خروجی Excel قبوض" checked={draft.excel_export} onChange={(v) => setDraft({ ...draft, excel_export: v })} />
              <Toggle label="پکیج فعال (قابل خرید)" checked={draft.is_active} onChange={(v) => setDraft({ ...draft, is_active: v })} />
            </div>

            <div className="flex flex-col gap-1.5">
              <label className="text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>
                امکانات (هر خط یک مورد)
              </label>
              <textarea
                value={draft.features.join('\n')}
                onChange={(e) => setDraft({ ...draft, features: e.target.value.split('\n').map((s) => s.trim()).filter(Boolean) })}
                rows={4}
                className="w-full rounded-xl border p-3 text-[13px] outline-none focus:ring-2"
                style={{ backgroundColor: 'var(--surface-sunken)', borderColor: 'var(--border-subtle)', color: 'var(--text-primary)', ['--tw-ring-color' as string]: 'var(--ring-focus)' }}
              />
            </div>

            <div className="flex gap-2">
              <button type="button" onClick={savePlan} disabled={saving} className="flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold text-white disabled:opacity-70" style={{ backgroundColor: 'var(--color-brand-500)' }}>
                {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                ذخیره
              </button>
              <button type="button" onClick={() => setDraft(null)} className="flex items-center gap-2 rounded-xl border px-5 py-2.5 text-sm font-semibold" style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-primary)' }}>
                <X size={16} />
                انصراف
              </button>
            </div>
          </div>
        </Card>
      )}

      {/* فهرست پکیج‌ها */}
      <Card title={`پکیج‌ها (${data.plans.length})`}>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.plans.map((plan) => (
            <div key={plan.id} className="flex flex-col gap-2 rounded-2xl border p-4" style={{ borderColor: 'var(--border-subtle)', opacity: plan.is_active ? 1 : 0.6 }}>
              <div className="flex items-center justify-between">
                <span className="text-[15px] font-extrabold" style={{ color: 'var(--text-primary)' }}>{plan.name}</span>
                <span className="text-[13px] font-bold" style={{ color: 'var(--color-brand-600)' }}>{plan.priceLabel}</span>
              </div>
              <div className="text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
                {plan.months} ماهه · سقف واحد: {plan.unit_limit ?? 'نامحدود'} · {plan.real_gateway ? 'درگاه ✓' : 'درگاه ✕'} · {plan.excel_export ? 'Excel ✓' : 'Excel ✕'}
              </div>
              <div className="mt-1 flex gap-2">
                <button type="button" onClick={() => setDraft({ ...plan })} className="rounded-lg border px-3 py-1.5 text-[12px] font-semibold" style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-primary)' }}>ویرایش</button>
                <button type="button" onClick={() => toggle(plan)} className="rounded-lg border px-3 py-1.5 text-[12px] font-semibold" style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}>{plan.is_active ? 'غیرفعال' : 'فعال'}</button>
                <button type="button" onClick={() => remove(plan)} className="rounded-lg border px-2.5 py-1.5" style={{ borderColor: 'var(--border-subtle)', color: 'var(--color-danger)' }}><Trash2 size={13} /></button>
              </div>
            </div>
          ))}
        </div>
      </Card>

      {/* فعال‌سازیِ دستی برای مجتمع */}
      <ManualGrant plans={data.plans} complexes={data.complexes} onDone={reload} />
    </div>
  )
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <label className="flex items-center gap-2 text-[12.5px] font-medium" style={{ color: 'var(--text-secondary)' }}>
      <input type="checkbox" className="h-4 w-4 rounded" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      {label}
    </label>
  )
}

function ManualGrant({ plans, complexes, onDone }: { plans: Plan[]; complexes: ComplexRow[]; onDone: () => void }) {
  const [complexId, setComplexId] = useState('')
  const [planId, setPlanId] = useState('')
  const [months, setMonths] = useState('')
  const [busy, setBusy] = useState(false)

  async function grant() {
    if (!complexId || !planId) return
    setBusy(true)
    try {
      const res = await api<{ message: string }>('/system/plans/grant', {
        method: 'POST',
        body: { complex_id: Number(complexId), plan_id: Number(planId), months: months ? Number(months) : undefined },
      })
      toastSuccess(res.message)
      onDone()
    } catch (err) {
      alertError(err, 'فعال‌سازی ممکن نشد.')
    } finally {
      setBusy(false)
    }
  }

  async function revoke(id: number) {
    setBusy(true)
    try {
      const res = await api<{ message: string }>('/system/plans/revoke', { method: 'POST', body: { complex_id: id } })
      toastSuccess(res.message)
      onDone()
    } catch (err) {
      alertError(err)
    } finally {
      setBusy(false)
    }
  }

  const selectStyle = {
    backgroundColor: 'var(--surface-sunken)',
    borderColor: 'var(--border-subtle)',
    color: 'var(--text-primary)',
    ['--tw-ring-color' as string]: 'var(--ring-focus)',
  }

  return (
    <Card title="فعال‌سازیِ دستیِ پلن برای مجتمع" subtitle="بدونِ پرداخت — برای آفر یا هدیه" delay={0.05}>
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[180px] flex-1">
            <label className="mb-1.5 block text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>مجتمع</label>
            <select value={complexId} onChange={(e) => setComplexId(e.target.value)} className="w-full rounded-xl border py-3 px-3 text-[13px] outline-none focus:ring-2" style={selectStyle}>
              <option value="">انتخاب کنید…</option>
              {complexes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div className="min-w-[150px] flex-1">
            <label className="mb-1.5 block text-[13px] font-medium" style={{ color: 'var(--text-secondary)' }}>پکیج</label>
            <select value={planId} onChange={(e) => setPlanId(e.target.value)} className="w-full rounded-xl border py-3 px-3 text-[13px] outline-none focus:ring-2" style={selectStyle}>
              <option value="">انتخاب کنید…</option>
              {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </div>
          <div className="w-28">
            <TextField label="مدت (ماه)" dir="ltr" inputMode="numeric" placeholder="پیش‌فرض" value={months} onChange={(e) => setMonths(e.target.value.replace(/\D/g, ''))} />
          </div>
          <button type="button" onClick={grant} disabled={busy || !complexId || !planId} className="flex items-center gap-1.5 rounded-xl px-5 py-3 text-[13px] font-bold text-white disabled:opacity-60" style={{ backgroundColor: 'var(--color-accent-500)' }}>
            <Gift size={15} />
            فعال کن
          </button>
        </div>

        {/* مجتمع‌هایی که اشتراک فعال دارند */}
        <div className="flex flex-col divide-y" style={{ borderColor: 'var(--border-subtle)' }}>
          {complexes.filter((c) => c.activePlan).map((c) => (
            <div key={c.id} className="flex items-center justify-between py-2.5 text-[12.5px]">
              <span style={{ color: 'var(--text-primary)' }}>
                <b>{c.name}</b> — {c.activePlan} {c.activeUntil ? `(تا ${c.activeUntil})` : ''}
              </span>
              <button type="button" onClick={() => revoke(c.id)} disabled={busy} className="rounded-lg border px-3 py-1 text-[11.5px] font-semibold" style={{ borderColor: 'var(--border-subtle)', color: 'var(--color-danger)' }}>
                غیرفعال‌سازی
              </button>
            </div>
          ))}
          {complexes.filter((c) => c.activePlan).length === 0 && (
            <p className="py-3 text-center text-[12px]" style={{ color: 'var(--text-tertiary)' }}>هیچ مجتمعی اشتراکِ فعال ندارد.</p>
          )}
        </div>
      </div>
    </Card>
  )
}
