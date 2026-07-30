import { Suspense } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Header } from './Header'
import { ErrorBoundary } from '@/shared/ui/ErrorBoundary'
import { InlineSpinner } from '@/shared/ui/PageState'

/**
 * پوسته‌ی ثابت داشبورد.
 *
 * هدر (با باکس جستجو، ماشین حساب، اعلان‌ها) و سایدبار سمت راست بیرون از
 * <Outlet /> هستند، پس با جابه‌جایی بین صفحه‌ها اصلاً unmount نمی‌شوند و فقط
 * وسط صفحه عوض می‌شود. حالتِ سایدبار/جستجو/اعلان حالا store‌های zustand‌اند
 * (بدون Provider)؛ نظرسنجیِ اعلان‌ها هم فقط وقتی همین پوسته mount است زنده می‌ماند.
 */
export function DashboardLayout() {
  const { pathname } = useLocation()

  return (
    <div
      className="flex h-screen overflow-hidden"
      dir="rtl"
      style={{ backgroundColor: 'var(--surface-canvas)' }}
    >
      <Sidebar />

      <div className="flex min-w-0 flex-1 flex-col">
        <Header />

        <main className="scrollbar-thin flex-1 overflow-y-auto p-4 sm:p-6">
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
          <ErrorBoundary resetKey={pathname}>
            <Suspense fallback={<InlineSpinner />}>
              <Outlet />
            </Suspense>
          </ErrorBoundary>
        </main>
      </div>
    </div>
  )
}
