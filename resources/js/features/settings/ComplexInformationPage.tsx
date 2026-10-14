import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Save } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { CheckField, TextAreaField, TextField } from '@/shared/ui/Field'
import { ErrorState } from '@/shared/ui/PageState'
import { FormSkeleton } from '@/shared/ui/Skeleton'
import { api, ApiError } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { toastSuccess } from '@/shared/lib/alert'
import { useDocumentTitle } from '@/shared/hooks'

interface Information {
  name: string
  typeLabel: string
  brandName: string | null
  address: string | null
  province: string | null
  city: string | null
  postalCode: string | null
  latitude: number | null
  longitude: number | null
  description: string | null
  structure: string | null
  commonAreas: string | null
  facilities: string[]
  facilitiesOther: string | null
  phone: string | null
  board: string | null
  responseHours: string | null
  emergencyInfo: string | null
  completedAt: string | null
}

interface Derived {
  buildings: number
  blocks: number
  floors: number
  units: number
  commercialSpaces: number
  owners: number
  tenants: number
  occupiedUnits: number
  vacantUnits: number
  activeUnits: number
  managers: string[]
}

interface Response {
  information: Information
  derived: Derived
  facilityOptions: { value: string; label: string }[]
}

type Form = Record<string, string>

const TEXT_FIELDS: [keyof Information, string][] = [
  ['name', 'name'],
  ['brandName', 'brand_name'],
  ['address', 'address'],
  ['province', 'province'],
  ['city', 'city'],
  ['postalCode', 'postal_code'],
  ['description', 'description'],
  ['structure', 'structure'],
  ['commonAreas', 'common_areas'],
  ['facilitiesOther', 'facilities_other'],
  ['phone', 'phone'],
  ['board', 'board'],
  ['responseHours', 'response_hours'],
  ['emergencyInfo', 'emergency_info'],
]

/**
 * «اطلاعاتِ مجتمع» (مرحلهٔ ۱۰ · فاز ۷؛ بندهای ۷ و ۱۸).
 *
 * فقط آنچه مدیر باید بنویسد در فرم است؛ شمارش‌ها (ساختمان، واحد، مالک، مستأجر، خالی، …) و فهرستِ مدیران از خودِ
 * داده می‌آیند و فقط نمایش داده می‌شوند (بندِ ۲). نوعِ مجتمع فقط‌خواندنی است — به تعرفه بسته است. جز نشانی، استان
 * و شهر هیچ فیلدی اجباری نیست تا مدیر به پرکردنِ فیلدِ نامرتبط مجبور نشود.
 */
export function ComplexInformationPage() {
  useDocumentTitle('اطلاعات مجتمع')
  const queryClient = useQueryClient()
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.complexInformation.current(),
    queryFn: ({ signal }) => api<Response>('/complex/information', { signal }),
  })

  if (isLoading) return <FormSkeleton />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data) return null

  // فرم از دادهٔ همین بار ساخته می‌شود؛ پس از ذخیره دادهٔ تازه با `key` فرمِ تازه می‌سازد
  return (
    <InformationForm
      key={data.information.completedAt ?? 'new'}
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

function InformationForm({ data, onSaved }: { data: Response; onSaved: () => Promise<void> }) {
  const [form, setForm] = useState<Form>(() => {
    const next: Form = {}
    for (const [key, name] of TEXT_FIELDS)
      next[name] = (data.information[key] as string | null) ?? ''
    return next
  })
  const [facilities, setFacilities] = useState<string[]>(data.information.facilities)
  const [lat, setLat] = useState(data.information.latitude?.toString() ?? '')
  const [lng, setLng] = useState(data.information.longitude?.toString() ?? '')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  const field = (name: string) => ({
    value: form[name] ?? '',
    error: errors[name],
    onChange: (event: { target: { value: string } }) =>
      setForm((current) => ({ ...current, [name]: event.target.value })),
  })

  async function save() {
    setSaving(true)
    setErrors({})
    try {
      const body = Object.fromEntries(
        Object.entries(form).map(([key, value]) => [
          key,
          value.trim() === '' ? null : value.trim(),
        ]),
      )
      await api('/complex/information', {
        method: 'PUT',
        body: {
          ...body,
          facilities,
          latitude: lat.trim() === '' ? null : Number(lat),
          longitude: lng.trim() === '' ? null : Number(lng),
        },
      })
      toastSuccess('اطلاعاتِ مجتمع ذخیره شد.')
      await onSaved()
    } catch (err) {
      if (err instanceof ApiError) {
        const next: Record<string, string> = {}
        for (const name of [...Object.keys(form), 'latitude', 'longitude', 'facilities']) {
          const message = err.fieldError(name)
          if (message) next[name] = message
        }
        setErrors(next)
      }
    } finally {
      setSaving(false)
    }
  }

  const d = data.derived
  const stats: [string, number | string][] = [
    ['ساختمان', d.buildings],
    ['بلوک', d.blocks],
    ['طبقه', d.floors],
    ['واحد', d.units],
    ['فضای تجاری', d.commercialSpaces],
    ['مالک', d.owners],
    ['مستأجر', d.tenants],
    ['واحدِ پُر', d.occupiedUnits],
    ['واحدِ خالی', d.vacantUnits],
    ['واحدِ فعال', d.activeUnits],
  ]

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          اطلاعات مجتمع
        </h1>
        <p className="mt-1 text-xs" style={{ color: 'var(--text-tertiary)' }}>
          شمارش‌ها از واحدها، ساختمان‌ها و ساکنان حساب می‌شوند؛ فقط آنچه را که از داده بیرون نمی‌آید
          بنویسید.
        </p>
      </header>

      <Card title="آنچه از داده می‌آید" subtitle={`مدیران: ${d.managers.join('، ') || '—'}`}>
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          {stats.map(([label, value]) => (
            <div
              key={label}
              className="rounded-xl px-3 py-2"
              style={{ backgroundColor: 'var(--surface-sunken)' }}
            >
              <dt className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
                {label}
              </dt>
              <dd
                className="text-sm font-bold tabular-nums"
                style={{ color: 'var(--text-primary)' }}
              >
                {value}
              </dd>
            </div>
          ))}
        </dl>
      </Card>

      <Card title="هویت">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <TextField label="نام مجتمع" {...field('name')} />
          <TextField label="نوع مجتمع" value={data.information.typeLabel} readOnly disabled />
          <TextField label="نام تجاری" {...field('brand_name')} />
          <TextField label="کد پستی" dir="ltr" inputMode="numeric" {...field('postal_code')} />
          <TextField label="استان" {...field('province')} />
          <TextField label="شهر" {...field('city')} />
          <TextField label="نشانی" {...field('address')} />
          <div className="grid grid-cols-2 gap-2">
            <TextField
              label="عرض جغرافیایی"
              dir="ltr"
              inputMode="decimal"
              value={lat}
              error={errors.latitude}
              onChange={(e) => setLat(e.target.value)}
            />
            <TextField
              label="طول جغرافیایی"
              dir="ltr"
              inputMode="decimal"
              value={lng}
              error={errors.longitude}
              onChange={(e) => setLng(e.target.value)}
            />
          </div>
        </div>
        <div className="mt-4">
          <TextAreaField label="توضیحات" rows={3} {...field('description')} />
        </div>
      </Card>

      <Card title="ساختار و بخش‌های مشترک">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <TextField label="ساختار ساختمان" {...field('structure')} />
          <TextField label="بخش‌های مشترک" {...field('common_areas')} />
        </div>
      </Card>

      <Card title="امکانات">
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
          {data.facilityOptions.map((option) => (
            <CheckField
              key={option.value}
              label={option.label}
              checked={facilities.includes(option.value)}
              onChange={(e) =>
                setFacilities((current) =>
                  e.target.checked
                    ? [...current, option.value]
                    : current.filter((value) => value !== option.value),
                )
              }
            />
          ))}
        </div>
        <div className="mt-4">
          <TextField label="سایر امکانات" {...field('facilities_other')} />
        </div>
      </Card>

      <Card title="مدیریت">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <TextField label="تلفن مدیریت" dir="ltr" {...field('phone')} />
          <TextField label="ساعات پاسخ‌گویی" {...field('response_hours')} />
          <TextField label="هیئت‌مدیره" {...field('board')} />
        </div>
        <div className="mt-4">
          <TextAreaField label="اطلاعات اضطراری" rows={2} {...field('emergency_info')} />
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
          ذخیره اطلاعات مجتمع
        </button>
      </div>
    </div>
  )
}
