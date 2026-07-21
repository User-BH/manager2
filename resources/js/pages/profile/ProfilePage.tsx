import { useState } from 'react'
import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  Building,
  Building2,
  CalendarDays,
  CheckCircle2,
  IdCard,
  Mail,
  MapPin,
  Pencil,
  Phone,
  Receipt,
  Settings,
  ShieldCheck,
  Users,
  Wallet,
  XCircle,
  type LucideIcon,
} from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { ErrorState, LoadingState } from '@/components/ui/PageState'
import { ProfileEditForm } from './ProfileEditForm'
import { PasswordCard } from './PasswordCard'
import { useApi } from '@/hooks/useApi'
import { useDocumentTitle } from '@/hooks'
import { formatMoney, formatNumber } from '@/lib/format'
import type { ProfileResponse } from './types'

/**
 * «پروفایل من».
 *
 * layout این صفحه عمداً با صفحات فهرستی فرق دارد: به‌جای یک جدول تمام‌عرض،
 * یک هدر پوششی با آواتار و بعد دو ستون (اطلاعات شخصی سمت راست، وابستگی‌ها
 * سمت چپ). هدر و سایدبار داشبورد سر جای خودشان می‌مانند چون این صفحه هم
 * داخل DashboardLayout رندر می‌شود.
 */
export function ProfilePage() {
  const [editing, setEditing] = useState(false)

  useDocumentTitle('پروفایل من')

  const { data, error, isLoading, reload } = useApi<ProfileResponse>('/profile')

  if (isLoading) return <LoadingState rows={5} />
  if (error) return <ErrorState message={error} onRetry={reload} />
  if (!data) return null

  const { profile, units, people, complexes, stats } = data

  return (
    <div className="flex flex-col gap-5">
      <ProfileHero
        name={profile.name}
        roleLabel={profile.roleLabel}
        complexName={profile.complex?.name ?? null}
        joinedAt={profile.joinedAt}
        isActive={profile.isActive}
        onEdit={() => setEditing((prev) => !prev)}
        editing={editing}
      />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <MiniStat icon={Building2} label="واحدهای من" value={formatNumber(stats.unitsCount)} />
        <MiniStat icon={Receipt} label="قبوض صادرشده" value={formatNumber(stats.billsCount)} />
        <MiniStat icon={CheckCircle2} label="پرداخت موفق" value={formatNumber(stats.paidCount)} />
        <MiniStat
          icon={Wallet}
          label="مانده بدهی"
          value={formatMoney(stats.totalDebt)}
          tone={stats.totalDebt > 0 ? 'var(--color-danger)' : 'var(--state-success)'}
        />
      </div>

      {editing ? (
        <Card title="ویرایش اطلاعات">
          <ProfileEditForm
            profile={profile}
            onSaved={() => {
              setEditing(false)
              reload()
            }}
            onCancel={() => setEditing(false)}
          />
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          <div className="flex flex-col gap-5">
            <Card title="اطلاعات شخصی">
              <dl className="flex flex-col">
                <InfoRow icon={Phone} label="شماره تلفن" value={profile.phone} ltr />
                <InfoRow icon={Mail} label="ایمیل" value={profile.email} ltr />
                <InfoRow icon={IdCard} label="کد ملی" value={profile.nationalId} ltr />
                <InfoRow icon={CalendarDays} label="تاریخ تولد" value={profile.birthDateLabel} />
                <InfoRow icon={Phone} label="تماس اضطراری" value={profile.emergencyPhone} ltr />
                <InfoRow icon={MapPin} label="نشانی" value={profile.address} />
                <InfoRow icon={ShieldCheck} label="سطح دسترسی" value={profile.roleLabel} />
              </dl>

              {profile.bio && (
                <p
                  className="mt-4 rounded-xl p-3 text-[12.5px] leading-7"
                  style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
                >
                  {profile.bio}
                </p>
              )}
            </Card>

            <PasswordCard />
          </div>

          <div className="flex flex-col gap-5">
            <UnitsCard units={units} />
            <PeopleCard people={people} />
            <ComplexesCard complexes={complexes} />
          </div>
        </div>
      )}
    </div>
  )
}

/* ------------------------------ هدر پوششی ------------------------------ */

function ProfileHero({
  name,
  roleLabel,
  complexName,
  joinedAt,
  isActive,
  editing,
  onEdit,
}: {
  name: string
  roleLabel: string
  complexName: string | null
  joinedAt: string
  isActive: boolean
  editing: boolean
  onEdit: () => void
}) {
  // حرف اول نام به‌جای عکس؛ سامانه هنوز آپلود آواتار ندارد
  const initial = name.trim().charAt(0) || '؟'

  return (
    <motion.section
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
      className="relative overflow-hidden rounded-2xl border"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      <div
        className="h-24"
        style={{
          background:
            'linear-gradient(120deg, var(--color-brand-600), var(--color-brand-400) 55%, var(--color-accent-500))',
        }}
      />

      <div className="flex flex-wrap items-end gap-4 px-5 pb-5">
        <div
          className="-mt-10 flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl border-4 text-3xl font-extrabold text-white"
          style={{
            backgroundColor: 'var(--color-brand-500)',
            borderColor: 'var(--surface-base)',
          }}
        >
          {initial}
        </div>

        <div className="min-w-0 flex-1 pt-2">
          <h1 className="flex flex-wrap items-center gap-2 text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
            {name}
            <span
              className="rounded-full px-2 py-0.5 text-[11px] font-semibold"
              style={{
                backgroundColor: isActive
                  ? 'color-mix(in srgb, var(--state-success) 14%, transparent)'
                  : 'color-mix(in srgb, var(--state-danger) 14%, transparent)',
                color: isActive ? 'var(--state-success)' : 'var(--state-danger)',
              }}
            >
              {isActive ? 'فعال' : 'غیرفعال'}
            </span>
          </h1>

          <p className="mt-1 text-[12.5px]" style={{ color: 'var(--text-tertiary)' }}>
            {roleLabel}
            {complexName && ` · ${complexName}`}
            {` · عضو از ${joinedAt}`}
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Link
            to="/account"
            className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-[13px] font-semibold"
            style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
          >
            <Settings size={15} />
            تنظیمات حساب
          </Link>

          <button
            onClick={onEdit}
            className="flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white transition-transform hover:scale-[1.03]"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            <Pencil size={15} />
            {editing ? 'بستن ویرایش' : 'ویرایش پروفایل'}
          </button>
        </div>
      </div>
    </motion.section>
  )
}

/* -------------------------------- اجزا --------------------------------- */

function MiniStat({
  icon: Icon,
  label,
  value,
  tone,
}: {
  icon: LucideIcon
  label: string
  value: string
  tone?: string
}) {
  return (
    <div
      className="rounded-2xl border p-4"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      <div className="flex items-center gap-2">
        <Icon size={15} style={{ color: tone ?? 'var(--color-brand-500)' }} />
        <p className="text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
          {label}
        </p>
      </div>
      <p
        className="mt-1.5 text-[19px] font-extrabold tabular-nums"
        style={{ color: tone ?? 'var(--text-primary)' }}
      >
        {value}
      </p>
    </div>
  )
}

function InfoRow({
  icon: Icon,
  label,
  value,
  ltr,
}: {
  icon: LucideIcon
  label: string
  value: string | null
  ltr?: boolean
}) {
  return (
    <div
      className="flex items-center gap-3 border-b py-2.5 last:border-b-0"
      style={{ borderColor: 'var(--border-subtle)' }}
    >
      <Icon size={15} className="shrink-0" style={{ color: 'var(--text-tertiary)' }} />
      <dt className="text-[12.5px]" style={{ color: 'var(--text-tertiary)' }}>
        {label}
      </dt>
      <dd
        dir={ltr && value ? 'ltr' : undefined}
        className="mr-auto truncate text-[12.5px] font-semibold"
        style={{ color: value ? 'var(--text-primary)' : 'var(--text-tertiary)' }}
      >
        {value || 'ثبت نشده'}
      </dd>
    </div>
  )
}

function UnitsCard({ units }: { units: ProfileResponse['units'] }) {
  return (
    <Card title="واحدهای من" subtitle={`${formatNumber(units.length)} مورد`} delay={0.05}>
      {units.length === 0 ? (
        <p className="py-6 text-center text-[12.5px]" style={{ color: 'var(--text-tertiary)' }}>
          هنوز به واحدی متصل نشده‌اید. مدیر مجتمع می‌تواند شما را به واحد وصل کند.
        </p>
      ) : (
        <ul className="flex flex-col gap-2">
          {units.map((unit) => (
            <li
              key={`${unit.id}-${unit.relation}`}
              className="rounded-xl border p-3"
              style={{
                borderColor: 'var(--border-subtle)',
                // واحدهای پایان‌یافته کم‌رنگ‌تر می‌مانند ولی حذف نمی‌شوند
                opacity: unit.isCurrent ? 1 : 0.6,
              }}
            >
              <div className="flex flex-wrap items-center gap-2">
                <Building2 size={15} style={{ color: 'var(--color-brand-500)' }} />
                <span className="text-[13px] font-bold" style={{ color: 'var(--text-primary)' }}>
                  {unit.label}
                </span>
                <span
                  className="rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
                  style={{
                    backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)',
                    color: 'var(--color-brand-600)',
                  }}
                >
                  {unit.relationLabel}
                </span>
                {!unit.isCurrent && (
                  <span className="text-[10.5px]" style={{ color: 'var(--text-tertiary)' }}>
                    پایان‌یافته
                  </span>
                )}

                <span
                  className="mr-auto text-[12px] font-bold tabular-nums"
                  style={{ color: unit.balance > 0 ? 'var(--color-danger)' : 'var(--state-success)' }}
                >
                  {formatMoney(unit.balance)}
                </span>
              </div>

              <p className="mt-1 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                {unit.buildingName && `${unit.buildingName} · `}
                طبقه {formatNumber(unit.floor)} · {formatNumber(unit.area)} متر
                {unit.sharePercent < 100 && ` · سهم ${formatNumber(unit.sharePercent)}٪`}
                {unit.startDate && ` · از ${unit.startDate}`}
              </p>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function PeopleCard({ people }: { people: ProfileResponse['people'] }) {
  return (
    <Card title="افراد مرتبط" subtitle={`${formatNumber(people.length)} نفر`} delay={0.1}>
      {people.length === 0 ? (
        <p className="py-6 text-center text-[12.5px]" style={{ color: 'var(--text-tertiary)' }}>
          فرد مرتبطی ثبت نشده است.
        </p>
      ) : (
        <ul className="flex flex-col">
          {people.map((person) => (
            <li
              key={person.id}
              className="flex items-center gap-3 border-b py-2.5 last:border-b-0"
              style={{ borderColor: 'var(--border-subtle)' }}
            >
              <span
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-white"
                style={{ backgroundColor: 'var(--color-brand-400)' }}
              >
                {person.name.trim().charAt(0) || '؟'}
              </span>

              <span className="min-w-0 flex-1">
                <span className="block truncate text-[12.5px] font-semibold" style={{ color: 'var(--text-primary)' }}>
                  {person.name}
                </span>
                <span className="block text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                  {person.relationLabel} · {person.roleLabel}
                </span>
              </span>

              {person.isActive ? (
                <CheckCircle2 size={14} style={{ color: 'var(--state-success)' }} />
              ) : (
                <XCircle size={14} style={{ color: 'var(--text-tertiary)' }} />
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function ComplexesCard({ complexes }: { complexes: ProfileResponse['complexes'] }) {
  return (
    <Card title="مجتمع‌های وابسته" delay={0.15}>
      {complexes.length === 0 ? (
        <p className="py-6 text-center text-[12.5px]" style={{ color: 'var(--text-tertiary)' }}>
          به مجتمعی متصل نیستید.
        </p>
      ) : (
        <ul className="flex flex-col gap-2">
          {complexes.map((complex) => (
            <li
              key={complex.id}
              className="rounded-xl border p-3"
              style={{
                borderColor: complex.isCurrent ? 'var(--color-brand-400)' : 'var(--border-subtle)',
                backgroundColor: complex.isCurrent
                  ? 'color-mix(in srgb, var(--color-brand-500) 6%, transparent)'
                  : undefined,
              }}
            >
              <div className="flex flex-wrap items-center gap-2">
                <Building size={15} style={{ color: 'var(--color-brand-500)' }} />
                <span className="text-[13px] font-bold" style={{ color: 'var(--text-primary)' }}>
                  {complex.name}
                </span>
                {complex.isCurrent && (
                  <span
                    className="rounded-full px-2 py-0.5 text-[10px] font-bold"
                    style={{ backgroundColor: 'var(--color-brand-500)', color: '#fff' }}
                  >
                    فعال
                  </span>
                )}
              </div>

              <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
                <span className="flex items-center gap-1">
                  <Building2 size={11} />
                  {formatNumber(complex.unitsCount)} واحد
                </span>
                <span className="flex items-center gap-1">
                  <Users size={11} />
                  {formatNumber(complex.usersCount)} کاربر
                </span>
                {complex.address && <span className="truncate">{complex.address}</span>}
              </p>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
