import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Save } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { CheckField, SelectField, TextAreaField, TextField } from '@/shared/ui/Field'
import { ErrorState } from '@/shared/ui/PageState'
import { FormSkeleton } from '@/shared/ui/Skeleton'
import { api, ApiError } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { toastSuccess } from '@/shared/lib/alert'
import { useDocumentTitle } from '@/shared/hooks'

interface Option {
  value: string
  label: string
}

interface Response {
  information: {
    unitType: string | null
    block: string | null
    roomsCount: number | null
    bedroomsCount: number | null
    bathroomsCount: number | null
    parkingNumbers: string | null
    storageNumbers: string | null
    hasTerrace: boolean | null
    hasBalcony: boolean | null
    orientation: string | null
    meters: Partial<Record<'water' | 'electricity' | 'gas', string>>
    description: string | null
    completedAt: string | null
  }
  managed: {
    unitNumber: string
    floor: number
    building: string | null
    area: number
    parkingCount: number
    storageCount: number
    occupancyLabel: string | null
  }
  people: { name: string; phone: string; relationLabel: string }[]
  unitTypeOptions: Option[]
  orientationOptions: Option[]
}

const METERS: ['water' | 'electricity' | 'gas', string][] = [
  ['water', 'کنتور آب'],
  ['electricity', 'کنتور برق'],
  ['gas', 'کنتور گاز'],
]

/**
 * «اطلاعاتِ واحد» (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۹) — برای مالک و مستأجرِ فعلی و مدیر.
 *
 * فقط مشخصاتِ توصیفی نوشتنی‌اند. شماره، طبقه، مساحت و دیگر ورودی‌های شارژ از فرمِ واحدِ مدیر می‌آیند و این‌جا
 * فقط دیده می‌شوند؛ مالک و مستأجر و تماسشان هم از دوره‌های سکونت — سرور هم همین را جداگانه اعمال می‌کند.
 */
export function UnitInformationPage() {
  const { unitId } = useParams()
  useDocumentTitle('اطلاعات واحد')
  const queryClient = useQueryClient()
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.units.information(Number(unitId)),
    queryFn: ({ signal }) => api<Response>(`/units/${unitId}/information`, { signal }),
  })

  if (isLoading) return <FormSkeleton />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data || !unitId) return null

  // فرم از دادهٔ همین بار ساخته می‌شود؛ پس از ذخیره دادهٔ تازه با `key` فرمِ تازه می‌سازد
  return (
    <UnitForm
      key={data.information.completedAt ?? 'new'}
      unitId={unitId}
      data={data}
      onSaved={async () => {
        await Promise.all([
          refetch(),
          queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all() }),
        ])
      }}
    />
  )
}

function UnitForm({
  unitId,
  data,
  onSaved,
}: {
  unitId: string
  data: Response
  onSaved: () => Promise<void>
}) {
  const i = data.information
  const [values, setValues] = useState<Record<string, string>>({
    unit_type: i.unitType ?? '',
    block: i.block ?? '',
    rooms_count: i.roomsCount?.toString() ?? '',
    bedrooms_count: i.bedroomsCount?.toString() ?? '',
    bathrooms_count: i.bathroomsCount?.toString() ?? '',
    parking_numbers: i.parkingNumbers ?? '',
    storage_numbers: i.storageNumbers ?? '',
    orientation: i.orientation ?? '',
    description: i.description ?? '',
  })
  const [terrace, setTerrace] = useState(Boolean(i.hasTerrace))
  const [balcony, setBalcony] = useState(Boolean(i.hasBalcony))
  const [meters, setMeters] = useState<Record<string, string>>({
    water: i.meters.water ?? '',
    electricity: i.meters.electricity ?? '',
    gas: i.meters.gas ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  const field = (name: string) => ({
    value: values[name] ?? '',
    error: errors[name],
    onChange: (event: { target: { value: string } }) =>
      setValues((current) => ({ ...current, [name]: event.target.value })),
  })
  const optional = (value: string) => (value.trim() === '' ? null : value.trim())
  const count = (value: string) => (value.trim() === '' ? null : Number(value))

  async function save() {
    setSaving(true)
    setErrors({})
    try {
      await api(`/units/${unitId}/information`, {
        method: 'PUT',
        body: {
          unit_type: optional(values.unit_type),
          block: optional(values.block),
          rooms_count: count(values.rooms_count),
          bedrooms_count: count(values.bedrooms_count),
          bathrooms_count: count(values.bathrooms_count),
          parking_numbers: optional(values.parking_numbers),
          storage_numbers: optional(values.storage_numbers),
          orientation: optional(values.orientation),
          description: optional(values.description),
          has_terrace: terrace,
          has_balcony: balcony,
          meters: Object.fromEntries(
            Object.entries(meters).map(([key, value]) => [key, optional(value)]),
          ),
        },
      })
      toastSuccess('اطلاعاتِ واحد ذخیره شد.')
      await onSaved()
    } catch (err) {
      if (err instanceof ApiError) {
        const next: Record<string, string> = {}
        for (const name of [...Object.keys(values), 'meters']) {
          const message = err.fieldError(name)
          if (message) next[name] = message
        }
        setErrors(next)
      }
    } finally {
      setSaving(false)
    }
  }

  const m = data.managed
  const managed: [string, string][] = [
    ['شماره واحد', m.unitNumber],
    ['طبقه', String(m.floor)],
    ['ساختمان', m.building ?? '—'],
    ['مساحت (متر مربع)', String(m.area)],
    ['تعداد پارکینگ', String(m.parkingCount)],
    ['تعداد انباری', String(m.storageCount)],
    ['وضعیت سکونت', m.occupancyLabel ?? '—'],
  ]

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          اطلاعات واحد {m.unitNumber}
        </h1>
        <p className="mt-1 text-xs" style={{ color: 'var(--text-tertiary)' }}>
          مشخصاتِ شارژ (مساحت، طبقه، …) را مدیرِ مجتمع ثبت می‌کند؛ بقیه را شما تکمیل کنید.
        </p>
      </header>

      <Card title="ثبت‌شده به دستِ مدیر">
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {managed.map(([label, value]) => (
            <div
              key={label}
              className="rounded-xl px-3 py-2"
              style={{ backgroundColor: 'var(--surface-sunken)' }}
            >
              <dt className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
                {label}
              </dt>
              <dd className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                {value}
              </dd>
            </div>
          ))}
        </dl>
      </Card>

      <Card title="ساکنانِ فعلی">
        <ul className="flex flex-col gap-2">
          {data.people.map((p) => (
            <li key={`${p.relationLabel}-${p.phone}`} className="flex justify-between text-sm">
              <span style={{ color: 'var(--text-primary)' }}>
                {p.name} · {p.relationLabel}
              </span>
              <span dir="ltr" className="tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                {p.phone}
              </span>
            </li>
          ))}
        </ul>
      </Card>

      <Card title="مشخصات واحد">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <SelectField
            label="نوع واحد"
            placeholder="انتخاب کنید"
            options={data.unitTypeOptions}
            {...field('unit_type')}
          />
          <TextField label="بلوک" {...field('block')} />
          <TextField label="تعداد اتاق" inputMode="numeric" {...field('rooms_count')} />
          <TextField label="تعداد خواب" inputMode="numeric" {...field('bedrooms_count')} />
          <TextField label="تعداد سرویس" inputMode="numeric" {...field('bathrooms_count')} />
          <SelectField
            label="جهت"
            placeholder="انتخاب کنید"
            options={data.orientationOptions}
            {...field('orientation')}
          />
          <TextField label="شماره پارکینگ" {...field('parking_numbers')} />
          <TextField label="شماره انباری" {...field('storage_numbers')} />
        </div>
        <div className="mt-4 flex flex-wrap gap-4">
          <CheckField
            label="تراس"
            checked={terrace}
            onChange={(e) => setTerrace(e.target.checked)}
          />
          <CheckField
            label="بالکن"
            checked={balcony}
            onChange={(e) => setBalcony(e.target.checked)}
          />
        </div>
      </Card>

      <Card title="کنتورها">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {METERS.map(([key, label]) => (
            <TextField
              key={key}
              label={label}
              dir="ltr"
              value={meters[key] ?? ''}
              error={errors[`meters.${key}`] ?? (key === 'water' ? errors.meters : undefined)}
              onChange={(e) => setMeters((current) => ({ ...current, [key]: e.target.value }))}
            />
          ))}
        </div>
        <div className="mt-4">
          <TextAreaField label="توضیحات" rows={3} {...field('description')} />
        </div>
      </Card>

      <div className="flex justify-end">
        <button
          type="button"
          onClick={() => void save()}
          disabled={saving}
          className="flex items-center gap-1.5 rounded-xl px-5 py-2.5 text-sm font-bold text-white disabled:opacity-60"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          ذخیره اطلاعات واحد
        </button>
      </div>
    </div>
  )
}
