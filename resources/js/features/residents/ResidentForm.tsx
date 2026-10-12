import { useState, type ChangeEvent } from 'react'
import { useForm, type FieldValues, type Path, type UseFormSetError } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Save, AlertCircle, Send } from 'lucide-react'
import { SelectField, TextField } from '@/shared/ui/Field'
import { api, ApiError } from '@/shared/lib/api'
import { filterMobile } from '@/shared/lib/inputFilters'
import {
  editResidentSchema,
  inviteResidentSchema,
  type EditResidentValues,
  type InviteResidentValues,
} from './schema'
import type { Resident, ResidentFilters } from './types'

export interface InviteResult {
  link: string
  phone: string | null
  expiresAt: string | null
}

interface ResidentFormProps {
  resident: Resident | null
  filters: ResidentFilters
  onSaved: () => void
  onInvited: (result: InviteResult) => void
  onCancel: () => void
}

/**
 * فرمِ ساکن — دو کار با دو فرمِ جدا (مرحلهٔ ۹ · فاز ۶).
 *
 * • «ساکن جدید» دیگر حساب نمی‌سازد: شماره، نقش و واحد → **دعوتِ امن**؛ پیوندِ
 *   یک‌بارمصرف به `onInvited` می‌رود (F5، F6).
 * • ویرایش شماره و رمز ندارد: هر دو مالِ صاحبِ حساب‌اند (تصاحبِ حساب).
 */
export function ResidentForm({
  resident,
  filters,
  onSaved,
  onInvited,
  onCancel,
}: ResidentFormProps) {
  return resident ? (
    <EditResidentForm resident={resident} filters={filters} onSaved={onSaved} onCancel={onCancel} />
  ) : (
    <InviteResidentForm filters={filters} onInvited={onInvited} onCancel={onCancel} />
  )
}

function InviteResidentForm({
  filters,
  onInvited,
  onCancel,
}: Pick<ResidentFormProps, 'filters' | 'onInvited' | 'onCancel'>) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<InviteResidentValues>({
    resolver: zodResolver(inviteResidentSchema),
    defaultValues: { phone: '', role: 'tenant', unit_id: '' },
  })

  async function onSubmit(values: InviteResidentValues) {
    setFormError(null)

    try {
      const result = await api<{
        link: string
        invitation: { phone: string | null; expiresAt: string | null }
      }>('/residents', { method: 'POST', body: { ...values, unit_id: Number(values.unit_id) } })
      onInvited({
        link: result.link,
        phone: result.invitation.phone,
        expiresAt: result.invitation.expiresAt,
      })
    } catch (error) {
      setFormError(showServerErrors(error, setError))
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
      <FormError message={formError} />

      <p className="text-xs leading-6" style={{ color: 'var(--text-tertiary)' }}>
        برای این شماره دعوت ساخته می‌شود. نام و رمز را صاحبِ شماره خودش هنگامِ ثبت‌نام با پیوندِ
        دعوت وارد می‌کند و با کدِ پیامکیِ ثبت‌نام مستقیم عضو می‌شود.
      </p>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <TextField
          label="شماره موبایل"
          inputMode="numeric"
          dir="ltr"
          error={errors.phone?.message}
          {...register('phone', {
            onChange: (event: ChangeEvent<HTMLInputElement>) => {
              event.target.value = filterMobile(event.target.value).value
            },
          })}
        />
        <SelectField
          label="نقش"
          options={filters.roleOptions}
          error={errors.role?.message}
          {...register('role')}
        />
        <SelectField
          label="واحد"
          placeholder="انتخاب واحد"
          options={filters.units.map((u) => ({ value: u.id, label: `واحد ${u.unit_number}` }))}
          error={errors.unit_id?.message}
          {...register('unit_id')}
        />
      </div>

      <Actions
        submitting={isSubmitting}
        label="ساختِ دعوت"
        icon={<Send size={16} />}
        onCancel={onCancel}
      />
    </form>
  )
}

function EditResidentForm({
  resident,
  filters,
  onSaved,
  onCancel,
}: Pick<ResidentFormProps, 'filters' | 'onSaved' | 'onCancel'> & { resident: Resident }) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<EditResidentValues>({
    resolver: zodResolver(editResidentSchema),
    defaultValues: {
      name: resident.name,
      email: resident.email ?? '',
      national_id: resident.nationalId ?? '',
      role: resident.role,
      unit_id: resident.units[0]?.id ? String(resident.units[0].id) : '',
    },
  })

  async function onSubmit(values: EditResidentValues) {
    setFormError(null)

    try {
      await api(`/residents/${resident.id}`, {
        method: 'PUT',
        body: {
          ...values,
          unit_id: Number(values.unit_id),
          email: values.email || null,
          national_id: values.national_id || null,
        },
      })
      onSaved()
    } catch (error) {
      setFormError(showServerErrors(error, setError))
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
      <FormError message={formError} />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <TextField label="نام و نام خانوادگی" error={errors.name?.message} {...register('name')} />
        {/* شماره مالِ صاحبِ حساب است؛ فقط نمایش (مرحلهٔ ۹ · فاز ۶) */}
        <TextField label="شماره موبایل" dir="ltr" value={resident.phone} readOnly disabled />
        <SelectField
          label="نقش"
          options={filters.roleOptions}
          error={errors.role?.message}
          {...register('role')}
        />
        <SelectField
          label="واحد"
          placeholder="انتخاب واحد"
          options={filters.units.map((u) => ({ value: u.id, label: `واحد ${u.unit_number}` }))}
          error={errors.unit_id?.message}
          {...register('unit_id')}
        />
        <TextField
          label="ایمیل (اختیاری)"
          type="email"
          dir="ltr"
          error={errors.email?.message}
          {...register('email')}
        />
        <TextField
          label="کد ملی (اختیاری)"
          dir="ltr"
          error={errors.national_id?.message}
          {...register('national_id')}
        />
      </div>

      <Actions
        submitting={isSubmitting}
        label="ذخیره تغییرات"
        icon={<Save size={16} />}
        onCancel={onCancel}
      />
    </form>
  )
}

/** خطای فیلدیِ سرور زیرِ همان فیلد؛ بقیه پیامِ کلیِ فرم. */
function showServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
): string | null {
  if (!(error instanceof ApiError)) return 'ارتباط با سرور برقرار نشد.'

  let handled = false
  for (const [field, messages] of Object.entries(error.errors)) {
    setError(field as Path<T>, { message: messages[0] })
    handled = true
  }

  return handled ? null : error.message
}

function FormError({ message }: { message: string | null }) {
  if (!message) return null

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

function Actions({
  submitting,
  label,
  icon,
  onCancel,
}: {
  submitting: boolean
  label: string
  icon: React.ReactNode
  onCancel: () => void
}) {
  return (
    <div className="mt-2 flex items-center gap-2">
      <button
        type="submit"
        disabled={submitting}
        className="flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-70"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        {submitting ? <Loader2 size={16} className="animate-spin" /> : icon}
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
