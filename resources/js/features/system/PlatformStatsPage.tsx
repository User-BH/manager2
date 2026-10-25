import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Activity,
  AlertTriangle,
  Building2,
  CreditCard,
  Crown,
  PauseCircle,
  Users,
  Wallet,
} from 'lucide-react'
import { api } from '@/shared/lib/api'
import { useAuth } from '@/shared/stores/authStore'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useDocumentTitle } from '@/shared/hooks'
import { Card } from '@/shared/ui/Card'
import { StatCard } from '@/shared/ui/StatCard'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { formatMoney, formatNumber } from '@/shared/lib/format'

interface Stats {
  complexes: { total: number; active: number; suspended: number; units: number }
  people: { total: number; active: number; engaged: number; openRequests: number }
  money: {
    periodLabel: string
    paymentsCount: number
    paymentsVolume: number
    paymentsThisPeriod: number
    outstanding: number
    subscriptionRevenue: number
    activeSubscriptions: number
  }
  health: { unresolvedErrors: number; errorsToday: number; failedJobs: number }
  trend: { period: string; label: string; complexes: number; users: number }[]
  analytics: Record<string, boolean>
}

const ANALYTICS_LABELS: Record<string, string> = {
  ga4: 'Google Analytics 4',
  gtm: 'Google Tag Manager',
  clarity: 'Microsoft Clarity',
  sentry: 'Sentry',
}

/**
 * آمارِ کلِ پلتفرم (R29).
 *
 * ─── چرا «درآمدِ پلتفرم» جدا از «حجمِ پرداخت‌ها» ─────────────────────────────
 * پولی که ساکنین بابتِ شارژ می‌دهند فقط از سامانه رد می‌شود و مالِ
 * ساختمان‌هاست؛ درآمدِ ما فقط اشتراک‌هاست. نشان‌دادنشان در یک عدد، گزارشی
 * می‌ساخت که هیچ تصمیمی رویش قابلِ گرفتن نیست.
 */
export function PlatformStatsPage() {
  useDocumentTitle('آمار پلتفرم')

  // دادهٔ آزمایشی پیش‌فرض بیرون است و سرور کنارش می‌گذارد (۱۳/۱)
  const [includeDemo, setIncludeDemo] = useState(false)
  // روی Production سرور این کلید را نادیده می‌گیرد؛ پس نشانش هم نمی‌دهیم
  const demoData = useAuth().user?.demoData === true

  const { data, error, isLoading, refetch } = useQuery({
    // کلیدِ مشترک دست نمی‌خورد (باندلِ همه‌ی صفحه‌ها)؛ گزینه فقط به انتهای کلیدِ همین صفحه می‌چسبد
    queryKey: [...queryKeys.system.stats(), { includeDemo }],
    queryFn: ({ signal }) =>
      api<Stats>(`/system/stats${includeDemo ? '?include_demo=1' : ''}`, { signal }),
  })

  if (isLoading) return <InlineSpinner />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data) return null

  const peak = Math.max(1, ...data.trend.map((m) => Math.max(m.complexes, m.users)))

  return (
    <div className="print-area flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          آمار پلتفرم
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          نمای کلی همه‌ی مجتمع‌ها، کاربران، درآمد و سلامت سامانه
        </p>
      </header>

      {demoData && (
        <label
          className="flex items-center gap-1.5 text-[12px]"
          style={{ color: 'var(--text-secondary)' }}
        >
          <input
            type="checkbox"
            className="h-4 w-4 rounded"
            checked={includeDemo}
            onChange={(e) => setIncludeDemo(e.target.checked)}
          />
          شامل داده‌ی آزمایشی
        </label>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="مجتمع‌های فعال"
          value={formatNumber(data.complexes.active)}
          unit={`از ${formatNumber(data.complexes.total)}`}
          icon={Building2}
          delay={0}
        />
        <StatCard
          label="کاربران فعال"
          value={formatNumber(data.people.active)}
          unit={`${formatNumber(data.people.engaged)} نفر در ۳۰ روز اخیر`}
          icon={Users}
          delay={0.05}
        />
        <StatCard
          label="درآمد اشتراک"
          value={formatMoney(data.money.subscriptionRevenue)}
          unit={`${formatNumber(data.money.activeSubscriptions)} اشتراک فعال`}
          icon={Crown}
          tone="success"
          delay={0.1}
        />
        <StatCard
          label="خطاهای بازنشده"
          value={formatNumber(data.health.unresolvedErrors)}
          unit={`${formatNumber(data.health.errorsToday)} مورد امروز`}
          icon={AlertTriangle}
          tone={data.health.unresolvedErrors > 0 ? 'danger' : undefined}
          delay={0.15}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card title="گردش مالی" subtitle={`دوره‌ی جاری: ${data.money.periodLabel}`}>
          <ul className="flex flex-col gap-2 text-[13px]">
            <Row
              icon={CreditCard}
              label="پرداخت‌های موفق (کل)"
              value={`${formatMoney(data.money.paymentsVolume)} — ${formatNumber(data.money.paymentsCount)} تراکنش`}
            />
            <Row
              icon={Wallet}
              label="پرداخت‌های دوره‌ی جاری"
              value={formatMoney(data.money.paymentsThisPeriod)}
            />
            <Row
              icon={AlertTriangle}
              label="بدهی معوق کل پلتفرم"
              value={formatMoney(data.money.outstanding)}
            />
          </ul>

          {/* تفکیکی که نبودش گزارش را بی‌معنا می‌کرد */}
          <p className="mt-3 text-[11.5px] leading-6" style={{ color: 'var(--text-tertiary)' }}>
            اعداد بالا پول ساختمان‌هاست که از سامانه رد می‌شود. درآمد خودِ پلتفرم فقط اشتراک‌هاست که
            در کارت بالا آمده.
          </p>
        </Card>

        <Card title="سلامت سامانه">
          <ul className="flex flex-col gap-2 text-[13px]">
            <Row
              icon={AlertTriangle}
              label="خطاهای رسیدگی‌نشده"
              value={formatNumber(data.health.unresolvedErrors)}
            />
            <Row
              icon={Activity}
              label="کارهای شکست‌خورده‌ی صف"
              value={formatNumber(data.health.failedJobs)}
            />
            <Row
              icon={PauseCircle}
              label="مجتمع‌های تعلیق‌شده"
              value={formatNumber(data.complexes.suspended)}
            />
            <Row
              icon={Building2}
              label="درخواست‌های باز ساکنین"
              value={formatNumber(data.people.openRequests)}
            />
          </ul>

          <div className="mt-4">
            <p className="mb-2 text-[12px] font-bold" style={{ color: 'var(--text-secondary)' }}>
              ابزارهای آنالیز
            </p>
            <div className="flex flex-wrap gap-1.5">
              {Object.entries(data.analytics).map(([key, on]) => (
                <span
                  key={key}
                  className="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                  style={{
                    backgroundColor: on ? 'rgba(16,185,129,0.14)' : 'var(--surface-sunken)',
                    color: on ? 'var(--state-success)' : 'var(--text-tertiary)',
                  }}
                >
                  {ANALYTICS_LABELS[key] ?? key}
                  {on ? ' ✓' : ' —'}
                </span>
              ))}
            </div>
          </div>
        </Card>
      </div>

      <Card title="رشد شش‌ماهه" subtitle="مجتمع‌ها و کاربران تازه در هر ماه شمسی">
        <ul className="flex flex-col gap-2.5">
          {data.trend.map((month) => (
            <li key={month.period} className="flex items-center gap-3 text-[12px]">
              <span className="w-24 shrink-0" style={{ color: 'var(--text-secondary)' }}>
                {month.label}
              </span>

              {/*
                نمودارِ میله‌ای ساده با div و نه کتابخانه‌ی نمودار: شش ردیف
                دو-مقداری ارزشِ افزودنِ وابستگی ندارد.
              */}
              <span className="flex flex-1 flex-col gap-1">
                <Bar value={month.complexes} peak={peak} color="var(--color-brand-500)" />
                <Bar value={month.users} peak={peak} color="rgba(100,116,139,0.55)" />
              </span>

              <span
                className="w-28 shrink-0 text-left tabular-nums"
                style={{ color: 'var(--text-tertiary)' }}
              >
                {formatNumber(month.complexes)} مجتمع · {formatNumber(month.users)} کاربر
              </span>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  )
}

function Bar({ value, peak, color }: { value: number; peak: number; color: string }) {
  return (
    <span
      className="block h-1.5 rounded-full transition-[width]"
      style={{
        // حداقل ۲٪ تا ماهِ صفر هم دیده شود و ردیف خالی به‌نظر نرسد
        width: `${Math.max(2, (value / peak) * 100)}%`,
        backgroundColor: color,
      }}
    />
  )
}

function Row({ icon: Icon, label, value }: { icon: typeof Users; label: string; value: string }) {
  return (
    <li className="flex items-center justify-between gap-2">
      <span className="flex items-center gap-1.5" style={{ color: 'var(--text-secondary)' }}>
        <Icon size={13} style={{ color: 'var(--color-brand-500)' }} />
        {label}
      </span>
      <span className="tabular-nums font-bold" style={{ color: 'var(--text-primary)' }}>
        {value}
      </span>
    </li>
  )
}
