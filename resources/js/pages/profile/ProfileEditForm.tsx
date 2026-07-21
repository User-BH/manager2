import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Save } from 'lucide-react'
import { z } from 'zod'
import { TextField } from '@/components/ui/Field'
import { JalaliDatePicker } from '@/components/ui/JalaliDatePicker'
import { api, ApiError } from '@/lib/api'
import { alertError, toastSuccess } from '@/lib/alert'
import { toEnglishDigits } from '@/lib/format'
import {
  nameField,
  optionalEmail,
  optionalNationalId,
  optionalPhone,
} from '@/lib/validation'
import type { ProfileFields } from './types'

/**
 * اعتبارسنجی دقیق هر فیلد. شماره‌ی تلفن عمداً اینجا نیست: کلید ورود است و
 * تغییرش باید با تایید پیامکی انجام شود، نه با یک فرم ساده. سرور هم آن را
 * نمی‌پذیرد.
 */
const schema = z.object({
  name: nameField,
  email: optionalEmail,
  national_id: optionalNationalId,
  birth_date: z
    .string()
    .optional()
    .refine(
      (value) => !value || !Number.isNaN(Date.parse(value)),
      'تاریخ تولد معتبر نیست.',
    )
    .refine(
      // تاریخ تولدِ آینده بی‌معنی است
      (value) => !value || new Date(value) <= new Date(),
      'تاریخ تولد نمی‌تواند در آینده باشد.',
    ),
  emergency_phone: optionalPhone,
  address: z.string().trim().max(255, 'نشانی بیش از حد طولانی است.').optional(),
  bio: z.string().trim().max(500, 'حداکثر ۵۰۰ نویسه.').optional(),
})

type ProfileValues = z.infer<typeof schema>

export function ProfileEditForm({
  profile,
  onSaved,
  onCancel,
}: {
  profile: ProfileFields
  onSaved: () => void
  onCancel: () => void
}) {
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ProfileValues>({
    resolver: zodResolver(schema),
    // اعتبارسنجی هنگام از دست دادن فوکوس، تا کاربر همان لحظه خطا را ببیند
    mode: 'onTouched',
    defaultValues: {
      name: profile.name,
      email: profile.email ?? '',
      national_id: profile.nationalId ?? '',
      birth_date: profile.birthDate ?? '',
      emergency_phone: profile.emergencyPhone ?? '',
      address: profile.address ?? '',
      bio: profile.bio ?? '',
    },
  })

  async function onSubmit(values: ProfileValues) {
    try {
      // ارقام فارسی به لاتین و فیلدهای خالی به null، تا اعتبارسنجی «nullable»
      // سرور آن‌ها را واقعاً خالی ببیند نه مقداری نامعتبر.
      const payload = {
        ...values,
        national_id: values.national_id ? toEnglishDigits(values.national_id) : null,
        emergency_phone: values.emergency_phone ? toEnglishDigits(values.emergency_phone) : null,
        email: values.email || null,
        birth_date: values.birth_date || null,
        address: values.address || null,
        bio: values.bio || null,
      }

      await api('/profile', { method: 'PUT', body: payload })

      toastSuccess('اطلاعات پروفایل ذخیره شد.')
      onSaved()
    } catch (error) {
      if (error instanceof ApiError) {
        // خطای هر فیلد زیر همان فیلد بنشیند، نه فقط در یک دیالوگ
        Object.entries(error.errors).forEach(([field, messages]) => {
          setError(field as keyof ProfileValues, { message: messages[0] })
        })
      }
      alertError(error, 'ذخیره‌ی پروفایل ممکن نشد.')
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <TextField label="نام و نام خانوادگی" error={errors.name?.message} {...register('name')} />
        <TextField
          label="شماره تلفن"
          value={profile.phone}
          disabled
          readOnly
          title="تغییر شماره تلفن از این صفحه ممکن نیست."
        />
        <TextField label="ایمیل" type="email" dir="ltr" error={errors.email?.message} {...register('email')} />
        <TextField
          label="کد ملی"
          inputMode="numeric"
          maxLength={10}
          dir="ltr"
          error={errors.national_id?.message}
          {...register('national_id')}
        />

        <Controller
          control={control}
          name="birth_date"
          render={({ field }) => (
            <JalaliDatePicker
              label="تاریخ تولد"
              value={field.value ?? ''}
              onChange={field.onChange}
              error={errors.birth_date?.message}
              maxToday
            />
          )}
        />

        <TextField
          label="شماره تماس اضطراری"
          inputMode="tel"
          dir="ltr"
          error={errors.emergency_phone?.message}
          {...register('emergency_phone')}
        />
      </div>

      <TextField label="نشانی" error={errors.address?.message} {...register('address')} />
      <TextField label="درباره من" error={errors.bio?.message} {...register('bio')} />

      <div className="flex items-center gap-2">
        <button
          type="submit"
          disabled={isSubmitting}
          className="flex items-center gap-1.5 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white disabled:opacity-60"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />}
          ذخیره تغییرات
        </button>

        <button
          type="button"
          onClick={onCancel}
          className="rounded-xl border px-4 py-2.5 text-[13px] font-medium"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
        >
          انصراف
        </button>
      </div>
    </form>
  )
}
