import { Suspense, lazy, useEffect } from 'react'
import { Route, Routes, useLocation } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { trackPageView } from '@/shared/lib/observability'
import { ProtectedRoute } from './ProtectedRoute'
import type { UserRole } from '@/shared/types'

/*
 * هر صفحه‌ی داشبورد چانک جدا می‌شود تا بازدیدکننده‌ی صفحه‌ی اصلی
 * مجبور نباشد Recharts و کل صفحات مدیریتی را هم دانلود کند.
 * صفحه‌ی اصلی عمداً eager می‌ماند چون اولین چیزی است که دیده می‌شود و
 * نباید پشت یک چانک دوم منتظر بماند.
 */
/*
 * صفحه‌ی ورود هم lazy است: کل پشته‌ی اعتبارسنجی فرم (react-hook-form + zod،
 * حدود ۱۰۰ کیلوبایت) فقط از اینجا می‌آید و بازدیدکننده‌ی صفحه‌ی فرود — که
 * هیچ فرمی نمی‌بیند — نباید بابتش هزینه بدهد.
 */
/*
 * پوسته‌ی داشبورد (نوار کناری، هدر، جستجو، اعلان‌ها، ماشین‌حساب) عمداً lazy است.
 *
 * صفحه‌های عمومی (خانه/دمو/پشتیبانی/ورود) دیگر اینجا نیستند: هرکدام یک سندِ
 * MPAِ مستقل‌اند که لاراول مستقیم سرو می‌کند. این روتر فقط برای داشبوردِ SPA
 * است، پس باندلِ داشبورد دیگر کدِ صفحه‌های عمومی را حمل نمی‌کند.
 */
const DashboardLayout = lazy(() =>
  import('@/shared/layout/DashboardLayout').then((m) => ({ default: m.DashboardLayout })),
)
const ForbiddenPage = lazy(() =>
  import('@/features/error/ForbiddenPage').then((m) => ({ default: m.ForbiddenPage })),
)
const DashboardPage = lazy(() =>
  import('@/features/dashboard/DashboardPage').then((m) => ({ default: m.DashboardPage })),
)
const UnitsPage = lazy(() =>
  import('@/features/units/UnitsPage').then((m) => ({ default: m.UnitsPage })),
)
const ResidentsPage = lazy(() =>
  import('@/features/residents/ResidentsPage').then((m) => ({ default: m.ResidentsPage })),
)
const BillsPage = lazy(() =>
  import('@/features/billing/bills/BillsPage').then((m) => ({ default: m.BillsPage })),
)
const MessengerPage = lazy(() =>
  import('@/features/messaging/messenger/MessengerPage').then((m) => ({
    default: m.MessengerPage,
  })),
)
const NotificationsPage = lazy(() =>
  import('@/features/notifications/NotificationsPage').then((m) => ({
    default: m.NotificationsPage,
  })),
)
const SmsCampaignPage = lazy(() =>
  import('@/features/notifications/SmsCampaignPage').then((m) => ({ default: m.SmsCampaignPage })),
)
const ServiceRequestsPage = lazy(() =>
  import('@/features/requests/ServiceRequestsPage').then((m) => ({
    default: m.ServiceRequestsPage,
  })),
)
const AnnouncementsPage = lazy(() =>
  import('@/features/messaging/announcements/AnnouncementsPage').then((m) => ({
    default: m.AnnouncementsPage,
  })),
)
const PayBillPage = lazy(() =>
  import('@/features/payments/pay/PayBillPage').then((m) => ({ default: m.PayBillPage })),
)
const MyBillsPage = lazy(() =>
  import('@/features/billing/my-bills/MyBillsPage').then((m) => ({ default: m.MyBillsPage })),
)
const WalletPage = lazy(() =>
  import('@/features/wallet/WalletPage').then((m) => ({ default: m.WalletPage })),
)
const GoodPayersPage = lazy(() =>
  import('@/features/finance/good-payers/GoodPayersPage').then((m) => ({
    default: m.GoodPayersPage,
  })),
)
const ComplexSettingsPage = lazy(() =>
  import('@/features/settings/ComplexSettingsPage').then((m) => ({
    default: m.ComplexSettingsPage,
  })),
)
const ComplexBackupPage = lazy(() =>
  import('@/features/settings/ComplexBackupPage').then((m) => ({ default: m.ComplexBackupPage })),
)
const ComplexesPage = lazy(() =>
  import('@/features/system/ComplexesPage').then((m) => ({ default: m.ComplexesPage })),
)
const SmsPage = lazy(() =>
  import('@/features/system/SmsPage').then((m) => ({ default: m.SmsPage })),
)
const SystemBackupPage = lazy(() =>
  import('@/features/system/SystemBackupPage').then((m) => ({ default: m.SystemBackupPage })),
)
const SystemSubscriptionsPage = lazy(() =>
  import('@/features/system/SubscriptionsPage').then((m) => ({ default: m.SubscriptionsPage })),
)
const AdvertisementsPage = lazy(() =>
  import('@/features/system/ads/AdvertisementsPage').then((m) => ({
    default: m.AdvertisementsPage,
  })),
)
const SiteSettingsPage = lazy(() =>
  import('@/features/system/SiteSettingsPage').then((m) => ({ default: m.SiteSettingsPage })),
)
const MembersPage = lazy(() =>
  import('@/features/system/MembersPage').then((m) => ({ default: m.MembersPage })),
)
const PlansPage = lazy(() =>
  import('@/features/system/PlansPage').then((m) => ({ default: m.PlansPage })),
)
const ObservabilityPage = lazy(() =>
  import('@/features/system/observability/ObservabilityPage').then((m) => ({
    default: m.ObservabilityPage,
  })),
)
const AuditLogPage = lazy(() =>
  import('@/features/system/AuditLogPage').then((m) => ({ default: m.AuditLogPage })),
)
const ManagersPage = lazy(() =>
  import('@/features/managers/ManagersPage').then((m) => ({ default: m.ManagersPage })),
)
const ChargeRulesPage = lazy(() =>
  import('@/features/billing/charge-rules/ChargeRulesPage').then((m) => ({
    default: m.ChargeRulesPage,
  })),
)
const FinancePage = lazy(() =>
  import('@/features/finance/FinancePage').then((m) => ({ default: m.FinancePage })),
)
const PaymentReviewPage = lazy(() =>
  import('@/features/payments/review/PaymentReviewPage').then((m) => ({
    default: m.PaymentReviewPage,
  })),
)
const DiscountsPage = lazy(() =>
  import('@/features/billing/discounts/DiscountsPage').then((m) => ({ default: m.DiscountsPage })),
)
const SearchResultsPage = lazy(() =>
  import('@/features/tools/search/SearchResultsPage').then((m) => ({
    default: m.SearchResultsPage,
  })),
)
const CalculatorPage = lazy(() =>
  import('@/features/tools/calculator/CalculatorPage').then((m) => ({ default: m.CalculatorPage })),
)
const ProfilePage = lazy(() =>
  import('@/features/profile/ProfilePage').then((m) => ({ default: m.ProfilePage })),
)
const AccountPage = lazy(() =>
  import('@/features/account/AccountPage').then((m) => ({ default: m.AccountPage })),
)

const ADMINS: UserRole[] = ['super_admin', 'complex_admin']
const SUPER: UserRole[] = ['super_admin']

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
  return (
    <Suspense fallback={<RouteFallback />}>
      <ScrollToTop />
      <TrackPageViews />
      <Routes>
        {/* صفحه‌های عمومی (/ , /auth , /demo , /support) اینجا نیستند؛ لاراول
            آن‌ها را به‌صورت MPA سرو می‌کند. این روتر فقط داشبورد است. */}
        <Route path="/forbidden" element={<ForbiddenPage />} />

        {/* --- مشترک بین همه‌ی نقش‌های واردشده --- */}
        <Route element={<ProtectedRoute />}>
          <Route element={<DashboardLayout />}>
            <Route path="/dashboard" element={<DashboardPage />} />
            <Route path="/announcements" element={<AnnouncementsPage />} />
            <Route path="/messenger" element={<MessengerPage />} />

            {/* درخواست‌ها زیر روت مشترک است: ساکن ثبت می‌کند، مسئول پیگیری
                می‌کند و مدیر واگذار — همه در یک صفحه با دامنه‌ی دیدِ متفاوت. */}
            <Route path="/requests" element={<ServiceRequestsPage />} />

            {/* تاریخچه و تنظیماتِ اعلان برای همه‌ی نقش‌ها (R27) */}
            <Route path="/notifications" element={<NotificationsPage />} />

            {/* صورت‌حساب‌ها زیر روت مشترک است چون مدیر هم واحد شخصی دارد و
                باید بتواند قبوض خودش را ببیند، نه فقط ساکنین. */}
            <Route path="/my-bills" element={<MyBillsPage />} />
            <Route path="/wallet" element={<WalletPage />} />
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
            {/* سهمیه‌ی ماهانه‌ی پیامک — فقط مدیر (R27) */}
            <Route path="/sms-campaign" element={<SmsCampaignPage />} />
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
            <Route path="/system/subscriptions" element={<SystemSubscriptionsPage />} />
            <Route path="/system/ads" element={<AdvertisementsPage />} />
            <Route path="/system/site" element={<SiteSettingsPage />} />
            <Route path="/system/members" element={<MembersPage />} />
            <Route path="/system/plans" element={<PlansPage />} />
            <Route path="/system/sms" element={<SmsPage />} />
            <Route path="/system/observability" element={<ObservabilityPage />} />
            <Route path="/system/audit" element={<AuditLogPage />} />
            <Route path="/system/backup" element={<SystemBackupPage />} />
          </Route>
        </Route>

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
