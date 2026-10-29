import { Suspense, useEffect } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { ActiveCommunityBar } from './ActiveCommunityBar'
import { Sidebar } from './Sidebar'
import { Header } from './Header'
import { ErrorBoundary } from '@/shared/ui/ErrorBoundary'
import { reportError } from '@/shared/lib/observability'
import { InlineSpinner } from '@/shared/ui/PageState'
import { registerPresenceBeacon } from '@/shared/lib/presenceBeacon'
import type { NavSection } from '@/shared/types'

interface DashboardLayoutProps {
  /** منوی سایدبار؛ بی آن، منوی نقش (`navigation.ts`) */
  sections?: NavSection[]
  navLabel?: string
  /** نشانِ پوسته برای آزمون‌ها و CSS (`data-experience`) */
  experience?: string
  /** «آفلاین شدم» هنگامِ بستن — فقط داشبوردهای عضو؛ داشبوردِ اولیه پیام‌رسان و حضور ندارد */
  presence?: boolean
}

/**
 * پوسته‌ی ثابت داشبورد.
 *
 * هدر (با باکس جستجو، ماشین حساب، اعلان‌ها) و سایدبار سمت راست بیرون از
 * <Outlet /> هستند، پس با جابه‌جایی بین صفحه‌ها اصلاً unmount نمی‌شوند و فقط
 * وسط صفحه عوض می‌شود. حالتِ سایدبار/جستجو/اعلان حالا store‌های zustand‌اند
 * (بدون Provider)؛ نظرسنجیِ اعلان‌ها هم فقط وقتی همین پوسته mount است زنده می‌ماند.
 *
 * هر چهار داشبورد همین پوسته‌اند (۲۰۲۶-۱۰-۲۹، بندِ ۷): داشبوردِ اولیه فقط منوی خودش را می‌دهد (`RegistrantLayout`).
 */
export function DashboardLayout({
  sections,
  navLabel,
  experience,
  presence = true,
}: DashboardLayoutProps = {}) {
  const { pathname } = useLocation()
  // بستنِ داشبورد همان لحظه «آفلاین» است، نه ۹۰ ثانیه بعد («چند نکته»، بندِ ۳)
  useEffect(() => (presence ? registerPresenceBeacon() : undefined), [presence])

  return (
    <div
      className="flex h-screen overflow-hidden"
      dir="rtl"
      style={{ backgroundColor: 'var(--surface-canvas)' }}
      data-experience={experience}
    >
      <Sidebar sections={sections} navLabel={navLabel} />

      <div className="flex min-w-0 flex-1 flex-col">
        {/* نامِ مجتمعِ فعال **بالای** نوارِ اصلی (۱۳/۳، بندِ ۴۳) */}
        <ActiveCommunityBar />
        <Header />

        <main
          id="main-content"
          /*
           * `tabIndex={-1}` لازم است: بدونش پرشِ `#main-content` فقط اسکرول
           * می‌کند و فوکوس همان بالا می‌ماند، پس Tabِ بعدی دوباره به منو
           * برمی‌گردد و کلِ پیوند بی‌اثر می‌شود.
           */
          tabIndex={-1}
          className="scrollbar-thin flex-1 overflow-y-auto p-4 sm:p-6"
        >
          {/*
            ── چرا Suspense و ErrorBoundary اینجا و نه فقط در ریشه؟ ──────────
            پیش از این تنها یک Suspense در ریشه بود؛ یعنی رفتن به هر صفحه‌ی
            تازه، تا دانلودِ چانکش، **کلِ پوسته را** با یک اسپینرِ تمام‌صفحه
            جایگزین می‌کرد — سایدبار و هدر هم می‌پریدند. حالا فقط همین ناحیه‌ی
            محتوا عوض می‌شود و پوسته سرِ جایش می‌ماند.

            همین منطق برای خطا هم هست: کرشِ یک صفحه نباید کاربر را از داشبورد
            بیرون بیندازد. با این چیدمان، سایدبار و هدر سالم می‌مانند و کاربر
            می‌تواند به صفحه‌ی دیگری برود.

            `resetKey={pathname}` اجباری است: بدونش کاربر پس از یک خطا هر جا
            برود همان صفحه‌ی خطا را می‌بیند، چون boundary در حالتِ خطا گیر کرده.
          */}
          <ErrorBoundary resetKey={pathname} onError={reportError}>
            <Suspense fallback={<InlineSpinner />}>
              <Outlet />
            </Suspense>
          </ErrorBoundary>
        </main>
      </div>
    </div>
  )
}
