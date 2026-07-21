import { Navigate, Route, Routes } from 'react-router-dom'
import { DashboardLayout } from '@/components/layout/DashboardLayout'
import { PlaceholderPage } from '@/components/common/PlaceholderPage'
import { HomePage } from '@/pages/home/HomePage'
import { AuthPage } from '@/pages/auth/AuthPage'
import { ForbiddenPage } from '@/pages/error/ForbiddenPage'
import { DashboardPage } from '@/pages/dashboard/DashboardPage'
import { UnitsPage } from '@/pages/units/UnitsPage'
import { ResidentsPage } from '@/pages/residents/ResidentsPage'
import { BillsPage } from '@/pages/bills/BillsPage'
import { ProtectedRoute } from './ProtectedRoute'
import type { UserRole } from '@/types'

const ADMINS: UserRole[] = ['super_admin', 'complex_admin']
const RESIDENTS: UserRole[] = ['owner', 'tenant']
const SUPER: UserRole[] = ['super_admin']

export function AppRouter() {
  return (
    <Routes>
      {/* --- عمومی --- */}
      <Route path="/" element={<HomePage />} />
      <Route path="/auth" element={<AuthPage />} />
      <Route path="/forbidden" element={<ForbiddenPage />} />

      {/* --- مشترک بین همه‌ی نقش‌های واردشده --- */}
      <Route element={<ProtectedRoute />}>
        <Route element={<DashboardLayout />}>
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/announcements" element={<PlaceholderPage title="اطلاعیه‌ها" />} />
          <Route path="/messenger" element={<PlaceholderPage title="پیام‌رسان" />} />
          <Route path="/top-residents" element={<PlaceholderPage title="ساکنین خوش‌حساب" />} />
        </Route>
      </Route>

      {/* --- مدیریت مجتمع --- */}
      <Route element={<ProtectedRoute roles={ADMINS} />}>
        <Route element={<DashboardLayout />}>
          <Route path="/units" element={<UnitsPage />} />
          <Route path="/residents" element={<ResidentsPage />} />
          <Route path="/bills" element={<BillsPage />} />

          <Route path="/managers" element={<PlaceholderPage title="مدیران مجتمع" />} />
          <Route path="/charge-rules" element={<PlaceholderPage title="قوانین شارژ" />} />
          <Route path="/finance" element={<PlaceholderPage title="هزینه‌ها و درآمدها" />} />
          <Route path="/payments" element={<PlaceholderPage title="بررسی پرداخت‌ها" />} />
          <Route path="/discounts" element={<PlaceholderPage title="تخفیف و بخشودگی" />} />
          <Route path="/settings/complex" element={<PlaceholderPage title="تنظیمات مجتمع" />} />
          <Route path="/settings/backup" element={<PlaceholderPage title="بکاپ مجتمع" />} />
        </Route>
      </Route>

      {/* --- ساکن --- */}
      <Route element={<ProtectedRoute roles={RESIDENTS} />}>
        <Route element={<DashboardLayout />}>
          <Route path="/my-bills" element={<PlaceholderPage title="صورت‌حساب‌های من" />} />
        </Route>
      </Route>

      {/* --- ادمین کل سیستم --- */}
      <Route element={<ProtectedRoute roles={SUPER} />}>
        <Route element={<DashboardLayout />}>
          <Route path="/system/complexes" element={<PlaceholderPage title="مدیریت مجتمع‌ها" />} />
          <Route path="/system/sms" element={<PlaceholderPage title="پنل پیامک" />} />
          <Route path="/system/backup" element={<PlaceholderPage title="بکاپ کل سیستم" />} />
        </Route>
      </Route>

      <Route path="/home" element={<Navigate to="/" replace />} />

      {/* مسیر نامعتبر */}
      <Route path="*" element={<ForbiddenPage />} />
    </Routes>
  )
}
