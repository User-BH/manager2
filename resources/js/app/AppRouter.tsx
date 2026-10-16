import { Suspense, lazy, useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { trackPageView } from '@/shared/lib/observability'
import { useAuth } from '@/shared/stores/authStore'
import { DashboardRouter, dashboardFor } from './dashboardRoutes'

/*
 * صفحه‌های عمومی (خانه/دمو/پشتیبانی/ورود) اینجا نیستند: هرکدام یک سندِ MPAِ مستقل‌اند که لاراول مستقیم سرو می‌کند.
 * این روتر فقط داشبوردِ SPA را برمی‌گزیند؛ مسیرها، صفحه‌ها و سه روترِ داشبورد در `dashboardRoutes.tsx` است.
 */
/*
 * تجربه‌ی جدای «ثبت‌نام‌کننده‌ی اولیه» (مرحلهٔ ۸ · فاز ۱) — روتر، پوسته و
 * داشبوردِ خودش؛ فقط برای همان حساب‌ها بار می‌شود.
 */
const RegistrantRouter = lazy(() =>
  import('./RegistrantRouter').then((m) => ({
    default: m.RegistrantRouter,
  })),
)

/**
 * با هر تغییرِ مسیر، صفحه از بالا شروع می‌شود.
 *
 * react-router موقعیتِ اسکرول را نگه می‌دارد؛ برای همین رفتن از ته صفحه‌ی دمو
 * به «/» کاربر را وسطِ صفحه‌ی اصلی (نزدیک گالری) می‌انداخت، نه هدر. تنها
 * استثنا صفحه‌هایی است که با `?topic=` به بخشی دیپ‌لینک می‌شوند (پشتیبانی)،
 * که خودشان به همان بخش اسکرول می‌کنند و نباید بازنویسی شوند.
 */
function ScrollToTop() {
  const { pathname, search } = useLocation()

  useEffect(() => {
    if (search.includes('topic=')) return
    window.scrollTo(0, 0)
  }, [pathname, search])

  return null
}

/**
 * ثبتِ بازدیدِ صفحه در SPA.
 *
 * GA4 فقط بارگذاریِ اولِ سند را می‌بیند و ناوبریِ داخلیِ روتر را نمی‌فهمد؛
 * بدونِ این کامپوننت، کلِ داشبورد یک بازدید شمرده می‌شد. عنوانِ سند با یک
 * تأخیرِ کوتاه خوانده می‌شود چون `useDocumentTitle` صفحه‌ها آن را در افکتِ
 * خودشان ست می‌کنند و در همین لحظه هنوز عنوانِ قبلی است.
 */
function TrackPageViews() {
  const { pathname } = useLocation()

  useEffect(() => {
    const timer = window.setTimeout(() => trackPageView(pathname), 60)
    return () => window.clearTimeout(timer)
  }, [pathname])

  return null
}

export function AppRouter() {
  const { user } = useAuth()

  /*
   * چهار تجربه، چهار روتر (مرحلهٔ ۸ · فاز ۱؛ و برای سه داشبوردِ عضو، مرحلهٔ ۱۰ · فاز ۱۶): حسابی که به هیچ مجتمعی
   * وصل نیست اصلاً مسیرهای داشبوردها را ندارد، و هر داشبورد فقط مسیرهای خودش را. حالت و نقش از سرور می‌آیند
   * (`accountState`، و نقشِ زمینه‌ی فعال) و قفلِ نوشتن هم همان‌جاست.
   */
  if (user?.dashboard === 'initial' || user?.accountState === 'initial') {
    return (
      <Suspense fallback={<RouteFallback />}>
        <ScrollToTop />
        <TrackPageViews />
        <RegistrantRouter />
      </Suspense>
    )
  }

  return (
    <Suspense fallback={<RouteFallback />}>
      <ScrollToTop />
      <TrackPageViews />
      <DashboardRouter dashboard={dashboardFor(user?.role, user?.dashboard)} />
    </Suspense>
  )
}

/** حالت انتظار هنگام دانلود چانک یک صفحه. */
function RouteFallback() {
  return (
    <div
      className="flex min-h-screen items-center justify-center"
      style={{ backgroundColor: 'var(--surface-canvas)' }}
    >
      <Loader2 size={28} className="animate-spin" style={{ color: 'var(--color-brand-500)' }} />
    </div>
  )
}
