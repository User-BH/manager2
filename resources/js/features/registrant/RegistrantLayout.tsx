import { Suspense } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { LogoMark } from '@/shared/common/LogoMark'
import { BRAND_NAME } from '@/shared/config/brand'
import { NotificationBell } from '@/shared/layout/NotificationBell'
import { ThemeToggle } from '@/shared/layout/ThemeToggle'
import { UserMenu } from '@/shared/layout/UserMenu'
import { reportError } from '@/shared/lib/observability'
import { ErrorBoundary } from '@/shared/ui/ErrorBoundary'
import { InlineSpinner } from '@/shared/ui/PageState'
import { REGISTRANT_NAV } from './navigation'

/**
 * پوسته‌ی تجربه‌ی «ثبت‌نام‌کننده‌ی اولیه» (مرحلهٔ ۸ · فاز ۱).
 *
 * سایدبارِ ساکن و مدیر را ندارد — چیزی برای مدیریت نیست — و جست‌وجو و
 * ماشین‌حساب هم نه (هر دو روی داده‌ی مجتمع کار می‌کنند). یک سرصفحه با نشان،
 * اعلان، تم و منوی کاربر، و زیرش چهار مقصدِ همین حالت.
 *
 * Suspense و ErrorBoundary به همان دلیلِ `DashboardLayout`: کرشِ یک صفحه
 * پوسته را نمی‌اندازد و `resetKey` با رفتن به صفحه‌ی دیگر خطا را پاک می‌کند.
 */
export function RegistrantLayout() {
  const { pathname } = useLocation()

  return (
    <div
      className="flex min-h-screen flex-col"
      dir="rtl"
      style={{ backgroundColor: 'var(--surface-canvas)' }}
      data-experience="registrant"
    >
      <header
        className="sticky top-0 z-20 border-b backdrop-blur"
        style={{
          backgroundColor: 'color-mix(in srgb, var(--surface-base) 92%, transparent)',
          borderColor: 'var(--border-subtle)',
        }}
      >
        <div className="mx-auto flex h-16 max-w-3xl items-center gap-3 px-4">
          <div className="flex flex-1 items-center gap-2.5">
            <LogoMark size={32} className="shrink-0" />
            <div className="leading-tight">
              <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                {BRAND_NAME}
              </p>
              <p className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
                حساب ثبت‌شده · بدون مجتمع
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <NotificationBell />
            <ThemeToggle />
            <div className="mx-1 h-7 w-px" style={{ backgroundColor: 'var(--border-subtle)' }} />
            <UserMenu />
          </div>
        </div>

        <nav aria-label="منوی حساب" className="mx-auto max-w-3xl px-2">
          <ul className="scrollbar-thin flex gap-1 overflow-x-auto">
            {REGISTRANT_NAV.map(({ to, label, icon: Icon }) => (
              <li key={to} className="shrink-0">
                <NavLink
                  to={to}
                  className="flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-[13px] font-semibold transition-colors"
                  style={({ isActive }) => ({
                    borderColor: isActive ? 'var(--color-brand-500)' : 'transparent',
                    color: isActive ? 'var(--color-brand-600)' : 'var(--text-secondary)',
                  })}
                >
                  {/* زیرِ ‎۴۲۰px چهار زبانه با آیکون جا نمی‌شوند (‎۴۰۰px در ‎۳۵۹px) و آخری
                      پشتِ اسکرول پنهان می‌ماند؛ آن‌جا فقط متن */}
                  <Icon size={16} aria-hidden="true" className="hidden min-[420px]:block" />
                  {label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
      </header>

      <main id="main-content" tabIndex={-1} className="flex-1 p-4 sm:p-6">
        <ErrorBoundary resetKey={pathname} onError={reportError}>
          <Suspense fallback={<InlineSpinner />}>
            <Outlet />
          </Suspense>
        </ErrorBoundary>
      </main>
    </div>
  )
}
