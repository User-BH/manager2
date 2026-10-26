import { useEffect, useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  ArrowRight,
  BadgeCheck,
  Building2,
  CalendarClock,
  CreditCard,
  Crown,
  History,
  Hourglass,
  RefreshCw,
  Sparkles,
} from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { ErrorState } from '@/shared/ui/PageState'
import { CardListSkeleton } from '@/shared/ui/Skeleton'
import { useQuery } from '@tanstack/react-query'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import {
  alertError,
  alertInfo,
  alertSuccess,
  confirmAction,
  toastSuccess,
} from '@/shared/lib/alert'
import { formatNumber } from '@/shared/lib/format'
import { PAYMENT_HISTORY_PATH, PaymentHistory, PlanWizard } from '@/shared/ui/plans/PlanWizard'
import type {
  AccountSettingsResponse,
  CurrentSubscription,
  SubscriptionRow,
} from '@/shared/ui/plans/types'

/**
 * «تنظیمات حساب» ِ مدیرِ مجتمع (مرحلهٔ ۱۳ · فاز ۶؛ ایرادات ۱۰، بخشِ اول).
 *
 * بالای صفحه پلنِ فعلی و مجتمع و «تمدیدِ پلنِ فعلی» (بندهای ۴ و ۲۰)؛ زیرش جادوگرِ خرید (نوع ← پلن ← مدت ← تأیید ←
 * پرداخت) و «تاریخچه‌ی پرداخت‌ها». اشتراک به مجتمع تعلق دارد نه به کاربر، پس هر مدیرِ همان مجتمع همین وضعیت را
 * می‌بیند. واحد و سوپر ادمین این صفحه را ندارند — سرور هم رد می‌کند (`SubscriptionPolicy::viewAccount`).
 */
export function AccountPage() {
  const [params, setParams] = useSearchParams()
  const historyView = useLocation().pathname === PAYMENT_HISTORY_PATH
  const [renewing, setRenewing] = useState(false)

  useDocumentTitle(historyView ? 'تاریخچه پرداخت‌ها' : 'تنظیمات حساب')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.subscription.all(),
    queryFn: ({ signal }) => api<AccountSettingsResponse>('/subscription', { signal }),
  })

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
      void refetch()
    } else if (checkout === 'failed') {
      void alertInfo(
        'پرداخت انجام نشد.',
        'مبلغی از حساب شما کسر نشده و پلنِ فعلی‌تان دست نخورده است. می‌توانید دوباره تلاش کنید.',
      )
    } else if (checkout === 'unknown') {
      /*
       * 🔴 حالتِ سوم (R59): درگاه پاسخ نداد، پس **نمی‌دانیم** پول کسر شده یا نه. اشتراک در وضعیتِ در-انتظار می‌ماند
       * و `payments:stale` آن را به اپراتور نشان می‌دهد.
       */
      void alertInfo(
        'نتیجه‌ی پرداخت مشخص نشد.',
        'ارتباط با بانک قطع شد و وضعیت این تراکنش هنوز روشن نیست. ' +
          'تا بررسی، دوباره پرداخت نکنید؛ اگر مبلغی کسر شده باشد پیگیری می‌شود.',
      )
    } else if (checkout === 'review') {
      void alertInfo(
        'پرداخت تأیید شد ولی مجتمع ساخته نشد.',
        'دوباره پرداخت نکنید: اگر حساب‌تان دوباره فعال شود مجتمع خودکار ساخته می‌شود، وگرنه مبلغ بازگردانده می‌شود.',
      )
    } else if (checkout === 'error') {
      void alertInfo('خرید انجام نشد.', params.get('message') ?? undefined)
    }

    const next = new URLSearchParams(params)
    next.delete('checkout')
    next.delete('tracking')
    next.delete('message')
    setParams(next, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params])

  if (isLoading) return <CardListSkeleton items={3} />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data) return null

  const pendingRequest = data.history.find((row) => row.status === 'pending') ?? null

  async function cancelSubscription(id: number) {
    const ok = await confirmAction({
      title: 'اشتراک لغو شود؟',
      text: 'تا پایان دوره‌ی پرداخت‌شده امکاناتِ پلن فعال می‌ماند.',
      confirmLabel: 'لغو اشتراک',
      danger: true,
    })
    if (!ok) return

    try {
      await api(`/subscription/${id}/cancel`, { method: 'POST' })
      toastSuccess('اشتراک لغو شد.')
      void refetch()
    } catch (err) {
      alertError(err, 'لغو اشتراک ممکن نشد.')
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1
          className="flex items-center gap-2 text-xl font-extrabold"
          style={{ color: 'var(--text-primary)' }}
        >
          {historyView ? (
            <History size={19} style={{ color: 'var(--color-brand-500)' }} />
          ) : (
            <CreditCard size={19} style={{ color: 'var(--color-brand-500)' }} />
          )}
          {historyView ? 'تاریخچه پرداخت‌ها' : 'تنظیمات حساب'}
        </h1>
        {historyView ? (
          <Link
            to="/account"
            className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-body font-semibold"
            style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
          >
            <ArrowRight size={15} />
            بازگشت به تنظیمات حساب
          </Link>
        ) : (
          <Link
            to={PAYMENT_HISTORY_PATH}
            className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-body font-semibold"
            style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
          >
            <History size={15} />
            تاریخچه پرداخت‌ها
          </Link>
        )}
      </header>

      {historyView ? (
        <PaymentHistory />
      ) : (
        <>
          {pendingRequest && <PendingBanner request={pendingRequest} />}

          <CurrentPlanBanner
            complexName={data.complexName}
            current={data.current}
            canRenew={data.renewal !== null && pendingRequest === null}
            onRenew={() => {
              setRenewing(true)
              window.scrollTo({ top: 0, behavior: 'smooth' })
            }}
            onCancel={cancelSubscription}
          />

          {data.usage && <UsageCard usage={data.usage} planLabel={data.currentPlanLabel} />}

          {pendingRequest === null && (
            <Card
              title={
                renewing ? 'تمدیدِ پلنِ فعلی' : data.current ? 'تمدید یا تغییرِ پلن' : 'خریدِ پلن'
              }
            >
              {/* `key`: «تمدیدِ پلنِ فعلی» جادوگر را از گامِ مدت از نو می‌سازد */}
              <PlanWizard
                key={renewing ? 'renew' : 'buy'}
                data={data}
                renewing={renewing}
                onLeaveRenewal={() => setRenewing(false)}
              />
            </Card>
          )}
        </>
      )}
    </div>
  )
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
        <p className="text-body font-bold" style={{ color: 'var(--text-primary)' }}>
          پرداختِ «{request.planLabel}» در انتظارِ نتیجه‌ی درگاه است
        </p>
        <p className="mt-0.5 text-small" style={{ color: 'var(--text-tertiary)' }}>
          ثبت‌شده در {request.createdAt} · مبلغ {request.amountLabel} تومان — تا روشن شدنِ نتیجه
          دوباره پرداخت نکنید؛ اگر مبلغی کسر شده باشد خودکار پیگیری می‌شود.
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
          <p className="text-body font-bold" style={{ color: 'var(--text-primary)' }}>
            واحدهای ثبت‌شده
          </p>
          <p className="mt-0.5 text-small" style={{ color: 'var(--text-tertiary)' }}>
            {unlimited
              ? `${formatNumber(usage.units)} واحد — بدون محدودیت در ${planLabel}`
              : `${formatNumber(usage.units)} از ${formatNumber(usage.unitLimit ?? 0)} واحد مجاز در ${planLabel}`}
          </p>
        </div>

        {!unlimited && (
          <span
            className="text-reading font-extrabold tabular-nums"
            style={{ color: nearLimit ? 'var(--color-danger)' : 'var(--color-brand-600)' }}
          >
            {formatNumber(Math.round(percent))}٪
          </span>
        )}
      </div>

      {!unlimited && (
        <div
          className="mt-3 h-2 overflow-hidden rounded-full"
          style={{ backgroundColor: 'var(--surface-sunken)' }}
        >
          <motion.div
            initial={{ width: 0 }}
            animate={{ width: `${percent}%` }}
            transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
            className="h-full rounded-full"
            style={{
              backgroundColor: nearLimit ? 'var(--color-danger)' : 'var(--color-brand-500)',
            }}
          />
        </div>
      )}

      {nearLimit && (
        <p className="mt-2.5 text-small" style={{ color: 'var(--color-danger)' }}>
          به سقفِ واحدهای پلن نزدیک شده‌اید. برای ثبتِ واحدِ بیشتر، پلنی با سقفِ بالاتر انتخاب کنید.
        </p>
      )}
    </Card>
  )
}

interface CurrentPlanBannerProps {
  complexName: string | null
  current: CurrentSubscription | null
  canRenew: boolean
  onRenew: () => void
  onCancel: (id: number) => void
}

/** «پلنِ فعلیِ شما: حرفه‌ای — ۳ ماهه / مجتمع: …» و «تمدیدِ پلنِ فعلی» (بندهای ۴، ۲۰ و ۱۱۴) */
function CurrentPlanBanner({
  complexName,
  current,
  canRenew,
  onRenew,
  onCancel,
}: CurrentPlanBannerProps) {
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
          <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
            {complexName
              ? `«${complexName}» هنوز پلنِ فعالی ندارد.`
              : 'این مجتمع هنوز پلنِ فعالی ندارد.'}
          </p>
          <p className="mt-0.5 text-small" style={{ color: 'var(--text-tertiary)' }}>
            از جادوگرِ پایین پلن و مدت را انتخاب کنید؛ اشتراک از لحظه‌ی پرداختِ موفق شروع می‌شود.
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
      className="overflow-hidden rounded-2xl border shadow-sm"
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
          <p
            className="flex items-center gap-2 text-reading font-extrabold"
            style={{ color: 'var(--text-primary)' }}
          >
            پلنِ فعلیِ شما: {current.tierLabel ?? current.planLabel} —{' '}
            {formatNumber(current.months)} ماهه
            <BadgeCheck size={16} style={{ color: 'var(--state-success)' }} />
          </p>
          {complexName && (
            <p
              className="mt-0.5 text-body font-semibold"
              style={{ color: 'var(--text-secondary)' }}
            >
              مجتمع: {complexName}
            </p>
          )}
          <p
            className="mt-1 flex flex-wrap items-center gap-x-3 text-small"
            style={{ color: 'var(--text-tertiary)' }}
          >
            <span className="flex items-center gap-1">
              <CalendarClock size={12} />
              پرداخت‌شده تا {current.paidUntil}
            </span>
            <span>{formatNumber(current.daysLeftTotal)} روز باقی‌مانده</span>
            {current.trackingCode && <span dir="ltr">کد رهگیری: {current.trackingCode}</span>}
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          {canRenew && (
            <button
              type="button"
              onClick={onRenew}
              className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-body font-bold text-white shadow-sm transition hover:shadow-md"
              style={{ backgroundColor: 'var(--color-brand-600)' }}
            >
              <RefreshCw size={15} />
              تمدید پلن فعلی
            </button>
          )}
          <button
            type="button"
            onClick={() => onCancel(current.id)}
            className="rounded-xl border px-4 py-2.5 text-body font-semibold"
            style={{ borderColor: 'var(--border-default)', color: 'var(--color-danger)' }}
          >
            لغو اشتراک
          </button>
        </div>
      </div>
    </motion.div>
  )
}
