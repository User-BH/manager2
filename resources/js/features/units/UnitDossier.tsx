import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeftRight,
  Boxes,
  Car,
  Layers,
  Receipt,
  Ruler,
  TriangleAlert,
  Users,
} from 'lucide-react'
import { api } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { ErrorState, InlineSpinner } from '@/shared/ui/PageState'
import { formatNumber } from '@/shared/lib/format'
import type { UnitDossier as Dossier, UnitTenure } from './types'

/**
 * پرونده‌ی کاملِ یک واحد با تاریخچه‌ی مالکیت و سکونت (R26).
 *
 * ─── چرا تاریخچه اینجا دیده می‌شود ─────────────────────────────────────────
 * جدولِ `unit_user` از اول سابقه را نگه می‌داشت، ولی هیچ صفحه‌ای نشانش
 * نمی‌داد — پس عملاً کسی خبر نداشت هست، و کدی که ساکن را جابه‌جا می‌کرد
 * سال‌ها بی‌سروصدا پاکش می‌کرد. چیزی که دیده نمی‌شود، محافظت هم نمی‌شود.
 */
export function UnitDossier({ unitId }: { unitId: number }) {
  const queryClient = useQueryClient()

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.units.dossier(unitId),
    queryFn: ({ signal }) => api<Dossier>(`/units/${unitId}`, { signal }),
  })

  const endTenure = useMutation({
    mutationFn: (tenureId: number) =>
      api(`/units/${unitId}/tenures/${tenureId}/end`, { method: 'PATCH' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.units.all() })
      void refetch()
      toastSuccess('این دوره بسته شد.')
    },
    onError: (err) => alertError(err, 'بستن دوره ممکن نشد.'),
  })

  if (isLoading) return <InlineSpinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data) return null

  const { unit, tenures, ownershipShare, history } = data
  const current = tenures.filter((t) => t.isCurrent)
  const past = tenures.filter((t) => !t.isCurrent)

  return (
    <div className="flex flex-col gap-5">
      {/* ── مشخصات ─────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Fact icon={Layers} label="طبقه" value={formatNumber(unit.floor)} />
        <Fact icon={Ruler} label="متراژ" value={`${formatNumber(unit.area)} م²`} />
        <Fact icon={Car} label="پارکینگ" value={formatNumber(unit.parkingCount)} />
        <Fact icon={Boxes} label="انباری" value={formatNumber(unit.storageCount)} />
      </div>

      {/*
        اگر جمعِ سهمِ مالکان ۱۰۰ نباشد پرونده ناقص است و مدیر باید ببیندش:
        رأیِ وزنیِ نظرسنجی (R24) و سهمِ هزینه هر دو رویش حساب می‌کنند.
      */}
      {current.some((t) => t.relation === 'owner') && Math.abs(ownershipShare - 100) > 0.01 && (
        <p
          className="flex items-center gap-1.5 rounded-xl px-3 py-2 text-[12px]"
          style={{ backgroundColor: 'rgba(245,158,11,0.12)', color: '#b45309' }}
        >
          <TriangleAlert size={13} />
          جمع سهم مالکان {formatNumber(ownershipShare)}٪ است، نه ۱۰۰٪.
        </p>
      )}

      {/* ── ساکنان و مالکان جاری ────────────────────────────────────────── */}
      <section>
        <h3
          className="mb-2 flex items-center gap-1.5 text-[13px] font-bold"
          style={{ color: 'var(--text-primary)' }}
        >
          <Users size={14} />
          اکنون
        </h3>

        {current.length === 0 ? (
          <p className="text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
            هیچ مالک یا ساکنی برای این واحد ثبت نشده است.
          </p>
        ) : (
          <ul className="flex flex-col gap-1.5">
            {current.map((tenure) => (
              <TenureRow
                key={tenure.id}
                tenure={tenure}
                onEnd={() => endTenure.mutate(tenure.id)}
                isEnding={endTenure.isPending}
              />
            ))}
          </ul>
        )}
      </section>

      {/* ── تاریخچه ─────────────────────────────────────────────────────── */}
      <section>
        <h3
          className="mb-2 flex items-center gap-1.5 text-[13px] font-bold"
          style={{ color: 'var(--text-primary)' }}
        >
          <ArrowLeftRight size={14} />
          تاریخچه
        </h3>

        {past.length === 0 ? (
          <p className="text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
            هنوز جابه‌جایی ثبت نشده است.
          </p>
        ) : (
          <ul className="flex flex-col gap-1.5">
            {past.map((tenure) => (
              <TenureRow key={tenure.id} tenure={tenure} />
            ))}
          </ul>
        )}
      </section>

      {/*
        این دو عدد ادعای اصلیِ پرونده را نشان می‌دهند: سابقه به **واحد**
        بسته است و با رفتن مالک یا مستاجر پاک نمی‌شود.
      */}
      <p
        className="flex items-center gap-1.5 rounded-xl px-3 py-2 text-[12px]"
        style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
      >
        <Receipt size={13} />
        {formatNumber(history.bills)} قبض و {formatNumber(history.payments)} پرداخت در پرونده‌ی این
        واحد ثبت است و با تغییر مالک یا مستاجر پاک نمی‌شود.
      </p>
    </div>
  )
}

function TenureRow({
  tenure,
  onEnd,
  isEnding,
}: {
  tenure: UnitTenure
  onEnd?: () => void
  isEnding?: boolean
}) {
  const isOwner = tenure.relation === 'owner'

  return (
    <li
      className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl px-3 py-2 text-[12.5px]"
      style={{
        backgroundColor: 'var(--surface-sunken)',
        color: 'var(--text-primary)',
        opacity: tenure.isCurrent ? 1 : 0.75,
      }}
    >
      <span className="font-bold">{tenure.name}</span>

      <span
        className="rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
        style={{
          backgroundColor: isOwner ? 'rgba(16,185,129,0.14)' : 'rgba(14,165,233,0.12)',
          color: isOwner ? '#059669' : '#0284c7',
        }}
      >
        {tenure.relationLabel}
        {isOwner && tenure.sharePercent < 100 && ` · ${formatNumber(tenure.sharePercent)}٪`}
      </span>

      <span className="text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
        {tenure.startDate ?? '—'}
        {' تا '}
        {/* دوره‌ی باز «تا کنون» است، نه یک تاریخِ نامعلوم */}
        {tenure.endDate ?? (tenure.isOpen ? 'کنون' : '—')}
      </span>

      {onEnd && (
        <>
          <span className="flex-1" />
          <button
            type="button"
            onClick={onEnd}
            disabled={isEnding}
            className="text-[11px] underline disabled:opacity-50"
            style={{ color: 'var(--text-tertiary)' }}
          >
            پایان دوره
          </button>
        </>
      )}
    </li>
  )
}

function Fact({ icon: Icon, label, value }: { icon: typeof Layers; label: string; value: string }) {
  return (
    <div className="rounded-xl px-3 py-2" style={{ backgroundColor: 'var(--surface-sunken)' }}>
      <p className="flex items-center gap-1 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
        <Icon size={12} />
        {label}
      </p>
      <p className="mt-0.5 font-bold" style={{ color: 'var(--text-primary)' }}>
        {value}
      </p>
    </div>
  )
}
