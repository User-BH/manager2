import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Save, AlertCircle } from 'lucide-react'
import { CheckField, SelectField, TextField } from '@/components/ui/Field'
import { api, ApiError } from '@/lib/api'
import { unitSchema, type UnitFormInput, type UnitFormValues } from './schema'
import type { Unit, UnitFilters } from './types'

interface UnitFormProps {
  unit: Unit | null
  filters: UnitFilters
  onSaved: () => void
  onCancel: () => void
}

export function UnitForm({ unit, filters, onSaved, onCancel }: UnitFormProps) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<UnitFormInput, unknown, UnitFormValues>({
    resolver: zodResolver(unitSchema),
    defaultValues: {
      unit_number: unit?.unitNumber ?? '',
      building_id: unit?.buildingId ? String(unit.buildingId) : '',
      floor: unit?.floor ?? 1,
      area: unit?.area ?? 0,
      residents_count: unit?.residentsCount ?? 1,
      parking_count: unit?.parkingCount ?? 0,
      occupancy_status: unit?.occupancyStatus ?? filters.occupancyOptions[0]?.value ?? '',
      coefficient: unit?.coefficient ?? 1,
      uses_elevator: unit?.usesElevator ?? true,
      notes: unit?.notes ?? '',
    },
  })

  async function onSubmit(values: UnitFormValues) {
    setFormError(null)

    try {
      await api(unit ? `/units/${unit.id}` : '/units', {
        method: unit ? 'PUT' : 'POST',
        body: { ...values, building_id: values.building_id || null },
      })
      onSaved()
    } catch (error) {
      if (error instanceof ApiError) {
        // خطاهای اعتبارسنجی سرور را روی همان فیلدها می‌نشانیم
        let handled = false
        for (const [field, messages] of Object.entries(error.errors)) {
          if (field in unitSchema.shape) {
            setError(field as keyof UnitFormInput, { message: messages[0] })
            handled = true
          }
        }
        if (!handled) setFormError(error.message)
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

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <TextField label="شماره واحد" error={errors.unit_number?.message} {...register('unit_number')} />
        <SelectField
          label="ساختمان"
          placeholder="—"
          options={filters.buildings.map((b) => ({ value: b.id, label: b.name }))}
          error={errors.building_id?.message}
          {...register('building_id')}
        />
        <TextField label="طبقه" type="number" error={errors.floor?.message} {...register('floor')} />
        <TextField label="متراژ (متر مربع)" type="number" step="0.01" error={errors.area?.message} {...register('area')} />
        <TextField label="تعداد ساکنین" type="number" error={errors.residents_count?.message} {...register('residents_count')} />
        <TextField label="تعداد پارکینگ" type="number" error={errors.parking_count?.message} {...register('parking_count')} />
        <SelectField
          label="وضعیت سکونت"
          options={filters.occupancyOptions}
          error={errors.occupancy_status?.message}
          {...register('occupancy_status')}
        />
        <TextField label="ضریب شارژ" type="number" step="0.01" error={errors.coefficient?.message} {...register('coefficient')} />
      </div>

      <TextField label="توضیحات" error={errors.notes?.message} {...register('notes')} />
      <CheckField label="از آسانسور استفاده می‌کند" {...register('uses_elevator')} />

      <div className="mt-2 flex items-center gap-2">
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-70"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          {unit ? 'ذخیره تغییرات' : 'ثبت واحد'}
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
