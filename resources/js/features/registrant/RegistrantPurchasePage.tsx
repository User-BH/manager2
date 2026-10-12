import { useEffect, useId, useRef, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { Building2, CreditCard, Loader2 } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { alertInfo } from '@/shared/lib/alert'
import { formatNumber } from '@/shared/lib/format'
import { filterPersianAlphanumeric } from '@/shared/lib/inputFilters'
import { useDocumentTitle } from '@/shared/hooks'
import { useAuth } from '@/shared/stores/authStore'
import { CardListSkeleton } from '@/shared/ui/Skeleton'
import { ErrorState } from '@/shared/ui/PageState'
import { purchaseSchema, type PurchaseValues } from './schemas/purchaseSchema'

type ComplexTypeValue = PurchaseValues['complexType']

interface PlanOption {
  value: string
  label: string
  price: number
  priceLabel: string
  months: number
  features: string[]
}

interface PurchaseResponse {
  checkoutEnabled: boolean
  checkoutAction: string
  /** فقط برای حسابِ اولیه؛ از همان قاعده‌ای که سرور هنگامِ خرید می‌پذیرد */
  newComplexPlans: Record<ComplexTypeValue, PlanOption[]> | null
}

const TYPES: { value: ComplexTypeValue; label: string }[] = [
  { value: 'residential', label: 'مسکونی' },
  { value: 'commercial', label: 'تجاری' },
]

/**
 * ساختِ مجتمع و خریدِ اشتراک — صفحه‌ی «حساب و اشتراک» ِ حسابِ اولیه
 * (مرحلهٔ ۹ · فاز ۵؛ تصمیمِ قطعیِ کارفرما).
 *
 *   نام و نوعِ مجتمع → پلن → پرداختِ آنلاین → تأییدِ سرور →
 *   مجتمع + اشتراک + مدیر در یک تراکنش → داشبوردِ مدیر
 *
 * ⚠️ صفحه‌ی جدای همین تجربه، نه `AccountPage` ِ مشترک: آن صفحه مالِ مدیر است و
 * طبقِ «بررسی جدید ۲» دست نمی‌خورد. اینجا **رسید نیست** — رسید هیچ نقشی در
 * مدیر شدن ندارد (F1، F2)؛ سرور هم رسیدِ حسابِ اولیه را رد می‌کند.
 *
 * ⚠️ مبلغی که نشان داده می‌شود فقط نمایش است: فرم فقط slug ِ پلن، نام و نوع
 * را می‌فرستد و سرور مبلغ را از خودِ پلن می‌خواند (`ComplexPurchase`).
 */
export function RegistrantPurchasePage() {
  useDocumentTitle('ساختِ مجتمع و اشتراک')
  const [params, setParams] = useSearchParams()
  const gatewayFormRef = useRef<HTMLFormElement>(null)
  const nameId = useId()
  const addressId = useId()
  const [gatewayError, setGatewayError] = useState<string | null>(null)
  // عضوِ مجتمع‌های دیگر هم از همین صفحه می‌خرد (مرحلهٔ ۱۰ · فاز ۵)
  const isMember = useAuth().user?.accountState === 'member'
  const [submitting, setSubmitting] = useState(false)

  const {
    control,
    register,
    handleSubmit,
    setError,
    setValue,
    formState: { errors },
  } = useForm<PurchaseValues>({
    resolver: zodResolver(purchaseSchema),
    defaultValues: { complexName: '', address: '', plan: '' },
  })
  const type = useWatch({ control, name: 'complexType' })
  const plan = useWatch({ control, name: 'plan' })

  const {
    data,
    isLoading,
    error: loadError,
    refetch,
  } = useQuery({
    queryKey: queryKeys.subscription.all(),
    queryFn: ({ signal }) => api<PurchaseResponse>('/subscription', { signal }),
  })

  /* بازگشت از درگاهِ ناموفق با پارامتر در آدرس اعلام می‌شود؛ موفق به داشبوردِ مدیر می‌رود */
  useEffect(() => {
    const checkout = params.get('checkout')
    if (!checkout) return

    if (checkout === 'failed') {
      void alertInfo('پرداخت انجام نشد.', 'مجتمعی ساخته نشد. می‌توانید دوباره تلاش کنید.')
    } else if (checkout === 'unknown') {
      void alertInfo(
        'نتیجه‌ی پرداخت مشخص نشد.',
        'ارتباط با درگاه قطع شد و وضعیت روشن نیست. تا بررسی دوباره پرداخت نکنید؛ اگر مبلغی کسر شده باشد پیگیری می‌شود.',
      )
    } else if (checkout === 'review') {
      void alertInfo('پرداخت تأیید شد ولی مجتمع ساخته نشد.', 'پشتیبانی آن را بررسی می‌کند.')
    } else if (checkout === 'error') {
      void alertInfo('اتصال به درگاه ممکن نشد.', params.get('message') ?? undefined)
    }

    const next = new URLSearchParams(params)
    next.delete('checkout')
    next.delete('message')
    setParams(next, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params])

  if (isLoading) return <CardListSkeleton items={2} />
  if (loadError) return <ErrorState error={loadError} onRetry={() => void refetch()} />
  if (!data) return null

  const plans = type ? (data.newComplexPlans?.[type] ?? []) : []

  function pay(values: PurchaseValues) {
    if (submitting) return

    // پلن باید مالِ همین نوع باشد؛ سرور هم دوباره می‌سنجد
    if (!plans.some((option) => option.value === values.plan)) {
      setError('plan', { message: 'یک پکیج انتخاب کنید.' })
      return
    }
    if (!data?.checkoutEnabled) {
      setGatewayError('پرداختِ آنلاین هنوز فعال نشده است.')
      return
    }

    setGatewayError(null)
    setSubmitting(true)

    const form = gatewayFormRef.current
    if (!form) return
    const set = (field: string, value: string) => {
      ;(form.elements.namedItem(field) as HTMLInputElement).value = value
    }
    set('plan', values.plan)
    set('complex_name', values.complexName)
    set('complex_type', values.complexType)
    set('complex_address', values.address)
    // فرمِ واقعی، نه fetch: مرورگر باید صفحه را ترک کند و به درگاه برود
    form.submit()
  }

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-5">
      <div>
        <h1 className="text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
          ساختِ مجتمع و خریدِ اشتراک
        </h1>
        <p className="mt-1 text-sm leading-6" style={{ color: 'var(--text-secondary)' }}>
          {isMember
            ? 'پس از پرداختِ موفق، مجتمعِ تازه ساخته می‌شود و مدیرِ آن می‌شوید. عضویت و نقشتان در مجتمع‌های فعلی دست نمی‌خورد؛ پس از پرداخت به مجتمعِ تازه می‌روید و از «مجتمع‌های من» برمی‌گردید.'
            : 'پس از پرداختِ موفق، مجتمعتان ساخته می‌شود و مدیرِ آن می‌شوید. اگر مالک یا مستأجرِ یک واحد هستید، اشتراک لازم نیست؛ کافی است مدیرِ ساختمان شما را اضافه کند.'}
        </p>
      </div>

      {/* دکمه با طولِ ورودی خاموش نمی‌شود؛ کلیک پیامِ روشن زیرِ هر فیلد می‌دهد */}
      <form
        // `handleSubmit` درونِ رویداد، نه هنگامِ رندر: `pay` به ref ِ فرمِ درگاه دست می‌زند
        onSubmit={(event) => void handleSubmit(pay)(event)}
        noValidate
        className="flex flex-col gap-5"
      >
        <section
          className="flex flex-col gap-4 rounded-2xl border p-4"
          style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-base)' }}
        >
          <div className="flex flex-col gap-1.5">
            <label
              htmlFor={nameId}
              className="text-sm font-semibold"
              style={{ color: 'var(--text-primary)' }}
            >
              نامِ مجتمع
            </label>
            <Controller
              control={control}
              name="complexName"
              render={({ field }) => (
                <input
                  {...field}
                  id={nameId}
                  maxLength={150}
                  onChange={(event) =>
                    field.onChange(filterPersianAlphanumeric(event.target.value).value)
                  }
                  aria-invalid={errors.complexName ? true : undefined}
                  placeholder="مثلاً مجتمع آفتاب"
                  className="field w-full rounded-xl px-3 py-2.5 text-sm"
                />
              )}
            />
            <FieldError message={errors.complexName?.message} />
          </div>

          <fieldset className="flex flex-col gap-2">
            <legend
              className="mb-1.5 text-sm font-semibold"
              style={{ color: 'var(--text-primary)' }}
            >
              نوعِ مجتمع
            </legend>
            <div className="flex flex-wrap gap-2">
              {TYPES.map((option) => (
                <label
                  key={option.value}
                  className="flex cursor-pointer items-center gap-2 rounded-xl border px-4 py-2 text-sm"
                  style={{
                    borderColor:
                      type === option.value ? 'var(--color-brand-500)' : 'var(--border-default)',
                    color: 'var(--text-primary)',
                  }}
                >
                  <input
                    type="radio"
                    value={option.value}
                    {...register('complexType', {
                      // پلنِ نوعِ دیگر برای این نوع معتبر نیست
                      onChange: () => setValue('plan', ''),
                    })}
                  />
                  {option.label}
                </label>
              ))}
            </div>
            <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
              تعرفه‌ی اشتراک به نوعِ مجتمع بستگی دارد و پس از ساخت فقط پشتیبانی آن را تغییر می‌دهد.
            </p>
            <FieldError message={errors.complexType?.message} />
          </fieldset>

          <div className="flex flex-col gap-1.5">
            <label
              htmlFor={addressId}
              className="text-sm font-semibold"
              style={{ color: 'var(--text-primary)' }}
            >
              نشانی (اختیاری)
            </label>
            <input
              id={addressId}
              maxLength={255}
              {...register('address')}
              className="field w-full rounded-xl px-3 py-2.5 text-sm"
            />
            <FieldError message={errors.address?.message} />
          </div>
        </section>

        <section aria-labelledby="registrant-plans" className="flex flex-col gap-3">
          <h2
            id="registrant-plans"
            className="flex items-center gap-2 text-sm font-bold"
            style={{ color: 'var(--text-primary)' }}
          >
            <Building2 size={16} style={{ color: 'var(--color-brand-500)' }} aria-hidden="true" />
            پکیج
          </h2>

          {!type ? (
            <p className="text-sm" style={{ color: 'var(--text-tertiary)' }}>
              اول نوعِ مجتمع را انتخاب کنید تا پکیج‌های همان نوع نمایش داده شوند.
            </p>
          ) : plans.length === 0 ? (
            <p className="text-sm" style={{ color: 'var(--text-tertiary)' }}>
              برای این نوع هنوز پکیجی برای خریدِ آنلاین تعریف نشده است.
            </p>
          ) : (
            <div role="radiogroup" aria-label="پکیج‌ها" className="grid gap-3 sm:grid-cols-2">
              {plans.map((option) => (
                <label
                  key={option.value}
                  className="flex cursor-pointer flex-col gap-2 rounded-2xl border p-4"
                  style={{
                    borderColor:
                      plan === option.value ? 'var(--color-brand-500)' : 'var(--border-default)',
                    backgroundColor: 'var(--surface-base)',
                  }}
                >
                  <span className="flex items-center gap-2">
                    <input type="radio" value={option.value} {...register('plan')} />
                    <span className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                      {option.label}
                    </span>
                  </span>
                  <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                    {option.priceLabel} تومان · {formatNumber(option.months)} ماه
                  </span>
                  {option.features.length > 0 && (
                    <ul
                      className="list-disc pr-5 text-xs leading-6"
                      style={{ color: 'var(--text-tertiary)' }}
                    >
                      {option.features.map((feature) => (
                        <li key={feature}>{feature}</li>
                      ))}
                    </ul>
                  )}
                </label>
              ))}
            </div>
          )}
          <FieldError message={errors.plan?.message} />
        </section>

        {!data.checkoutEnabled && (
          <p
            role="status"
            className="rounded-xl px-3.5 py-2.5 text-xs"
            style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
          >
            پرداختِ آنلاین هنوز فعال نشده است.
          </p>
        )}

        <FieldError message={gatewayError ?? undefined} />

        <button
          type="submit"
          disabled={submitting}
          aria-busy={submitting || undefined}
          className="inline-flex items-center justify-center gap-2 self-start rounded-xl px-5 py-2.5 text-sm font-bold text-white disabled:opacity-60"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {submitting ? (
            <Loader2 size={16} className="animate-spin" aria-hidden="true" />
          ) : (
            <CreditCard size={16} aria-hidden="true" />
          )}
          {submitting ? 'در حالِ انتقال به درگاه…' : 'پرداختِ آنلاین'}
        </button>
      </form>

      {/* فرمِ واقعیِ رفتن به درگاه — فقط slug و نام و نوع؛ مبلغ را سرور تعیین می‌کند */}
      <form
        ref={gatewayFormRef}
        method="POST"
        action={data.checkoutAction}
        className="hidden"
        aria-hidden="true"
      >
        <input type="hidden" name="_token" value={csrfToken()} />
        <input type="hidden" name="plan" defaultValue="" />
        <input type="hidden" name="complex_name" defaultValue="" />
        <input type="hidden" name="complex_type" defaultValue="" />
        <input type="hidden" name="complex_address" defaultValue="" />
        {/* مجتمعِ تازه، نه تمدیدِ مجتمعِ فعال — عضوِ مجتمع‌های دیگر هم از همین صفحه می‌خرد (۱۰/۵) */}
        <input type="hidden" name="purpose" value="new_complex" />
      </form>
    </div>
  )
}

function FieldError({ message }: { message?: string }) {
  if (!message) return null

  return (
    <p role="alert" className="text-xs" style={{ color: 'var(--color-danger)' }}>
      {message}
    </p>
  )
}

/** توکن CSRF از متاتگِ قالب؛ فرمِ معمولی بی آن ۴۱۹ می‌گیرد. */
function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}
