import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  TrendingUp,
  TrendingDown,
  Wallet,
  AlertTriangle,
  Building2,
  Users,
  Receipt,
  Award,
  ArrowLeft,
} from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/PageState'
import { TrendChart } from './components/TrendChart'
import { PaymentStatusChart } from './components/PaymentStatusChart'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { useAuth } from '@/context/AuthContext'
import { formatMoney, formatNumber } from '@/lib/format'
import type { BillStatus } from '@/types'

interface AdminDashboard {
  type: 'admin'
  periodLabel: string
  currency: string
  income: number
  expense: number
  balance: number
  totalDebt: number
  statusCounts: Record<BillStatus, number>
  trend: { label: string; income: number; expense: number }[]
  debtors: { id: number; label: string; floor: number; balance: number }[]
  goodPayers: { id: number; label: string; onTime: number }[]
}

interface SystemDashboard {
  type: 'system'
  totalComplexes: number
  totalUnits: number
  totalUsers: number
  complexes: { id: number; name: string; units: number; users: number }[]
}

interface ResidentDashboard {
  type: 'resident'
  unitCount: number
  totalDebt: number
  currency: string
  units: {
    id: number
    label: string
    balance: number
    latestBill: { id: number; periodLabel: string; total: number; status: BillStatus } | null
  }[]
}

type DashboardData = AdminDashboard | SystemDashboard | ResidentDashboard

export function DashboardPage() {
  const { user } = useAuth()
  const { data, error, isLoading, reload } = useApi<DashboardData>('/dashboard')

  useDocumentTitle('داشبورد')

  if (isLoading) return <LoadingState rows={4} />
  if (error) return <ErrorState message={error} onRetry={reload} />
  if (!data) return null

  return (
    <div className="flex flex-col gap-5">
      <motion.header
        initial={{ opacity: 0, y: -8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          سلام {user?.name} 👋
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          {data.type === 'admin'
            ? `دوره‌ی جاری: ${data.periodLabel}`
            : data.type === 'system'
              ? 'نمای کلی سیستم'
              : 'خلاصه‌ی وضعیت واحدهای شما'}
        </p>
      </motion.header>

      {data.type === 'admin' && <AdminView data={data} />}
      {data.type === 'system' && <SystemView data={data} />}
      {data.type === 'resident' && <ResidentView data={data} />}
    </div>
  )
}

function AdminView({ data }: { data: AdminDashboard }) {
  return (
    <>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="درآمد این ماه" value={formatMoney(data.income)} unit={data.currency} icon={TrendingUp} tone="success" delay={0} />
        <StatCard label="هزینه این ماه" value={formatMoney(data.expense)} unit={data.currency} icon={TrendingDown} tone="warning" delay={0.05} />
        <StatCard label="مانده صندوق" value={formatMoney(data.balance)} unit={data.currency} icon={Wallet} tone="brand" delay={0.1} />
        <StatCard label="بدهی کل ساکنین" value={formatMoney(data.totalDebt)} unit={data.currency} icon={AlertTriangle} tone="danger" delay={0.15} />
      </div>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <Card title="روند درآمد و هزینه" subtitle="شش ماه اخیر" className="xl:col-span-2" delay={0.2}>
          <TrendChart data={data.trend} currency={data.currency} />
        </Card>

        <Card title="وضعیت پرداخت" subtitle="دوره‌ی جاری" delay={0.25}>
          <PaymentStatusChart counts={data.statusCounts} />
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card
          title="بدهکاران"
          subtitle="بیشترین بدهی"
          delay={0.3}
          actions={
            <Link
              to="/bills"
              className="flex items-center gap-1 text-xs font-semibold"
              style={{ color: 'var(--color-brand-600)' }}
            >
              همه قبوض
              <ArrowLeft size={13} />
            </Link>
          }
        >
          {data.debtors.length === 0 ? (
            <EmptyState message="بدهکاری ثبت نشده است." />
          ) : (
            <ul className="flex flex-col gap-1">
              {data.debtors.map((row) => (
                <li
                  key={row.id}
                  className="flex items-center justify-between rounded-xl px-3 py-2.5 text-sm"
                  style={{ backgroundColor: 'var(--surface-sunken)' }}
                >
                  <span style={{ color: 'var(--text-primary)' }}>{row.label}</span>
                  <span className="tabular-nums font-semibold" style={{ color: 'var(--color-danger)' }}>
                    {formatMoney(row.balance)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card title="ساکنین خوش‌حساب" subtitle="پرداخت به‌موقع" delay={0.35}>
          {data.goodPayers.length === 0 ? (
            <EmptyState message="هنوز داده‌ای موجود نیست." />
          ) : (
            <ul className="flex flex-col gap-1">
              {data.goodPayers.map((row, index) => (
                <li
                  key={row.id}
                  className="flex items-center justify-between rounded-xl px-3 py-2.5 text-sm"
                  style={{ backgroundColor: 'var(--surface-sunken)' }}
                >
                  <span className="flex items-center gap-2.5">
                    <span
                      className="flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold"
                      style={{
                        backgroundColor: 'color-mix(in srgb, var(--color-accent-500) 18%, transparent)',
                        color: 'var(--color-accent-600)',
                      }}
                    >
                      {formatNumber(index + 1)}
                    </span>
                    <span style={{ color: 'var(--text-primary)' }}>{row.label}</span>
                  </span>
                  <span className="flex items-center gap-1 text-xs" style={{ color: 'var(--state-success)' }}>
                    <Award size={13} />
                    {formatNumber(row.onTime)} پرداخت به‌موقع
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </>
  )
}

function SystemView({ data }: { data: SystemDashboard }) {
  return (
    <>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="مجتمع‌ها" value={formatNumber(data.totalComplexes)} icon={Building2} tone="brand" />
        <StatCard label="کل واحدها" value={formatNumber(data.totalUnits)} icon={Receipt} tone="info" delay={0.05} />
        <StatCard label="کل کاربران" value={formatNumber(data.totalUsers)} icon={Users} tone="success" delay={0.1} />
      </div>

      <Card title="مجتمع‌ها" subtitle="برای ورود به هر مجتمع، آن را انتخاب کنید" delay={0.15}>
        {data.complexes.length === 0 ? (
          <EmptyState message="هنوز مجتمعی ثبت نشده است." hint="از «مدیریت مجتمع‌ها» اولین مجتمع را بسازید." />
        ) : (
          <ul className="flex flex-col gap-1">
            {data.complexes.map((complex) => (
              <li
                key={complex.id}
                className="flex items-center justify-between rounded-xl px-3 py-3 text-sm"
                style={{ backgroundColor: 'var(--surface-sunken)' }}
              >
                <span className="font-medium" style={{ color: 'var(--text-primary)' }}>
                  {complex.name}
                </span>
                <span className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
                  {formatNumber(complex.units)} واحد · {formatNumber(complex.users)} کاربر
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}

function ResidentView({ data }: { data: ResidentDashboard }) {
  return (
    <>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <StatCard label="تعداد واحدهای شما" value={formatNumber(data.unitCount)} icon={Building2} tone="brand" />
        <StatCard
          label="بدهی فعلی"
          value={formatMoney(data.totalDebt)}
          unit={data.currency}
          icon={Wallet}
          tone={data.totalDebt > 0 ? 'danger' : 'success'}
          delay={0.05}
        />
      </div>

      <Card title="واحدها و صورت‌حساب‌ها" delay={0.1}>
        {data.units.length === 0 ? (
          <EmptyState message="واحدی به شما اختصاص نیافته است." hint="با مدیر مجتمع تماس بگیرید." />
        ) : (
          <ul className="flex flex-col gap-2">
            {data.units.map((unit) => (
              <li
                key={unit.id}
                className="rounded-xl border p-3.5"
                style={{ borderColor: 'var(--border-subtle)' }}
              >
                <div className="flex items-center justify-between">
                  <span className="font-semibold" style={{ color: 'var(--text-primary)' }}>
                    {unit.label}
                  </span>
                  <span
                    className="tabular-nums text-sm font-semibold"
                    style={{ color: unit.balance > 0 ? 'var(--color-danger)' : 'var(--state-success)' }}
                  >
                    {formatMoney(unit.balance)} {data.currency}
                  </span>
                </div>

                {unit.latestBill && (
                  <p className="mt-1.5 text-xs" style={{ color: 'var(--text-tertiary)' }}>
                    آخرین قبض: {unit.latestBill.periodLabel} — {formatMoney(unit.latestBill.total)} {data.currency}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}
