import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  BadgeCheck,
  Building2,
  CalendarClock,
  Check,
  CreditCard,
  Crown,
  Headphones,
  Hourglass,
  Loader2,
  Receipt,
  Sparkles,
  UserRound,
} from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { Modal } from '@/components/ui/Modal'
import { ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { api } from '@/lib/api'
import { alertError, alertInfo, alertSuccess, confirmAction, toastSuccess } from '@/lib/alert'
import { formatMoney, formatNumber } from '@/lib/format'
import { ReceiptUploadForm } from './ReceiptUploadForm'
import type { SubscriptionPlanOption, SubscriptionResponse, SubscriptionRow } from './types'

const STATUS_COLOR: Record<string, string> = {
  active: 'var(--state-success)',
  pending: 'var(--state-info)',
  failed: 'var(--color-danger)',
  canceled: 'var(--text-tertiary)',
  expired: 'var(--text-tertiary)',
}

/**
 * «تنظیمات حساب» — وضعیت اشتراک مجتمع، مصرف در برابر سقف پلن، خرید و سابقه.
 *
 * اشتراک به مجتمع تعلق دارد نه به کاربر، پس هر مدیرِ همان مجتمع همین صفحه
 * را با همان وضعیت می‌بیند.
 */
export function AccountPage() {
  const [params, setParams] = useSearchParams()
  const [busyPlan, setBusyPlan] = useState<string | null>(null)
  const [receiptOpen, setReceiptOpen] = useState(false)
  const formRef = useRef<HTMLFormElement>(null)

  useDocumentTitle('تنظیمات حساب')

  const { data, error, isLoading, reload } = useApi<SubscriptionResponse>('/subscription')

  /*
   * بازگشت از درگاه با پارامتر در آدرس اعلام می‌شود (نه با state)، چون
   * مرورگر واقعاً از دامنه‌ی بانک برگشته و هیچ state ری‌اکتی زنده نمانده.
   */
  useEffect(() => {
    const checkout = params.get('checkout')
    if (!checkout) return

    if (checkout === 'success') {
      const tracking = params.get('tracking')
      void alertSuccess('اشتراک شما فعال شد.', tracking ? `کد رهگیری: ${tracking}` : undefined)
      reload()
    } else if (checkout === 'failed') {
      void alertInfo('پرداخت انجام نشد.', 'مبلغی از حساب شما کسر نشده است. می‌توانید دوباره تلاش کنید.')
    } else if (checkout === 'error') {
      void alertInfo('اتصال به درگاه ممکن نشد.', params.get('message') ?? undefined)
    }

    const next = new URLSearchParams(params)
    next.delete('checkout')
    next.delete('tracking')
    next.delete('message')
    setParams(next, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params])

  if (isLoading) return <LoadingState rows={5} />
  if (error) return <ErrorState message={error} onRetry={reload} />
  if (!data) return null

  const pendingRequest = data.history.find((row) => row.status === 'pending') ?? null

  async function startCheckout(plan: SubscriptionPlanOption) {
    if (!data) return

    const ok = await confirmAction({
      title: `خرید ${plan.label}`,
      text: `مبلغ ${formatMoney(plan.price)} تومان برای ${formatNumber(plan.months)} ماه. به درگاه پرداخت منتقل می‌شوید.`,
      confirmLabel: 'انتقال به درگاه',
    })
    if (!ok) return

    setBusyPlan(plan.value)

    /*
     * فرم واقعی submit می‌شود، نه fetch: مرورگر باید صفحه را ترک کند و به
     * سایت بانک برود.
     */
    const form = formRef.current
    if (!form) return

    ;(form.elements.namedItem('plan') as HTMLInputElement).value = plan.value
    form.submit()
  }

  async function cancelSubscription(id: number) {
    const ok = await confirmAction({
      title: 'اشتراک لغو شود؟',
      text: 'تا پایان دوره‌ی پرداخت‌شده امکانات پرو فعال می‌ماند.',
      confirmLabel: 'لغو اشتراک',
      danger: true,
    })
    if (!ok) return

    try {
      await api(`/subscription/${id}/cancel`, { method: 'POST' })
      toastSuccess('اشتراک لغو شد.')
      reload()
    } catch (err) {
      alertError(err, 'لغو اشتراک ممکن نشد.')
    }
  }

  return (
    <div className="flex flex-col gap-5">
      {/* فرم پنهانِ شروع پرداخت — تنها راهی که مرورگر واقعاً به بانک می‌رود */}
      <form ref={formRef} method="POST" action={data.checkoutAction} className="hidden">
        <input type="hidden" name="_token" value={csrfToken()} />
        <input type="hidden" name="plan" defaultValue="" />
      </form>

      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            <CreditCard size={19} style={{ color: 'var(--color-brand-500)' }} />
            تنظیمات حساب
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {data.complexName ? `${data.complexName} — ` : ''}
            پلن فعلی: {data.currentPlanLabel}
          </p>
        </div>

        <Link
          to="/profile"
          className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-[13px] font-semibold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
        >
          <UserRound size={15} />
          پروفایل من
        </Link>
      </header>

      {/* درخواست در انتظار بررسی */}
      {pendingRequest && <PendingBanner request={pendingRequest} />}

      <CurrentPlanBanner current={data.current} onCancel={cancelSubscription} />

      {data.usage && <UsageCard usage={data.usage} planLabel={data.currentPlanLabel} />}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <FreePlanCard features={data.freeFeatures} isCurrent={data.currentPlan === 'free'} />

        {data.plans.map((plan, index) => (
          <PlanCard
            key={plan.value}
            plan={plan}
            delay={0.05 * (index + 1)}
            busy={busyPlan === plan.value}
            disabled={busyPlan !== null || pendingRequest !== null}
            checkoutEnabled={data.checkoutEnabled}
            onBuyOnline={() => void startCheckout(plan)}
            onBuyReceipt={() => setReceiptOpen(true)}
          />
        ))}
      </div>

      {!data.checkoutEnabled && (
        <div
          className="flex flex-wrap items-center gap-2.5 rounded-2xl border p-4 text-[12.5px]"
          style={{
            borderColor: 'var(--border-subtle)',
            backgroundColor: 'color-mix(in srgb, var(--state-info) 8%, transparent)',
            color: 'var(--text-secondary)',
          }}
        >
          <Headphones size={16} style={{ color: 'var(--state-info)' }} />
          درگاه پرداخت آنلاین روی این نصب فعال نیست؛ خرید از راه{' '}
          <span className="font-bold" style={{ color: 'var(--text-primary)' }}>
            واریز و آپلود رسید
          </span>{' '}
          انجام می‌شود. در صورت نیاز با پشتیبانی{' '}
          <span className="font-bold" style={{ color: 'var(--text-primary)' }}>
            {data.supportPhone}
          </span>{' '}
          تماس بگیرید.
        </div>
      )}

      <HistoryCard history={data.history} />

      <Modal open={receiptOpen} title="خرید اشتراک با واریز" onClose={() => setReceiptOpen(false)}>
        <ReceiptUploadForm
          plans={data.plans}
          bank={data.bankInfo}
          onDone={() => {
            setReceiptOpen(false)
            reload()
          }}
        />
      </Modal>
    </div>
  )
}

/** توکن CSRF از همان متاتگ قالب؛ فرم معمولی بدون آن ۴۱۹ می‌گیرد. */
function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

function PendingBanner({ request }: { request: SubscriptionRow }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      className="flex flex-wrap items-center gap-3 rounded-2xl border p-4"
      style={{
        borderColor: 'var(--state-info)',
        backgroundColor: 'color-mix(in srgb, var(--state-info) 8%, transparent)',
      }}
    >
      <Hourglass size={18} style={{ color: 'var(--state-info)' }} />
      <div className="min-w-0 flex-1">
        <p className="text-[13.5px] font-bold" style={{ color: 'var(--text-primary)' }}>
          درخواست «{request.planLabel}» در انتظار بررسی است
        </p>
        <p className="mt-0.5 text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
          {request.methodLabel} · ثبت‌شده در {request.createdAt} · مبلغ {request.amountLabel} تومان
        </p>
      </div>
    </motion.div>
  )
}

/** مصرف فعلی در برابر سقف پلن — تا کاربر بداند چرا محدود شده. */
function UsageCard({
  usage,
  planLabel,
}: {
  usage: { units: number; unitLimit: number | null }
  planLabel: string
}) {
  const unlimited = usage.unitLimit === null
  const percent = unlimited ? 0 : Math.min(100, (usage.units / (usage.unitLimit || 1)) * 100)
  const nearLimit = !unlimited && percent >= 80

  return (
    <Card>
      <div className="flex flex-wrap items-center gap-3">
        <span
          className="flex h-10 w-10 items-center justify-center rounded-xl"
          style={{
            backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)',
            color: 'var(--color-brand-600)',
          }}
        >
          <Building2 size={18} />
        </span>

        <div className="min-w-0 flex-1">
          <p className="text-[13.5px] font-bold" style={{ color: 'var(--text-primary)' }}>
            واحدهای ثبت‌شده
          </p>
          <p className="mt-0.5 text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
            {unlimited
              ? `${formatNumber(usage.units)} واحد — بدون محدودیت در ${planLabel}`
              : `${formatNumber(usage.units)} از ${formatNumber(usage.unitLimit ?? 0)} واحد مجاز در ${planLabel}`}
          </p>
        </div>

        {!unlimited && (
          <span
            className="text-[15px] font-extrabold tabular-nums"
            style={{ color: nearLimit ? 'var(--color-danger)' : 'var(--color-brand-600)' }}
          >
            {formatNumber(Math.round(percent))}٪
          </span>
        )}
      </div>

      {!unlimited && (
        <div className="mt-3 h-2 overflow-hidden rounded-full" style={{ backgroundColor: 'var(--surface-sunken)' }}>
          <motion.div
            initial={{ width: 0 }}
            animate={{ width: `${percent}%` }}
            transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
            className="h-full rounded-full"
            style={{ backgroundColor: nearLimit ? 'var(--color-danger)' : 'var(--color-brand-500)' }}
          />
        </div>
      )}

      {nearLimit && (
        <p className="mt-2.5 text-[12px]" style={{ color: 'var(--color-danger)' }}>
          به سقف پلن رایگان نزدیک شده‌اید. برای ثبت واحد بیشتر، اشتراک پرو را فعال کنید.
        </p>
      )}
    </Card>
  )
}

function CurrentPlanBanner({
  current,
  onCancel,
}: {
  current: SubscriptionRow | null
  onCancel: (id: number) => void
}) {
  if (!current) {
    return (
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
        className="flex flex-wrap items-center gap-3 rounded-2xl border p-5"
        style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
      >
        <Sparkles size={20} style={{ color: 'var(--color-accent-500)' }} />
        <div className="min-w-0 flex-1">
          <p className="text-[14px] font-bold" style={{ color: 'var(--text-primary)' }}>
            این مجتمع روی پلن رایگان است.
          </p>
          <p className="mt-0.5 text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
            با ارتقا به پرو، سقف تعداد واحد برداشته می‌شود و اتصال درگاه بانکی و خروجی Excel باز می‌شود.
          </p>
        </div>
      </motion.div>
    )
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.3 }}
      className="overflow-hidden rounded-2xl border"
      style={{ borderColor: 'var(--color-brand-400)' }}
    >
      <div
        className="flex flex-wrap items-center gap-4 p-5"
        style={{
          background:
            'linear-gradient(120deg, color-mix(in srgb, var(--color-brand-500) 12%, transparent), transparent)',
        }}
      >
        <span
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <Crown size={20} />
        </span>

        <div className="min-w-0 flex-1">
          <p className="flex items-center gap-2 text-[15px] font-extrabold" style={{ color: 'var(--text-primary)' }}>
            {current.planLabel}
            <BadgeCheck size={16} style={{ color: 'var(--state-success)' }} />
          </p>
          <p className="mt-0.5 flex flex-wrap items-center gap-x-3 text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
            <span className="flex items-center gap-1">
              <CalendarClock size={12} />
              تا {current.endsAt}
            </span>
            <span>{formatNumber(current.daysLeft)} روز باقی‌مانده</span>
            <span>{current.methodLabel}</span>
            {current.trackingCode && <span dir="ltr">کد رهگیری: {current.trackingCode}</span>}
          </p>
        </div>

        <button
          onClick={() => onCancel(current.id)}
          className="rounded-xl border px-4 py-2 text-[12.5px] font-semibold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--color-danger)' }}
        >
          لغو اشتراک
        </button>
      </div>
    </motion.div>
  )
}

function FreePlanCard({ features, isCurrent }: { features: string[]; isCurrent: boolean }) {
  return (
    <Card className="flex flex-col">
      <div className="flex items-center justify-between gap-2">
        <h2 className="text-[15px] font-extrabold" style={{ color: 'var(--text-primary)' }}>
          رایگان
        </h2>
        {isCurrent && (
          <span
            className="rounded-full px-2 py-0.5 text-[10.5px] font-bold"
            style={{
              backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 13%, transparent)',
              color: 'var(--color-brand-600)',
            }}
          >
            پلن فعلی
          </span>
        )}
      </div>

      <p className="mt-2 text-[24px] font-extrabold" style={{ color: 'var(--text-primary)' }}>
        ۰
        <span className="mr-1 text-[12px] font-medium" style={{ color: 'var(--text-tertiary)' }}>
          تومان
        </span>
      </p>

      <FeatureList features={features} muted />
    </Card>
  )
}

function PlanCard({
  plan,
  delay,
  busy,
  disabled,
  checkoutEnabled,
  onBuyOnline,
  onBuyReceipt,
}: {
  plan: SubscriptionPlanOption
  delay: number
  busy: boolean
  disabled: boolean
  checkoutEnabled: boolean
  onBuyOnline: () => void
  onBuyReceipt: () => void
}) {
  const highlighted = plan.savingPercent > 0

  return (
    <Card delay={delay} className="relative flex flex-col">
      {highlighted && (
        <span
          className="absolute -top-2.5 right-5 rounded-full px-2.5 py-0.5 text-[10.5px] font-extrabold text-white"
          style={{ backgroundColor: 'var(--color-accent-500)' }}
        >
          {formatNumber(plan.savingPercent)}٪ صرفه‌جویی
        </span>
      )}

      <div className="flex items-center gap-2">
        <Crown size={16} style={{ color: 'var(--color-brand-500)' }} />
        <h2 className="text-[15px] font-extrabold" style={{ color: 'var(--text-primary)' }}>
          {plan.label}
        </h2>
      </div>

      <p className="mt-2 text-[24px] font-extrabold tabular-nums" style={{ color: 'var(--text-primary)' }}>
        {plan.priceLabel}
        <span className="mr-1 text-[12px] font-medium" style={{ color: 'var(--text-tertiary)' }}>
          تومان / {formatNumber(plan.months)} ماه
        </span>
      </p>

      <FeatureList features={plan.features} />

      <div className="mt-4 flex flex-col gap-2">
        {/* پرداخت آنلاین فقط وقتی درگاه واقعاً فعال است نشان داده می‌شود */}
        {checkoutEnabled && (
          <button
            onClick={onBuyOnline}
            disabled={disabled}
            className="flex items-center justify-center gap-1.5 rounded-xl py-3 text-[13px] font-bold text-white transition-transform hover:scale-[1.02] disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {busy ? <Loader2 size={15} className="animate-spin" /> : <CreditCard size={15} />}
            {busy ? 'در حال انتقال به درگاه…' : 'پرداخت آنلاین'}
          </button>
        )}

        <button
          onClick={onBuyReceipt}
          disabled={disabled}
          className={
            'flex items-center justify-center gap-1.5 rounded-xl py-3 text-[13px] font-bold transition-transform hover:scale-[1.02] disabled:opacity-60 ' +
            (checkoutEnabled ? 'border' : 'text-white')
          }
          style={
            checkoutEnabled
              ? { borderColor: 'var(--border-default)', color: 'var(--text-primary)' }
              : { backgroundColor: 'var(--color-brand-500)' }
          }
        >
          <Receipt size={15} />
          واریز و آپلود رسید
        </button>
      </div>
    </Card>
  )
}

function FeatureList({ features, muted }: { features: string[]; muted?: boolean }) {
  return (
    <ul className="mt-4 flex flex-1 flex-col gap-2">
      {features.map((feature) => (
        <li key={feature} className="flex items-start gap-2 text-[12.5px]" style={{ color: 'var(--text-secondary)' }}>
          <Check
            size={14}
            className="mt-0.5 shrink-0"
            style={{ color: muted ? 'var(--text-tertiary)' : 'var(--state-success)' }}
          />
          {feature}
        </li>
      ))}
    </ul>
  )
}

function HistoryCard({ history }: { history: SubscriptionRow[] }) {
  return (
    <Card title="سابقه اشتراک" delay={0.2}>
      {history.length === 0 ? (
        <p className="py-8 text-center text-[12.5px]" style={{ color: 'var(--text-tertiary)' }}>
          هنوز خریدی ثبت نشده است.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[680px] text-right text-[13px]">
            <thead>
              <tr style={{ color: 'var(--text-tertiary)' }}>
                <th className="pb-3 font-medium">پلن</th>
                <th className="pb-3 font-medium">روش</th>
                <th className="pb-3 font-medium">مبلغ</th>
                <th className="pb-3 font-medium">خریدار</th>
                <th className="pb-3 font-medium">تاریخ</th>
                <th className="pb-3 font-medium">اعتبار تا</th>
                <th className="pb-3 font-medium">وضعیت</th>
              </tr>
            </thead>
            <tbody>
              {history.map((row) => (
                <tr key={row.id} className="border-t" style={{ borderColor: 'var(--border-subtle)' }}>
                  <td className="py-3 font-semibold" style={{ color: 'var(--text-primary)' }}>
                    {row.planLabel}
                  </td>
                  <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                    {row.methodLabel}
                  </td>
                  <td className="py-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                    {row.amountLabel}
                  </td>
                  <td className="py-3" style={{ color: 'var(--text-tertiary)' }}>
                    {row.buyerName ?? '—'}
                  </td>
                  <td className="py-3 tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
                    {row.createdAt}
                  </td>
                  <td className="py-3 tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
                    {row.endsAt ?? '—'}
                  </td>
                  <td className="py-3">
                    <span
                      className="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                      style={{
                        backgroundColor: `color-mix(in srgb, ${STATUS_COLOR[row.status] ?? 'var(--text-tertiary)'} 14%, transparent)`,
                        color: STATUS_COLOR[row.status] ?? 'var(--text-tertiary)',
                      }}
                    >
                      {row.statusLabel}
                    </span>
                    {row.status === 'failed' && row.reviewNote && (
                      <span className="mt-1 block text-[10.5px]" style={{ color: 'var(--text-tertiary)' }}>
                        {row.reviewNote}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}
