import { Outlet } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Header } from './Header'

/**
 * پوسته‌ی ثابت داشبورد.
 *
 * هدر (با باکس جستجو، ماشین حساب، اعلان‌ها) و سایدبار سمت راست بیرون از
 * <Outlet /> هستند، پس با جابه‌جایی بین صفحه‌ها اصلاً unmount نمی‌شوند و فقط
 * وسط صفحه عوض می‌شود. حالتِ سایدبار/جستجو/اعلان حالا store‌های zustand‌اند
 * (بدون Provider)؛ نظرسنجیِ اعلان‌ها هم فقط وقتی همین پوسته mount است زنده می‌ماند.
 */
export function DashboardLayout() {
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
          <Outlet />
        </main>
      </div>
    </div>
  )
}
