import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Save, AlertCircle } from 'lucide-react'
import { SelectField, TextField } from '@/components/ui/Field'
import { api, ApiError } from '@/lib/api'
import { residentSchema, type ResidentFormValues } from './schema'
import type { Resident, ResidentFilters } from './types'

interface ResidentFormProps {
  resident: Resident | null
  filters: ResidentFilters
  onSaved: () => void
  onCancel: () => void
}

export function ResidentForm({ resident, filters, onSaved, onCancel }: ResidentFormProps) {
  const [formError, setFormError] = useState<string | null>(null)
  const isEditing = resident !== null

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ResidentFormValues>({
    resolver: zodResolver(residentSchema(isEditing)),
    defaultValues: {
      name: resident?.name ?? '',
      phone: resident?.phone ?? '',
      email: resident?.email ?? '',
      national_id: resident?.nationalId ?? '',
      role: resident?.role ?? 'tenant',
      unit_id: resident?.units[0]?.id ? String(resident.units[0].id) : '',
      password: '',
    },
  })

  async function onSubmit(values: ResidentFormValues) {
    setFormError(null)

    try {
      await api(isEditing ? `/residents/${resident.id}` : '/residents', {
        method: isEditing ? 'PUT' : 'POST',
        body: {
          ...values,
          unit_id: values.unit_id || null,
          email: values.email || null,
          national_id: values.national_id || null,
          password: values.password || null,
        },
      })
      onSaved()
    } catch (error) {
      if (error instanceof ApiError) {
        let handled = false
        for (const [field, messages] of Object.entries(error.errors)) {
          setError(field as keyof ResidentFormValues, { message: messages[0] })
          handled = true
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
        <TextField label="نام و نام خانوادگی" error={errors.name?.message} {...register('name')} />
        <TextField label="شماره موبایل" inputMode="numeric" dir="ltr" error={errors.phone?.message} {...register('phone')} />
        <SelectField label="نقش" options={filters.roleOptions} error={errors.role?.message} {...register('role')} />
        <SelectField
          label="واحد"
          placeholder="بدون واحد"
          options={filters.units.map((u) => ({ value: u.id, label: `واحد ${u.unit_number}` }))}
          error={errors.unit_id?.message}
          {...register('unit_id')}
        />
        <TextField label="ایمیل (اختیاری)" type="email" dir="ltr" error={errors.email?.message} {...register('email')} />
        <TextField label="کد ملی (اختیاری)" dir="ltr" error={errors.national_id?.message} {...register('national_id')} />
      </div>

      <TextField
        label={isEditing ? 'رمز عبور جدید (خالی بگذارید تا تغییر نکند)' : 'رمز عبور'}
        type="password"
        dir="ltr"
        error={errors.password?.message}
        {...register('password')}
      />

      <div className="mt-2 flex items-center gap-2">
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-70"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          {isEditing ? 'ذخیره تغییرات' : 'ثبت ساکن'}
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
