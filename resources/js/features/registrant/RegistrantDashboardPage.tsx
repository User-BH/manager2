import { type ReactNode } from 'react'
import { ArrowLeft, Building2, PlayCircle, ShieldAlert, UserRound, UserPlus } from 'lucide-react'
import { useDocumentTitle } from '@/shared/hooks'
import { useCheckoutReturn } from '@/shared/hooks/useCheckoutReturn'
import { useAuth } from '@/shared/stores/authStore'
import { JoinByManagerPhone } from './JoinByManagerPhone'
import { RegistrantGuide } from './RegistrantGuide'
import { ReceivedInvitations } from '@/shared/ui/invitations/ReceivedInvitations'

/**
 * داشبوردِ «ثبت‌نام‌کننده‌ی اولیه» (R21؛ از مرحلهٔ ۸ · فاز ۱ تجربه‌ی جدا با
 * روتر و پوسته‌ی خودش — `RegistrantRouter`).
 *
 * مخاطبش کسی است که خودش ثبت‌نام کرده ولی هنوز نه خریده و نه مدیری اضافه‌اش
 * کرده. پیش از R21 چنین کسی اصلاً نمی‌توانست وارد شود و در بن‌بست می‌ماند.
 *
 * کلِ این صفحه یک کار دارد: **به او بگوید دقیقاً چه کند.** دو راه بیشتر
 * ندارد و هر دو اینجا صریح‌اند — دعوتِ مدیر را بپذیرد، یا خودش مجتمع بسازد.
 * بقیه‌ی جزئیات را فایلِ داشبوردِ کارفرما مشخص می‌کند؛ چیزی حدسی اضافه نشد.
 */
export function RegistrantDashboardPage() {
  const { user } = useAuth()
  useDocumentTitle('داشبورد')
  /*
   * بازگشت از خریدِ مجتمعِ تازه در «ایجادِ حسابِ جدید» (۱۳/۳): عضوِ مجتمع‌های دیگر این‌جا فرود می‌آید (بازگشتِ بانک
   * زمینه‌اش را جابه‌جا نمی‌کند) و همان هوکِ داشبوردِ مجتمع او را به مجتمعِ تازه می‌برد — سرور عضویت را می‌سنجد.
   */
  useCheckoutReturn()

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-5">
      <h1 className="text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
        {user?.name ? `${user.name}، خوش آمدید` : 'خوش آمدید'}
      </h1>
      <StatusBanner />
      {user?.needsName ? <CompleteName /> : null}
      <ReceivedInvitations />
      <JoinByManagerPhone />
      <NextSteps />
      <RegistrantGuide />
      <DemoVideo />
      {user?.role === 'tenant' || user?.role === 'owner' ? <RoleHint /> : null}
    </div>
  )
}

/* ── وضعیتِ حساب ─────────────────────────────────────────────────────────── */

/**
 * پیش از این عنوانش «نسخه‌ی نمایشی» بود — که این حساب نیست: حسابِ واقعی است
 * که هنوز به مجتمعی وصل نشده. همان را صریح می‌گوید (مرحلهٔ ۸ · فاز ۱).
 */
function StatusBanner() {
  /*
   * عضوِ معلق (۱۰/۶): حسابی که تنها عضویتش را مدیرِ همان مجتمع معلق کرده هم این‌جا می‌آید. «هنوز عضو هیچ
   * مجتمعی نیستید» برای او نادرست است؛ می‌گوییم کجا معلق است و با چه کسی صحبت کند.
   */
  const { user } = useAuth()
  const memberships = user?.memberships ?? []
  const suspended = memberships.filter((m) => m.status === 'suspended')
  const active = memberships.filter((m) => m.status === 'active')

  /*
   * «ایجادِ حسابِ جدید» (۱۳/۳): عضوی که با همین حساب به داشبوردِ اولیه آمده. «هنوز عضو هیچ مجتمعی نیستید» برای او
   * نادرست است؛ می‌گوییم کجا عضو است و راهِ بازگشت را — مجتمع‌هایش سرِ جایشان‌اند (ایرادات ۱۱، بندِ ۵.۸).
   */
  if (active.length > 0) {
    return (
      <div
        className="flex items-start gap-3 rounded-2xl border p-4"
        style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-base)' }}
        role="status"
      >
        <Building2
          size={20}
          className="mt-0.5 shrink-0"
          style={{ color: 'var(--color-brand-500)' }}
        />
        <div>
          <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
            مجتمعِ تازه با همین حساب
          </p>
          <p className="mt-1 text-body leading-6" style={{ color: 'var(--text-secondary)' }}>
            {`با همین حساب در ${active.map((m) => `«${m.complexName}»`).join('، ')} عضو هستید و همه‌چیزِ آن‌جا سرِ جایش است. از این‌جا می‌توانید به مجتمعِ دیگری بپیوندید، دعوتِ مدیری را ببینید یا مجتمعِ تازه بسازید؛ برای بازگشت، مجتمع را از منوی حساب (بالای صفحه) انتخاب کنید.`}
          </p>
        </div>
      </div>
    )
  }

  if (suspended.length > 0) {
    return (
      <div
        className="flex items-start gap-3 rounded-2xl border p-4"
        style={{
          borderColor: 'var(--color-amber-400, #fbbf24)',
          backgroundColor: 'var(--surface-sunken)',
        }}
        role="status"
      >
        <ShieldAlert
          size={20}
          className="mt-0.5 shrink-0"
          style={{ color: 'var(--state-warning)' }}
        />
        <div>
          <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
            عضویت شما معلق است
          </p>
          <p className="mt-1 text-[13px] leading-6" style={{ color: 'var(--text-secondary)' }}>
            {`عضویتِ شما در ${suspended.map((m) => `«${m.complexName}»`).join('، ')} از سوی مدیرِ همان مجتمع معلق شده است. حساب و سابقه‌تان سرِ جایش است؛ برای فعال شدنِ دوباره با مدیرِ مجتمع تماس بگیرید.`}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div
      className="flex items-start gap-3 rounded-2xl border p-4"
      style={{
        borderColor: 'var(--color-amber-400, #fbbf24)',
        backgroundColor: 'var(--surface-sunken)',
      }}
      role="status"
    >
      <ShieldAlert
        size={20}
        className="mt-0.5 shrink-0"
        style={{ color: 'var(--state-warning)' }}
      />
      <div>
        <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
          ثبت‌نام شما کامل است؛ هنوز عضو هیچ مجتمعی نیستید
        </p>
        <p className="mt-1 text-[13px] leading-6" style={{ color: 'var(--text-secondary)' }}>
          حساب شما ساخته شده ولی هنوز به هیچ مجتمع یا واحدی وصل نیست. وقتی مدیر ساختمان شما را به
          واحدتان اضافه کند یا خودتان مجتمع بسازید، داشبورد کامل برایتان باز می‌شود.
        </p>
      </div>
    </div>
  )
}

/* ── تکمیلِ نام ─────────────────────────────────────────────────────────── */

/**
 * حسابِ پیش از مرحلهٔ ۹ نامش یک رشته است و عمداً با حدس شکسته نشد؛ فقط از
 * خودِ کاربر خواسته می‌شود نام و نام خانوادگی را جدا ثبت کند (تصمیمِ ۱۲).
 */
function CompleteName() {
  return (
    <a
      href="/profile"
      className="flex items-center gap-3 rounded-2xl border p-4 text-[13px] leading-6"
      style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-base)' }}
    >
      <UserRound size={18} className="shrink-0" style={{ color: 'var(--color-brand-500)' }} />
      <span className="flex-1" style={{ color: 'var(--text-secondary)' }}>
        <span className="font-bold" style={{ color: 'var(--text-primary)' }}>
          نام و نام خانوادگی‌تان را جدا ثبت کنید.
        </span>{' '}
        تا آن موقع نامِ فعلیِ حسابتان همان‌طور که هست می‌ماند.
      </span>
      <ArrowLeft size={14} style={{ color: 'var(--color-brand-500)' }} />
    </a>
  )
}

/* ── دعوت‌ها ─────────────────────────────────────────────────────────────── */

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
