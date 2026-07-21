import { Outlet } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Header } from './Header'
import { SidebarProvider } from '@/context/SidebarContext'
import { SearchProvider } from '@/context/SearchContext'
import { NotificationProvider } from '@/context/NotificationContext'

/**
 * پوسته‌ی ثابت داشبورد.
 *
 * هدر (با باکس جستجو، ماشین حساب، اعلان‌ها) و سایدبار سمت راست بیرون از
 * <Outlet /> هستند، پس با جابه‌جایی بین صفحه‌ها اصلاً unmount نمی‌شوند و فقط
 * وسط صفحه عوض می‌شود. پرووایدرهای جستجو و اعلان هم اینجا نشسته‌اند تا با هر
 * تغییر مسیر حالتشان از دست نرود و فقط برای کاربر واردشده ساخته شوند.
 */
export function DashboardLayout() {
  return (
    <SidebarProvider>
      <SearchProvider>
        <NotificationProvider>
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
        </NotificationProvider>
      </SearchProvider>
    </SidebarProvider>
  )
}
