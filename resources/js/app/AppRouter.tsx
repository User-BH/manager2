import { Suspense, lazy } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { DashboardLayout } from '@/components/layout/DashboardLayout'
import { HomePage } from '@/pages/home/HomePage'
import { AuthPage } from '@/pages/auth/AuthPage'
import { ProtectedRoute } from './ProtectedRoute'
import type { UserRole } from '@/types'

/*
 * هر صفحه‌ی داشبورد چانک جدا می‌شود تا بازدیدکننده‌ی صفحه‌ی اصلی
 * مجبور نباشد Recharts و کل صفحات مدیریتی را هم دانلود کند.
 * صفحه‌ی اصلی و ورود عمداً eager می‌مانند چون اولین چیزی هستند که
 * دیده می‌شوند و نباید پشت یک چانک دوم منتظر بمانند.
 */
const ForbiddenPage = lazy(() => import('@/pages/error/ForbiddenPage').then((m) => ({ default: m.ForbiddenPage })))
const DashboardPage = lazy(() => import('@/pages/dashboard/DashboardPage').then((m) => ({ default: m.DashboardPage })))
const UnitsPage = lazy(() => import('@/pages/units/UnitsPage').then((m) => ({ default: m.UnitsPage })))
const ResidentsPage = lazy(() => import('@/pages/residents/ResidentsPage').then((m) => ({ default: m.ResidentsPage })))
const BillsPage = lazy(() => import('@/pages/bills/BillsPage').then((m) => ({ default: m.BillsPage })))
const MessengerPage = lazy(() => import('@/pages/messenger/MessengerPage').then((m) => ({ default: m.MessengerPage })))
const AnnouncementsPage = lazy(() => import('@/pages/announcements/AnnouncementsPage').then((m) => ({ default: m.AnnouncementsPage })))
const PayBillPage = lazy(() => import('@/pages/pay/PayBillPage').then((m) => ({ default: m.PayBillPage })))
const MyBillsPage = lazy(() => import('@/pages/my-bills/MyBillsPage').then((m) => ({ default: m.MyBillsPage })))
const GoodPayersPage = lazy(() => import('@/pages/good-payers/GoodPayersPage').then((m) => ({ default: m.GoodPayersPage })))
const ComplexSettingsPage = lazy(() => import('@/pages/settings/ComplexSettingsPage').then((m) => ({ default: m.ComplexSettingsPage })))
const ComplexBackupPage = lazy(() => import('@/pages/settings/ComplexBackupPage').then((m) => ({ default: m.ComplexBackupPage })))
const ComplexesPage = lazy(() => import('@/pages/system/ComplexesPage').then((m) => ({ default: m.ComplexesPage })))
const SmsPage = lazy(() => import('@/pages/system/SmsPage').then((m) => ({ default: m.SmsPage })))
const SystemBackupPage = lazy(() => import('@/pages/system/SystemBackupPage').then((m) => ({ default: m.SystemBackupPage })))
const ManagersPage = lazy(() => import('@/pages/managers/ManagersPage').then((m) => ({ default: m.ManagersPage })))
const ChargeRulesPage = lazy(() => import('@/pages/charge-rules/ChargeRulesPage').then((m) => ({ default: m.ChargeRulesPage })))
const FinancePage = lazy(() => import('@/pages/finance/FinancePage').then((m) => ({ default: m.FinancePage })))
const PaymentReviewPage = lazy(() => import('@/pages/payments/PaymentReviewPage').then((m) => ({ default: m.PaymentReviewPage })))
const DiscountsPage = lazy(() => import('@/pages/discounts/DiscountsPage').then((m) => ({ default: m.DiscountsPage })))
const SearchResultsPage = lazy(() => import('@/pages/search/SearchResultsPage').then((m) => ({ default: m.SearchResultsPage })))
const CalculatorPage = lazy(() => import('@/pages/calculator/CalculatorPage').then((m) => ({ default: m.CalculatorPage })))
const ProfilePage = lazy(() => import('@/pages/profile/ProfilePage').then((m) => ({ default: m.ProfilePage })))
const AccountPage = lazy(() => import('@/pages/account/AccountPage').then((m) => ({ default: m.AccountPage })))

const ADMINS: UserRole[] = ['super_admin', 'complex_admin']
const SUPER: UserRole[] = ['super_admin']

export function AppRouter() {
  return (
    <Suspense fallback={<RouteFallback />}>
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
            <Route path="/pay/:billId" element={<PayBillPage />} />
            <Route path="/top-residents" element={<GoodPayersPage />} />

            {/* این چهار صفحه هم داخل همین layout هستند، پس هدر و سایدبار
                هنگام رفتن به آن‌ها حذف نمی‌شوند و فقط وسط صفحه عوض می‌شود. */}
            <Route path="/search" element={<SearchResultsPage />} />
            <Route path="/calculator" element={<CalculatorPage />} />
            <Route path="/profile" element={<ProfilePage />} />
            <Route path="/account" element={<AccountPage />} />
          </Route>
        </Route>

        {/* --- مدیریت مجتمع --- */}
        <Route element={<ProtectedRoute roles={ADMINS} />}>
          <Route element={<DashboardLayout />}>
            <Route path="/units" element={<UnitsPage />} />
            <Route path="/residents" element={<ResidentsPage />} />
            <Route path="/bills" element={<BillsPage />} />

            <Route path="/managers" element={<ManagersPage />} />
            <Route path="/charge-rules" element={<ChargeRulesPage />} />
            <Route path="/finance" element={<FinancePage />} />
            <Route path="/payments" element={<PaymentReviewPage />} />
            <Route path="/discounts" element={<DiscountsPage />} />
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
