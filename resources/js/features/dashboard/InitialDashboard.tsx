import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Building2, Check, PlayCircle, ShieldAlert, UserPlus, X } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useAction } from '@/shared/hooks/useAction'
import { useAuth } from '@/shared/stores/authStore'
import type { Invitation } from '@/shared/types'
import { JoinByManagerPhone } from './JoinByManagerPhone'

/**
 * داشبوردِ «حالتِ اولیه» — پنجمین حالتِ داشبورد (R21).
 *
 * مخاطبش کسی است که خودش ثبت‌نام کرده ولی هنوز نه خریده و نه مدیری اضافه‌اش
 * کرده. پیش از R21 چنین کسی اصلاً نمی‌توانست وارد شود و در بن‌بست می‌ماند.
 *
 * کلِ این صفحه یک کار دارد: **به او بگوید دقیقاً چه کند.** دو راه بیشتر
 * ندارد و هر دو اینجا صریح‌اند — دعوتِ مدیر را بپذیرد، یا خودش مجتمع بسازد.
 */
export function InitialDashboard() {
  const { user } = useAuth()

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-5">
      <DemoBanner />
      <InvitationList />
      <JoinByManagerPhone />
      <NextSteps />
      <DemoVideo />
      {user?.role === 'tenant' || user?.role === 'owner' ? <RoleHint /> : null}
    </div>
  )
}

/* ── بنرِ «نسخه‌ی نمایشی» ─────────────────────────────────────────────────── */

function DemoBanner() {
  return (
    <div
      className="flex items-start gap-3 rounded-2xl border p-4"
      style={{
        borderColor: 'var(--color-amber-400, #fbbf24)',
        backgroundColor: 'var(--surface-sunken)',
      }}
      role="status"
    >
      <ShieldAlert size={20} className="mt-0.5 shrink-0" style={{ color: '#d97706' }} />
      <div>
        <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
          نسخه‌ی نمایشی
        </p>
        <p className="mt-1 text-[13px] leading-6" style={{ color: 'var(--text-secondary)' }}>
          حساب شما ساخته شده ولی هنوز به مجتمعی وصل نیست. تا آن زمان همه‌چیز فقط‌خواندنی است و
          تغییری ذخیره نمی‌شود.
        </p>
      </div>
    </div>
  )
}

/* ── دعوت‌ها ─────────────────────────────────────────────────────────────── */

function InvitationList() {
  const { run, pendingKey } = useAction()

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.invitations.all(),
    queryFn: () => api<{ data: Invitation[] }>('/invitations'),
  })

  const accept = (id: number) =>
    run(() => api(`/invitations/${id}/accept`, { method: 'POST' }), {
      key: id,
      onDone: () => {
        /*
         * پذیرش، حالتِ حساب را عوض می‌کند و کلِ ناوبری باید از نو ساخته شود.
         * بارگذاریِ کاملِ صفحه ساده‌ترین راهِ درست است: هر حالتِ محلی که روی
         * «حالتِ اولیه» بنا شده هم پاک می‌شود.
         */
        window.location.assign('/dashboard')
      },
    })

  const decline = (id: number) =>
    run(() => api(`/invitations/${id}/decline`, { method: 'POST' }), {
      key: id,
      success: 'دعوت رد شد.',
      invalidate: [queryKeys.invitations.all()],
    })

  if (isLoading || !data?.data.length) return null

  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
        دعوت‌های شما
      </h2>

      {data.data.map((invitation) => (
        <div
          key={invitation.id}
          className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-4"
          style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-base)' }}
        >
          <div className="flex items-start gap-3">
            <Building2
              size={18}
              className="mt-0.5 shrink-0"
              style={{ color: 'var(--color-brand-500)' }}
            />
            <div>
              <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                {invitation.complexName}
              </p>
              <p className="mt-0.5 text-xs" style={{ color: 'var(--text-tertiary)' }}>
                {[invitation.unitLabel, `به عنوان ${invitation.roleLabel}`, invitation.invitedBy]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
            </div>
          </div>

          <div className="flex gap-2">
            <button
              onClick={() => void accept(invitation.id)}
              disabled={pendingKey !== null}
              className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold text-white disabled:opacity-60"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              <Check size={14} />
              پیوستن
            </button>
            <button
              onClick={() => void decline(invitation.id)}
              disabled={pendingKey !== null}
              className="flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
            >
              <X size={14} />
              رد
            </button>
          </div>
        </div>
      ))}
    </section>
  )
}

/* ── دو راهِ پیشِ رو ─────────────────────────────────────────────────────── */

function NextSteps() {
  return (
    <section className="grid gap-4 sm:grid-cols-2">
      <StepCard
        icon={<UserPlus size={18} />}
        title="مدیر مجتمع شما را اضافه کند"
        body="شماره‌ی موبایلتان را به مدیر ساختمان بدهید. پس از افزودن، دعوتش همین‌جا نمایش داده می‌شود و با یک کلیک عضو می‌شوید."
      />
      <StepCard
        icon={<Building2 size={18} />}
        title="خودتان مجتمع بسازید"
        body="اگر مدیر ساختمان هستید، با تهیه‌ی اشتراک مجتمع شما ساخته می‌شود و بلافاصله مدیر آن خواهید بود."
        action={{ label: 'مشاهده‌ی پکیج‌ها', href: '/account' }}
      />
    </section>
  )
}

function StepCard({
  icon,
  title,
  body,
  action,
}: {
  icon: ReactNode
  title: string
  body: string
  action?: { label: string; href: string }
}) {
  return (
    <div
      className="flex flex-col gap-2 rounded-2xl border p-4"
      style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-base)' }}
    >
      <span style={{ color: 'var(--color-brand-500)' }}>{icon}</span>
      <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
        {title}
      </p>
      <p className="text-[13px] leading-6" style={{ color: 'var(--text-secondary)' }}>
        {body}
      </p>
      {action && (
        <a
          href={action.href}
          className="mt-1 inline-flex items-center gap-1.5 text-xs font-bold"
          style={{ color: 'var(--color-brand-500)' }}
        >
          {action.label}
          <ArrowLeft size={14} />
        </a>
      )}
    </div>
  )
}

/* ── ویدیوی دمو ─────────────────────────────────────────────────────────── */

/**
 * راهِ دیدنِ دمو.
 *
 * ─── چرا `<video>` درون‌خطی نیست ───────────────────────────────────────────
 * فایلِ `public/videos/demo.mp4` هنوز تحویل نشده و طبق تصمیمِ کارفرما به بعد
 * موکول شده. گذاشتنِ تگِ `<video>` که منبعش وجود ندارد یعنی جعبه‌ی خرابِ
 * پخش‌کننده؛ و افزودنِ `<track>`ِ زیرنویسی که آن هم وجود ندارد، فقط برای
 * ساکت‌کردنِ قاعده‌ی دسترس‌پذیری، خود را گول‌زدن است.
 *
 * پس فعلاً لینکِ صفحه‌ی دمو — که واقعاً وجود دارد. وقتی ویدیو رسید همین‌جا
 * جاسازی می‌شود، همراه با فایلِ زیرنویس.
 */
function DemoVideo() {
  return (
    <a
      href="/demo"
      className="flex items-center justify-center gap-2 rounded-2xl border border-dashed p-5 text-sm font-semibold transition-colors hover:bg-(--surface-sunken)"
      style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
    >
      <PlayCircle size={18} />
      ببینید سامانه چطور کار می‌کند
    </a>
  )
}

/* ── راهنمای نقشِ مالک/مستأجر ───────────────────────────────────────────── */

function RoleHint() {
  return (
    <p
      className="rounded-2xl border border-dashed p-4 text-[13px] leading-6"
      style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-tertiary)' }}
    >
      اگر مالک یا مستاجر یکی از واحدها هستید، لازم نیست اشتراک بخرید؛ فقط کافی است مدیر ساختمان شما
      را به مجتمع اضافه کند.
    </p>
  )
}
