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
import { MessengerPage } from '@/pages/messenger/MessengerPage'
import { AnnouncementsPage } from '@/pages/announcements/AnnouncementsPage'
import { MyBillsPage } from '@/pages/my-bills/MyBillsPage'
import { GoodPayersPage } from '@/pages/good-payers/GoodPayersPage'
import { ComplexSettingsPage } from '@/pages/settings/ComplexSettingsPage'
import { ComplexBackupPage } from '@/pages/settings/ComplexBackupPage'
import { ComplexesPage } from '@/pages/system/ComplexesPage'
import { SmsPage } from '@/pages/system/SmsPage'
import { SystemBackupPage } from '@/pages/system/SystemBackupPage'
import { ProtectedRoute } from './ProtectedRoute'
import type { UserRole } from '@/types'

const ADMINS: UserRole[] = ['super_admin', 'complex_admin']
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
          <Route path="/announcements" element={<AnnouncementsPage />} />
          <Route path="/messenger" element={<MessengerPage />} />

          {/* صورت‌حساب‌ها زیر روت مشترک است چون مدیر هم واحد شخصی دارد و
              باید بتواند قبوض خودش را ببیند، نه فقط ساکنین. */}
          <Route path="/my-bills" element={<MyBillsPage />} />
          <Route path="/top-residents" element={<GoodPayersPage />} />
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
          <Route path="/settings/complex" element={<ComplexSettingsPage />} />
          <Route path="/settings/backup" element={<ComplexBackupPage />} />
        </Route>
      </Route>

      {/* --- ادمین کل سیستم --- */}
      <Route element={<ProtectedRoute roles={SUPER} />}>
        <Route element={<DashboardLayout />}>
          <Route path="/system/complexes" element={<ComplexesPage />} />
          <Route path="/system/sms" element={<SmsPage />} />
          <Route path="/system/backup" element={<SystemBackupPage />} />
        </Route>
      </Route>

      <Route path="/home" element={<Navigate to="/" replace />} />

      {/* مسیر نامعتبر */}
      <Route path="*" element={<ForbiddenPage />} />
    </Routes>
  )
}
