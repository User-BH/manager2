import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Save } from 'lucide-react'
import { z } from 'zod'
import { TextField } from '@/components/ui/Field'
import { api, ApiError } from '@/lib/api'
import { alertError, toastSuccess } from '@/lib/alert'
import type { ProfileFields } from './types'

/**
 * شماره‌ی تلفن عمداً اینجا نیست: کلید ورود به سامانه است و تغییرش باید با
 * تایید پیامکی انجام شود، نه با یک فرم ساده. سرور هم آن را نمی‌پذیرد.
 */
const schema = z.object({
  name: z.string().trim().min(2, 'نام را کامل وارد کنید.').max(255),
  email: z.union([z.literal(''), z.email('ایمیل معتبر نیست.')]),
  national_id: z.string().trim().max(20).optional(),
  birth_date: z.string().optional(),
  emergency_phone: z.string().trim().max(20).optional(),
  address: z.string().trim().max(255).optional(),
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
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ProfileValues>({
    resolver: zodResolver(schema),
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
      // فیلدهای خالی به‌جای رشته‌ی تهی، null فرستاده می‌شوند تا اعتبارسنجی
      // «nullable» سرور آن‌ها را واقعاً خالی ببیند نه مقداری نامعتبر.
      await api('/profile', {
        method: 'PUT',
        body: Object.fromEntries(
          Object.entries(values).map(([key, value]) => [key, value === '' ? null : value]),
        ),
      })

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
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
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
        <TextField label="کد ملی" inputMode="numeric" error={errors.national_id?.message} {...register('national_id')} />
        <TextField label="تاریخ تولد" type="date" dir="ltr" error={errors.birth_date?.message} {...register('birth_date')} />
        <TextField
          label="شماره تماس اضطراری"
          inputMode="tel"
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
