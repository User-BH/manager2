import { useDeferredValue, useState } from 'react'
import { Loader2, Search, ShieldCheck, Trash2, UserCog } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { ErrorState, LoadingState } from '@/components/ui/PageState'
import { useApi } from '@/hooks/useApi'
import { useDebounce, useDocumentTitle } from '@/hooks'
import { api } from '@/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/lib/alert'

interface Member {
  id: number
  name: string
  phone: string
  role: string
  roleLabel: string
  isActive: boolean
  complex: { id: number; name: string } | null
  registeredAt: string
}

interface MembersResponse {
  data: Member[]
  meta: { total: number; page: number; lastPage: number }
  roles: { value: string; label: string }[]
}

/**
 * فهرست همه‌ی اعضای ثبت‌نام‌شده برای ادمینِ کل: جست‌وجو با شماره/نام، مشاهده‌ی
 * اطلاعات، و تغییرِ نقش (از جمله ارتقا به ادمینِ کل).
 */
export function MembersPage() {
  useDocumentTitle('اعضای سامانه')

  const [q, setQ] = useState('')
  // debounce درخواست را کم می‌کند؛ useDeferredValue هم بازخوانی را کم‌اولویت
  // می‌کند تا با حجمِ بالای ثبت‌نام‌ها، تایپ در کادرِ جست‌وجو روان بماند.
  const debounced = useDebounce(q, 400)
  const deferredQuery = useDeferredValue(debounced)
  const { data, error, isLoading, reload } = useApi<MembersResponse>(
    `/system/members?q=${encodeURIComponent(deferredQuery)}`,
  )

  const [savingId, setSavingId] = useState<number | null>(null)

  async function changeRole(member: Member, role: string) {
    if (role === member.role) return

    const roleLabel = data?.roles.find((r) => r.value === role)?.label ?? role
    const ok = await confirmAction({
      title: 'تغییر نقشِ کاربر',
      text: `نقشِ «${member.name || member.phone}» به «${roleLabel}» تغییر کند؟`,
      confirmLabel: 'بله، تغییر بده',
      danger: role === 'super_admin',
    })
    if (!ok) return

    setSavingId(member.id)
    try {
      await api(`/system/members/${member.id}`, {
        method: 'PATCH',
        body: { role, is_active: member.isActive },
      })
      toastSuccess('نقشِ کاربر به‌روزرسانی شد.')
      reload()
    } catch (err) {
      alertError(err, 'تغییر نقش ممکن نشد.')
    } finally {
      setSavingId(null)
    }
  }

  async function removeMember(member: Member) {
    const ok = await confirmAction({
      title: 'حذف کاربر',
      text: `کاربر «${member.name || member.phone}» برای همیشه حذف شود؟`,
      confirmLabel: 'حذف',
      danger: true,
    })
    if (!ok) return

    setSavingId(member.id)
    try {
      await api(`/system/members/${member.id}`, { method: 'DELETE' })
      toastSuccess('کاربر حذف شد.')
      reload()
    } catch (err) {
      alertError(err, 'حذف کاربر ممکن نشد.')
    } finally {
      setSavingId(null)
    }
  }

  async function toggleActive(member: Member) {
    setSavingId(member.id)
    try {
      await api(`/system/members/${member.id}`, {
        method: 'PATCH',
        body: { role: member.role, is_active: !member.isActive },
      })
      toastSuccess(member.isActive ? 'حساب غیرفعال شد.' : 'حساب فعال شد.')
      reload()
    } catch (err) {
      alertError(err, 'تغییرِ وضعیت ممکن نشد.')
    } finally {
      setSavingId(null)
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          اعضای سامانه
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          همه‌ی کاربرانِ ثبت‌نام‌شده — حتی آن‌هایی که هنوز به مجتمعی وصل نشده‌اند.
        </p>
      </header>

      {/* جست‌وجو */}
      <div className="relative">
        <Search
          size={17}
          className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2"
          style={{ color: 'var(--text-tertiary)' }}
        />
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="جست‌وجو با شماره موبایل یا نام…"
          className="w-full rounded-xl border py-3 pr-11 pl-4 text-[13.5px] outline-none focus:ring-2"
          style={{
            backgroundColor: 'var(--surface-sunken)',
            borderColor: 'var(--border-subtle)',
            color: 'var(--text-primary)',
            ['--tw-ring-color' as string]: 'var(--ring-focus)',
          }}
        />
      </div>

      {isLoading && <LoadingState rows={5} />}
      {error && <ErrorState message={error} onRetry={reload} />}

      {data && (
        <Card
          title={`نتایج (${data.meta.total})`}
          subtitle={data.data.length === 0 ? 'کاربری پیدا نشد' : undefined}
        >
          <div className="flex flex-col divide-y" style={{ borderColor: 'var(--border-subtle)' }}>
            {data.data.map((member) => (
              <div
                key={member.id}
                className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 md:flex-row md:items-center md:justify-between"
              >
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="text-[14px] font-bold" style={{ color: 'var(--text-primary)' }}>
                      {member.name || '—'}
                    </span>
                    {member.role === 'super_admin' && (
                      <ShieldCheck size={14} style={{ color: 'var(--color-brand-500)' }} />
                    )}
                  </div>
                  <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
                    <span dir="ltr">{member.phone}</span>
                    <span>مجتمع: {member.complex?.name ?? '—'}</span>
                    <span>ثبت‌نام: {member.registeredAt}</span>
                  </div>
                </div>

                <div className="flex items-center gap-2.5">
                  <label className="flex items-center gap-1.5 text-[12px]" style={{ color: 'var(--text-secondary)' }}>
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded"
                      checked={member.isActive}
                      disabled={savingId === member.id}
                      onChange={() => void toggleActive(member)}
                    />
                    فعال
                  </label>

                  <div className="relative">
                    <UserCog
                      size={15}
                      className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2"
                      style={{ color: 'var(--text-tertiary)' }}
                    />
                    <select
                      value={member.role}
                      disabled={savingId === member.id}
                      onChange={(e) => void changeRole(member, e.target.value)}
                      className="appearance-none rounded-xl border py-2 pr-8 pl-3 text-[12.5px] font-semibold outline-none focus:ring-2"
                      style={{
                        backgroundColor: 'var(--surface-sunken)',
                        borderColor: 'var(--border-subtle)',
                        color: 'var(--text-primary)',
                        ['--tw-ring-color' as string]: 'var(--ring-focus)',
                      }}
                    >
                      {data.roles.map((r) => (
                        <option key={r.value} value={r.value}>
                          {r.label}
                        </option>
                      ))}
                    </select>
                  </div>

                  <button
                    type="button"
                    onClick={() => void removeMember(member)}
                    disabled={savingId === member.id}
                    aria-label="حذف کاربر"
                    className="flex h-8 w-8 items-center justify-center rounded-lg border transition-colors hover:bg-(--surface-sunken) disabled:opacity-50"
                    style={{ borderColor: 'var(--border-subtle)', color: 'var(--color-danger)' }}
                  >
                    <Trash2 size={14} />
                  </button>

                  {savingId === member.id && (
                    <Loader2 size={15} className="animate-spin" style={{ color: 'var(--color-brand-500)' }} />
                  )}
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  )
}
