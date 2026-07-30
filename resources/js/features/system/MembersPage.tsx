import { useDeferredValue, useState } from 'react'
import { Loader2, Search, ShieldCheck, Trash2, UserCog } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { ErrorState, LoadingState } from '@/shared/ui/PageState'
import { useQuery } from '@tanstack/react-query'
import { useDebounce, useDocumentTitle } from '@/shared/hooks'
import { useApiAction } from '@/shared/hooks/useAction'
import { api } from '@/shared/lib/api'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'

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
  /*
   * کلید شاملِ عبارتِ جست‌وجو است، پس هر عبارت کشِ خودش را دارد: برگشتن به
   * جست‌وجوی قبلی آنی است و دوباره به سرور نمی‌رود.
   */
  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.members.list({ q: deferredQuery }),
    queryFn: ({ signal }) =>
      api<MembersResponse>(`/system/members?q=${encodeURIComponent(deferredQuery)}`, { signal }),
  })

  // تایید، وضعیتِ ارسال، توست، ابطالِ کش و مدیریت خطا همه در همین هوک است
  const { call, isPending } = useApiAction()

  function changeRole(member: Member, role: string) {
    if (role === member.role) return

    const roleLabel = data?.roles.find((r) => r.value === role)?.label ?? role

    void call(
      `/system/members/${member.id}`,
      { method: 'PATCH', body: { role, is_active: member.isActive } },
      {
        key: member.id,
        confirm: {
          title: 'تغییر نقشِ کاربر',
          text: `نقشِ «${member.name || member.phone}» به «${roleLabel}» تغییر کند؟`,
          confirmLabel: 'بله، تغییر بده',
          danger: role === 'super_admin',
        },
        success: 'نقشِ کاربر به‌روزرسانی شد.',
        errorFallback: 'تغییر نقش ممکن نشد.',
        invalidate: [queryKeys.members.all()],
      },
    )
  }

  function removeMember(member: Member) {
    void call(
      `/system/members/${member.id}`,
      { method: 'DELETE' },
      {
        key: member.id,
        confirm: {
          title: 'حذف کاربر',
          text: `کاربر «${member.name || member.phone}» برای همیشه حذف شود؟`,
          confirmLabel: 'حذف',
          danger: true,
        },
        success: 'کاربر حذف شد.',
        errorFallback: 'حذف کاربر ممکن نشد.',
        invalidate: [queryKeys.members.all()],
      },
    )
  }

  function toggleActive(member: Member) {
    void call(
      `/system/members/${member.id}`,
      { method: 'PATCH', body: { role: member.role, is_active: !member.isActive } },
      {
        key: member.id,
        success: member.isActive ? 'حساب غیرفعال شد.' : 'حساب فعال شد.',
        errorFallback: 'تغییرِ وضعیت ممکن نشد.',
        invalidate: [queryKeys.members.all()],
      },
    )
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
      {error && <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />}

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
                    <span
                      className="text-[14px] font-bold"
                      style={{ color: 'var(--text-primary)' }}
                    >
                      {member.name || '—'}
                    </span>
                    {member.role === 'super_admin' && (
                      <ShieldCheck size={14} style={{ color: 'var(--color-brand-500)' }} />
                    )}
                  </div>
                  <div
                    className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px]"
                    style={{ color: 'var(--text-tertiary)' }}
                  >
                    <span dir="ltr">{member.phone}</span>
                    <span>مجتمع: {member.complex?.name ?? '—'}</span>
                    <span>ثبت‌نام: {member.registeredAt}</span>
                  </div>
                </div>

                <div className="flex items-center gap-2.5">
                  <label
                    className="flex items-center gap-1.5 text-[12px]"
                    style={{ color: 'var(--text-secondary)' }}
                  >
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded"
                      checked={member.isActive}
                      disabled={isPending(member.id)}
                      onChange={() => toggleActive(member)}
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
                      disabled={isPending(member.id)}
                      onChange={(e) => changeRole(member, e.target.value)}
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
                    onClick={() => removeMember(member)}
                    disabled={isPending(member.id)}
                    aria-label="حذف کاربر"
                    className="flex h-8 w-8 items-center justify-center rounded-lg border transition-colors hover:bg-(--surface-sunken) disabled:opacity-50"
                    style={{ borderColor: 'var(--border-subtle)', color: 'var(--color-danger)' }}
                  >
                    <Trash2 size={14} />
                  </button>

                  {isPending(member.id) && (
                    <Loader2
                      size={15}
                      className="animate-spin"
                      style={{ color: 'var(--color-brand-500)' }}
                    />
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
