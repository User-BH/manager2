import { useId, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import {
  AlertTriangle,
  ArrowRight,
  Building2,
  Check,
  ChevronLeft,
  CreditCard,
  Infinity as InfinityIcon,
  Loader2,
  Store,
} from 'lucide-react'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { formatNumber } from '@/shared/lib/format'
import { filterPersianAlphanumeric } from '@/shared/lib/inputFilters'
import { EmptyState, ErrorState } from '@/shared/ui/PageState'
import { Pager } from '@/shared/ui/Pager'
import { CardListSkeleton } from '@/shared/ui/Skeleton'
import { newComplexSchema, renewalSchema, type CheckoutDetails } from './schema'
import type {
  AccountSettingsResponse,
  PaymentHistoryResponse,
  PlanCard,
  PlanComplexType,
  PlanDurationPrice,
} from './types'

/*
 * جادوگرِ خریدِ «تنظیماتِ حساب» — مرحلهٔ ۱۳ · فاز ۶ (ایرادات ۱۰، بندهای ۹ تا ۲۵).
 *
 *   نوعِ مجتمع ← پلن ← مدتِ اشتراک ← تأیید ← پرداخت
 *
 * هم حسابِ اولیه (ساختِ مجتمع) و هم مدیر (تمدید یا تغییر) از همین یک جادوگر می‌خرند. هر چه نشان داده می‌شود — کارت‌ها،
 * قیمتِ هر مدت، «تمدید یا تغییر» — از سرور است (`PlanCatalog`، `SubscriptionRenewal`)؛ رابط فقط می‌پرسد و توضیح
 * می‌دهد. مبلغ هرگز فرستاده نمی‌شود و تأییدِ «تغییرِ پلن» را هم سرور جدا می‌خواهد (`confirm_change`).
 */

type Step = 'type' | 'plan' | 'duration' | 'confirm'

/** «تاریخچه‌ی پرداخت‌ها» — همان صفحه‌ی تنظیماتِ حساب، محتوای دیگر (بندِ ۲۶)؛ مدیر و حسابِ اولیه */
export const PAYMENT_HISTORY_PATH = '/account/history'

const STEPS: { id: Step | 'pay'; label: string }[] = [
  { id: 'type', label: 'نوع مجتمع' },
  { id: 'plan', label: 'پلن' },
  { id: 'duration', label: 'مدت اشتراک' },
  { id: 'confirm', label: 'تأیید' },
  { id: 'pay', label: 'پرداخت' },
]

const TYPES: { value: PlanComplexType; label: string; hint: string; icon: typeof Building2 }[] = [
  { value: 'residential', label: 'مسکونی', hint: 'آپارتمان و مجتمعِ مسکونی', icon: Building2 },
  { value: 'commercial', label: 'تجاری', hint: 'پاساژ، مجتمعِ اداری و تجاری', icon: Store },
]

interface PlanWizardProps {
  data: AccountSettingsResponse
  /** «تمدیدِ پلنِ فعلی» (بندِ ۲۰): مستقیم به گامِ مدت، با پلنِ فعلی */
  renewing?: boolean
  onLeaveRenewal?: () => void
}

export function PlanWizard({ data, renewing = false, onLeaveRenewal }: PlanWizardProps) {
  const initial = data.account === 'initial'
  const fixedType = initial ? null : data.complexType
  const renewalSlug = renewing ? (data.renewal?.plan ?? null) : null

  const [step, setStep] = useState<Step>(renewalSlug ? 'duration' : fixedType ? 'plan' : 'type')
  const [type, setType] = useState<PlanComplexType | null>(fixedType)
  const [slug, setSlug] = useState<string | null>(renewalSlug)
  const [months, setMonths] = useState<number | null>(null)
  const [changeAccepted, setChangeAccepted] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const gatewayForm = useRef<HTMLFormElement>(null)

  const cards = type ? (data.catalog[type] ?? []) : []
  const card = cards.find((option) => option.slug === slug) ?? null
  const price = card?.prices.find((option) => option.months === months) ?? null
  // فقط برای توضیح؛ سرور خودش «تمدید یا تغییر» را تعیین می‌کند
  const hasCurrent = !initial && data.current !== null
  const isChange = hasCurrent && card !== null && card.slug !== data.currentPlan

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<CheckoutDetails>({
    resolver: zodResolver(initial ? newComplexSchema : renewalSchema),
    defaultValues: { complexName: '', address: '' },
  })

  function go(next: Step) {
    setStep(next)
    if (next !== 'confirm') setChangeAccepted(false)
    // گامِ تازه از بالای محتوا خوانده می‌شود، نه از وسطِ کارتِ قبلی
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function back() {
    if (step === 'confirm') go('duration')
    else if (step === 'duration') {
      if (renewalSlug) onLeaveRenewal?.()
      else go('plan')
    } else if (step === 'plan' && !fixedType) go('type')
  }

  function pay(details: CheckoutDetails) {
    const form = gatewayForm.current
    if (submitting || !form || !type || !card || !price) return
    if (isChange && !changeAccepted) return
    // سرور گفته درگاه خاموش است (`checkoutEnabled`)؛ خطای ردِ خودِ سرور با بازگشت به همین صفحه نشان داده می‌شود
    if (!data.checkoutEnabled) {
      setError('root', {
        message: `پرداختِ آنلاینِ اشتراک فعلاً در دسترس نیست. با پشتیبانی ${data.supportPhone} تماس بگیرید.`,
      })
      return
    }

    setSubmitting(true)
    const set = (field: string, value: string) => {
      ;(form.elements.namedItem(field) as HTMLInputElement).value = value
    }
    set('plan', card.slug)
    set('months', String(price.months))
    set('confirm_change', isChange ? '1' : '0')
    if (initial) {
      set('purpose', 'new_complex')
      set('complex_type', type)
      set('complex_name', details.complexName)
      set('complex_address', details.address)
    }
    // فرمِ واقعی، نه fetch: مرورگر باید صفحه را ترک کند و به درگاه برود
    form.submit()
  }

  return (
    <section className="flex flex-col gap-5" aria-label="خریدِ پلن">
      <form ref={gatewayForm} method="POST" action={data.checkoutAction} className="hidden">
        <input type="hidden" name="_token" value={csrfToken()} />
        {[
          'plan',
          'months',
          'confirm_change',
          'purpose',
          'complex_type',
          'complex_name',
          'complex_address',
        ].map((field) => (
          <input key={field} type="hidden" name={field} defaultValue="" />
        ))}
      </form>

      <StepBar current={step} skipType={fixedType !== null} />

      {step !== 'type' && !(step === 'plan' && fixedType) && (
        <button
          type="button"
          onClick={back}
          className="flex w-fit items-center gap-1.5 rounded-xl px-3 py-2 text-body font-semibold transition hover:opacity-80"
          style={{ color: 'var(--text-secondary)', backgroundColor: 'var(--surface-sunken)' }}
        >
          <ArrowRight size={15} />
          {backLabel(step, renewalSlug !== null)}
        </button>
      )}

      {step === 'type' && (
        <TypeStep
          onPick={(value) => {
            setType(value)
            setSlug(null)
            setMonths(null)
            go('plan')
          }}
        />
      )}

      {step === 'plan' && (
        <PlanStep
          cards={cards}
          base={data.baseFeatures}
          hasCurrent={hasCurrent}
          typeLabel={TYPES.find((option) => option.value === type)?.label ?? ''}
          selected={slug}
          onPick={(value) => {
            setSlug(value)
            setMonths(null)
            go('duration')
          }}
        />
      )}

      {step === 'duration' && card && (
        <DurationStep
          card={card}
          isChange={isChange}
          renewal={hasCurrent && !isChange}
          selected={months}
          onPick={(value) => {
            setMonths(value)
            go('confirm')
          }}
        />
      )}

      {step === 'confirm' && card && price && (
        <form
          onSubmit={(event) => void handleSubmit(pay)(event)}
          noValidate
          className="flex flex-col gap-4"
        >
          <ConfirmSummary
            data={data}
            typeLabel={TYPES.find((option) => option.value === type)?.label ?? ''}
            card={card}
            price={price}
            isChange={isChange}
          />

          {initial && (
            <div className="grid gap-3 sm:grid-cols-2">
              <TextField
                label="نامِ مجتمع"
                required
                error={errors.complexName?.message}
                maxLength={150}
                placeholder="مثلاً مجتمع آفتاب"
                {...register('complexName', {
                  onChange: (event: React.ChangeEvent<HTMLInputElement>) => {
                    event.target.value = filterPersianAlphanumeric(event.target.value).value
                  },
                })}
              />
              <TextField
                label="نشانی (اختیاری)"
                maxLength={255}
                error={errors.address?.message}
                {...register('address')}
              />
            </div>
          )}

          {isChange && (
            <label
              className="flex cursor-pointer items-start gap-2.5 rounded-2xl border p-4 text-body leading-6"
              style={{
                borderColor: 'color-mix(in srgb, var(--state-warning) 45%, transparent)',
                backgroundColor: 'color-mix(in srgb, var(--state-warning) 8%, transparent)',
                color: 'var(--text-primary)',
              }}
            >
              <input
                type="checkbox"
                className="mt-1 size-4 shrink-0"
                checked={changeAccepted}
                onChange={(event) => setChangeAccepted(event.target.checked)}
              />
              متوجه شدم: با خریدِ این پلن، پلنِ فعلیِ من خاتمه پیدا می‌کند و مدتِ باقی‌مانده‌اش به
              پلنِ تازه منتقل نمی‌شود.
            </label>
          )}

          {!data.checkoutEnabled && !errors.root && (
            <p className="text-body" role="status" style={{ color: 'var(--state-info)' }}>
              پرداختِ آنلاینِ اشتراک فعلاً در دسترس نیست. در صورتِ نیاز با پشتیبانی{' '}
              {data.supportPhone} تماس بگیرید.
            </p>
          )}
          {errors.root?.message && (
            <p className="text-body" role="alert" style={{ color: 'var(--color-danger)' }}>
              {errors.root.message}
            </p>
          )}

          <div className="flex flex-wrap gap-2.5">
            <button
              type="submit"
              disabled={submitting || (isChange && !changeAccepted)}
              className="flex items-center gap-2 rounded-xl px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50"
              style={{ backgroundColor: 'var(--color-brand-600)' }}
            >
              {submitting ? (
                <Loader2 size={16} className="animate-spin" />
              ) : (
                <CreditCard size={16} />
              )}
              تأیید و پرداخت
            </button>
            <button
              type="button"
              onClick={back}
              className="rounded-xl border px-5 py-3 text-sm font-semibold"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
            >
              بازگشت
            </button>
          </div>
        </form>
      )}
    </section>
  )
}

/* ─── نوارِ راهنما (بندِ ۱۹) ────────────────────────────────────────────── */

function StepBar({ current, skipType }: { current: Step; skipType: boolean }) {
  const index = STEPS.findIndex((item) => item.id === current)

  return (
    <nav aria-label="مراحلِ خرید">
      <ol className="flex flex-wrap items-center gap-1.5 text-tiny sm:text-small">
        {STEPS.map((item, i) => {
          const done = i < index || (skipType && item.id === 'type')
          const active = i === index
          return (
            <li key={item.id} className="flex items-center gap-1.5">
              <span
                aria-current={active ? 'step' : undefined}
                className={`flex items-center gap-1 rounded-full px-2.5 py-1 font-semibold transition ${active ? 'text-white' : ''}`}
                style={{
                  backgroundColor: active
                    ? 'var(--color-brand-600)'
                    : done
                      ? 'color-mix(in srgb, var(--color-brand-500) 14%, transparent)'
                      : 'var(--surface-sunken)',
                  color: active
                    ? undefined
                    : done
                      ? 'var(--color-brand-700)'
                      : 'var(--text-tertiary)',
                }}
              >
                {done && <Check size={12} />}
                {item.label}
              </span>
              {i < STEPS.length - 1 && (
                <ChevronLeft size={13} style={{ color: 'var(--text-tertiary)' }} />
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

function backLabel(step: Step, renewing: boolean): string {
  if (step === 'confirm') return 'بازگشت به انتخابِ مدت'
  if (step === 'duration') return renewing ? 'بازگشت' : 'بازگشت به انتخابِ پلن'
  return 'بازگشت به انتخابِ مسکونی یا تجاری'
}

/* ─── گامِ ۱: نوعِ مجتمع (بندهای ۹ و ۱۰) ───────────────────────────────── */

function TypeStep({ onPick }: { onPick: (type: PlanComplexType) => void }) {
  return (
    <div className="flex flex-col gap-4">
      <StepIntro title="نوع مجتمع خود را انتخاب کنید.">
        اگر مجتمع شما مسکونی است، «مسکونی» و اگر تجاری است، «تجاری» را انتخاب کنید.
      </StepIntro>
      <RenewalVsChange />
      <div className="grid gap-4 sm:grid-cols-2">
        {TYPES.map(({ value, label, hint, icon: Icon }) => (
          <button
            key={value}
            type="button"
            onClick={() => onPick(value)}
            className="group flex flex-col items-center gap-3 rounded-3xl border p-8 text-center shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-2"
            style={{
              borderColor: 'var(--border-default)',
              backgroundColor: 'var(--surface-raised)',
            }}
          >
            <span
              className="flex size-16 items-center justify-center rounded-2xl transition group-hover:scale-105"
              style={{
                backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)',
              }}
            >
              <Icon size={30} style={{ color: 'var(--color-brand-600)' }} />
            </span>
            <span className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
              {label}
            </span>
            <span className="text-body" style={{ color: 'var(--text-tertiary)' }}>
              {hint}
            </span>
          </button>
        ))}
      </div>
    </div>
  )
}

/** تفاوتِ «تمدیدِ همان پلن» و «تغییرِ پلن» — همان قاعده‌ی سرور (`SubscriptionRenewal`)، به زبانِ ساده */
function RenewalVsChange() {
  return (
    <div
      className="grid gap-3 rounded-2xl border p-4 text-body leading-6 sm:grid-cols-2"
      style={{
        borderColor: 'var(--border-subtle)',
        backgroundColor: 'var(--surface-sunken)',
        color: 'var(--text-secondary)',
      }}
    >
      <p>
        <strong style={{ color: 'var(--text-primary)' }}>تمدیدِ همان پلن: </strong>
        مدتِ تازه به مدتِ باقی‌مانده‌ی پلنِ فعلی اضافه می‌شود و پلنِ فعلی باطل نمی‌شود.
      </p>
      <p>
        <strong style={{ color: 'var(--text-primary)' }}>تغییرِ پلن: </strong>
        پلنِ قبلی خاتمه پیدا می‌کند و پلنِ تازه جایش را می‌گیرد؛ مدتِ باقی‌مانده‌ی پلنِ قبلی منتقل
        نمی‌شود.
      </p>
    </div>
  )
}

/* ─── گامِ ۲: پلن (بندهای ۱۱ تا ۱۵) ─────────────────────────────────────── */

interface PlanStepProps {
  cards: PlanCard[]
  base: string[]
  hasCurrent: boolean
  typeLabel: string
  selected: string | null
  onPick: (slug: string) => void
}

function PlanStep({ cards, base, hasCurrent, typeLabel, selected, onPick }: PlanStepProps) {
  if (cards.length === 0) {
    return (
      <EmptyState
        message="فعلاً پلنی برای خرید نیست"
        hint={`برای مجتمعِ ${typeLabel} هنوز پلنِ قیمت‌داری تعریف نشده است.`}
      />
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <StepIntro title={`پلنِ مجتمعِ ${typeLabel}`}>
        هر پلن امکانات متفاوتی دارد. قبل از انتخاب، امکاناتِ هر کارت را بررسی کنید.
        {hasCurrent &&
          ' اگر پلنی متفاوت از پلنِ فعلیِ خود انتخاب کنید، پلنِ قبلیِ شما خاتمه پیدا می‌کند.'}
      </StepIntro>
      {!hasCurrent && <RenewalVsChange />}

      <p className="text-small leading-6" style={{ color: 'var(--text-tertiary)' }}>
        در همه‌ی پلن‌ها: {base.join('، ')}
      </p>

      <div className="grid gap-4 lg:grid-cols-3">
        {cards.map((plan) => {
          const cheapest = plan.prices[0]
          const active = selected === plan.slug
          return (
            <button
              key={plan.slug}
              type="button"
              aria-pressed={active}
              onClick={() => onPick(plan.slug)}
              className="relative flex flex-col gap-4 rounded-3xl border-2 p-6 text-start shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-xl"
              style={{
                borderColor:
                  active || plan.tier === 'advanced'
                    ? 'var(--color-brand-500)'
                    : 'var(--border-default)',
                backgroundColor: 'var(--surface-raised)',
              }}
            >
              {plan.isCurrent && (
                <span
                  className="absolute -top-3 start-5 rounded-full px-3 py-0.5 text-tiny font-bold text-white"
                  style={{ backgroundColor: 'var(--state-success)' }}
                >
                  پلنِ فعلیِ شما
                </span>
              )}
              <div>
                <p className="text-small font-bold" style={{ color: 'var(--color-brand-600)' }}>
                  {plan.tierLabel}
                </p>
                <h3
                  className="mt-1 text-xl font-extrabold"
                  style={{ color: 'var(--text-primary)' }}
                >
                  {plan.name}
                </h3>
                {plan.description && (
                  <p
                    className="mt-1.5 text-body leading-6"
                    style={{ color: 'var(--text-secondary)' }}
                  >
                    {plan.description}
                  </p>
                )}
              </div>

              <ul className="flex flex-col gap-2 text-body">
                {[...plan.capabilities, ...plan.features].map((line) => (
                  <li
                    key={line}
                    className="flex items-start gap-2"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    <Check
                      size={15}
                      className="mt-0.5 shrink-0"
                      style={{ color: 'var(--state-success)' }}
                    />
                    {line}
                  </li>
                ))}
              </ul>

              {plan.includesEverything && (
                <p
                  className="flex items-center gap-2 rounded-xl px-3 py-2 text-body font-bold"
                  style={{
                    backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)',
                    color: 'var(--color-brand-700)',
                  }}
                >
                  <InfinityIcon size={16} />
                  تمامِ امکاناتِ سایت، بدون محدودیت
                </p>
              )}

              <p className="mt-auto text-body" style={{ color: 'var(--text-tertiary)' }}>
                از{' '}
                <strong className="text-lg" style={{ color: 'var(--text-primary)' }}>
                  {cheapest.priceLabel}
                </strong>{' '}
                تومان برای {formatNumber(cheapest.months)} ماه
              </p>
            </button>
          )
        })}
      </div>
    </div>
  )
}

/* ─── گامِ ۳: مدت (بندهای ۱۶ و ۱۷) ──────────────────────────────────────── */

interface DurationStepProps {
  card: PlanCard
  isChange: boolean
  renewal: boolean
  selected: number | null
  onPick: (months: number) => void
}

function DurationStep({ card, isChange, renewal, selected, onPick }: DurationStepProps) {
  return (
    <div className="flex flex-col gap-4">
      <StepIntro title={`مدتِ اشتراکِ پلنِ «${card.name}»`}>
        قیمتِ هر کارت، مبلغِ کلِ همان مدت است.{' '}
        {renewal &&
          'این تمدیدِ همان پلنِ فعلی است: مدتِ تازه به مدتِ باقی‌مانده اضافه می‌شود و پلنِ فعلی باطل نمی‌شود.'}
        {isChange &&
          'این تغییرِ پلن است: با پرداخت، پلنِ فعلیِ شما خاتمه پیدا می‌کند و مدتِ باقی‌مانده‌اش منتقل نمی‌شود.'}
        {!renewal && !isChange && 'اشتراک از لحظه‌ی پرداختِ موفق شروع می‌شود.'}
      </StepIntro>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {card.prices.map((option) => (
          <button
            key={option.months}
            type="button"
            aria-pressed={selected === option.months}
            onClick={() => onPick(option.months)}
            className="flex flex-col items-center gap-1.5 rounded-2xl border-2 p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg"
            style={{
              borderColor:
                selected === option.months ? 'var(--color-brand-500)' : 'var(--border-default)',
              backgroundColor: 'var(--surface-raised)',
            }}
          >
            <span className="text-body font-bold" style={{ color: 'var(--text-secondary)' }}>
              {formatNumber(option.months)} ماهه
            </span>
            <span className="text-2xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
              {option.priceLabel}
            </span>
            <span className="text-small" style={{ color: 'var(--text-tertiary)' }}>
              تومان
            </span>
          </button>
        ))}
      </div>
    </div>
  )
}

/* ─── گامِ ۴: تأییدِ نهایی (بندهای ۲۲ تا ۲۴) ───────────────────────────── */

interface ConfirmSummaryProps {
  data: AccountSettingsResponse
  typeLabel: string
  card: PlanCard
  price: PlanDurationPrice
  isChange: boolean
}

function ConfirmSummary({ data, typeLabel, card, price, isChange }: ConfirmSummaryProps) {
  const current = data.account === 'manager' ? data.current : null
  const effect = isChange
    ? 'با خریدِ این پلن، پلنِ فعلیِ شما خاتمه پیدا می‌کند و مدتِ باقی‌مانده‌اش منتقل نمی‌شود.'
    : current
      ? `${formatNumber(price.months)} ماه به پایانِ اشتراکِ فعلی (${current.paidUntil}) اضافه می‌شود؛ پلنِ فعلی باطل نمی‌شود.`
      : data.account === 'initial'
        ? 'پس از پرداختِ موفق، مجتمعتان ساخته می‌شود و مدیرِ آن می‌شوید.'
        : 'اشتراک از لحظه‌ی پرداختِ موفق شروع می‌شود.'

  const rows: [string, string][] = [
    ['نوعِ مجتمع', typeLabel],
    ['پلن', `${card.tierLabel} — ${card.name}`],
    ['مدت', `${formatNumber(price.months)} ماه`],
    ['مبلغ', `${price.priceLabel} تومان`],
    [
      'وضعیتِ پلنِ فعلی',
      current
        ? `${current.tierLabel ?? current.planLabel} — ${formatNumber(current.daysLeftTotal)} روز باقی‌مانده`
        : 'پلنِ فعالی ندارید',
    ],
  ]

  return (
    <div className="flex flex-col gap-4">
      <StepIntro title={isChange ? 'تأییدِ تغییرِ پلن' : 'تأییدِ نهایی'}>
        اطلاعاتِ خرید را بررسی کنید. تا «تأیید و پرداخت» را نزنید، هیچ پلنی تغییر نمی‌کند و هیچ
        مبلغی ثبت نمی‌شود.
      </StepIntro>
      <dl
        className="divide-y rounded-2xl border shadow-sm"
        style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-raised)' }}
      >
        {rows.map(([label, value]) => (
          <div
            key={label}
            className="flex flex-wrap items-center justify-between gap-2 px-5 py-3"
            style={{ borderColor: 'var(--border-subtle)' }}
          >
            <dt className="text-body" style={{ color: 'var(--text-tertiary)' }}>
              {label}
            </dt>
            <dd className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
              {value}
            </dd>
          </div>
        ))}
      </dl>
      <p
        className="flex items-start gap-2 rounded-2xl p-4 text-body font-semibold leading-6"
        role={isChange ? 'alert' : undefined}
        style={{
          backgroundColor: isChange
            ? 'color-mix(in srgb, var(--state-warning) 12%, transparent)'
            : 'color-mix(in srgb, var(--state-info) 8%, transparent)',
          color: 'var(--text-primary)',
        }}
      >
        {isChange && (
          <AlertTriangle
            size={17}
            className="mt-0.5 shrink-0"
            style={{ color: 'var(--state-warning)' }}
          />
        )}
        {effect}
      </p>
    </div>
  )
}

/* ─── مشترک ─────────────────────────────────────────────────────────────── */

function StepIntro({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <h2 className="text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
        {title}
      </h2>
      <p className="mt-1 text-body leading-7" style={{ color: 'var(--text-secondary)' }}>
        {children}
      </p>
    </div>
  )
}

interface TextFieldProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label: string
  error?: string
}

function TextField({ label, error, required, ...input }: TextFieldProps) {
  const id = useId()
  return (
    <div className="flex flex-col gap-1.5">
      <label
        htmlFor={id}
        className="text-body font-semibold"
        style={{ color: 'var(--text-secondary)' }}
      >
        {label}
        {required && <span style={{ color: 'var(--color-danger)' }}> *</span>}
      </label>
      <input
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        className="field w-full rounded-xl px-3 py-2.5 text-sm"
        {...input}
      />
      {error && (
        <p id={`${id}-error`} className="text-small" style={{ color: 'var(--color-danger)' }}>
          {error}
        </p>
      )}
    </div>
  )
}

/** توکنِ CSRF از همان متاتگِ قالب؛ فرمِ معمولی بدونِ آن ۴۱۹ می‌گیرد */
function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

/* ─── تاریخچه‌ی پرداخت‌ها (بندهای ۲۶ تا ۲۸) ─────────────────────────────── */

const STATUS_COLOR: Record<string, string> = {
  active: 'var(--state-success)',
  completed: 'var(--state-success)',
  pending: 'var(--state-info)',
  paid: 'var(--state-info)',
  failed: 'var(--color-danger)',
}

/**
 * «تاریخچه‌ی پرداخت‌ها»: مدیر — همه‌ی خریدهای همین مجتمع؛ حسابِ اولیه — خریدهای خودش. صفحه‌بندیِ سرور (۱۵ در هر صفحه).
 * در همین ماژول تا چانکِ ریزِ جدا نسازد (`bundleBudget.test.ts`).
 */
export function PaymentHistory() {
  const [page, setPage] = useState(1)
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.subscription.history(page),
    queryFn: ({ signal }) =>
      api<PaymentHistoryResponse>(`/subscription/history?page=${page}`, { signal }),
  })

  if (isLoading) return <CardListSkeleton items={4} />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data) return null
  if (data.data.length === 0) {
    return (
      <EmptyState
        message="هنوز خریدی ثبت نشده"
        hint="پس از اولین خرید، سابقه‌اش همین‌جا دیده می‌شود."
      />
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <ul className="flex flex-col gap-3">
        {data.data.map((row) => (
          <li
            key={row.id}
            className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-4 shadow-sm"
            style={{
              borderColor: 'var(--border-default)',
              backgroundColor: 'var(--surface-raised)',
            }}
          >
            <div className="flex flex-col gap-1">
              <p className="text-sm font-extrabold" style={{ color: 'var(--text-primary)' }}>
                {row.tierLabel ?? row.planLabel} — {formatNumber(row.months)} ماهه
              </p>
              <p className="text-small" style={{ color: 'var(--text-tertiary)' }}>
                {row.complexTypeLabel}
                {row.complexName ? ` · ${row.complexName}` : ''} · {row.date} ساعت {row.time}
              </p>
            </div>
            <div className="flex items-center gap-3">
              <span className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                {row.amountLabel} تومان
              </span>
              <span
                className="rounded-full px-2.5 py-1 text-tiny font-bold"
                style={{
                  color: STATUS_COLOR[row.status] ?? 'var(--text-tertiary)',
                  backgroundColor: `color-mix(in srgb, ${STATUS_COLOR[row.status] ?? 'var(--text-tertiary)'} 12%, transparent)`,
                }}
              >
                {row.statusLabel}
              </span>
            </div>
          </li>
        ))}
      </ul>
      <Pager meta={data.meta} onChange={setPage} noun="پرداخت" />
    </div>
  )
}
