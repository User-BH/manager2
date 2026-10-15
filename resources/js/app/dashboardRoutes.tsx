import { lazy, type ComponentType, type LazyExoticComponent } from 'react'
import { Navigate, Route, Routes, matchPath, useLocation } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { leaveToAuth } from '@/shared/lib/leaveToAuth'
import { useAuth } from '@/shared/stores/authStore'
import type { UserRole } from '@/shared/types'
import { ProtectedRoute } from './ProtectedRoute'

/*
 * سه داشبورد، سه روتر (بندِ ۲ ِ فایلِ «بررسی جدید ۲»؛ مرحلهٔ ۱۰ · فاز ۱۶).
 *
 * پیش از این یک `AppRouter` بود با سه گروهِ مسیرِ نقش‌محور: مسیرِ مدیر در روترِ ساکن هم ثبت بود و فقط `ProtectedRoute`
 * با فهرستِ نقش جلویش را می‌گرفت. حالا هر داشبورد روترِ خودش را دارد که **فقط** مسیرهای خودش را می‌شناسد، و
 * `AppRouter` یکی را از نقشِ زمینه‌ی فعال (۱۰/۳) برمی‌گزیند. ظاهر، پوسته (`DashboardLayout`) و صفحه‌ها همان‌اند؛ مرزِ
 * دسترسیِ واقعی همچنان در سرور است.
 *
 * هر صفحه‌ی داشبورد چانک جدا می‌شود تا بازدیدکننده‌ی یک داشبورد صفحه‌های داشبوردِ دیگر را دانلود نکند.
 */
const page = <T extends Record<string, ComponentType>>(load: () => Promise<T>, name: keyof T) =>
  lazy(() => load().then((m) => ({ default: m[name] })))

const DashboardLayout = page(() => import('@/shared/layout/DashboardLayout'), 'DashboardLayout')
const ForbiddenPage = page(() => import('@/features/error/ForbiddenPage'), 'ForbiddenPage')
const NotFoundPage = page(() => import('@/features/error/NotFoundPage'), 'NotFoundPage')

/** یک مسیرِ داشبورد: نشانی و صفحه‌اش. روترها و آزمونِ هم‌خوانیِ منو هر دو از همین جدول می‌خوانند. */
export interface DashboardRoute {
  path: string
  page: LazyExoticComponent<ComponentType>
}

/** مشترکِ هر سه: هر عضوِ واردشده — مدیر و ادمینِ کل هم واحدِ شخصی و کیف دارند. */
export const SHARED_ROUTES: DashboardRoute[] = [
  {
    path: '/dashboard',
    page: page(() => import('@/features/dashboard/DashboardPage'), 'DashboardPage'),
  },
  {
    path: '/announcements',
    page: page(
      () => import('@/features/messaging/announcements/AnnouncementsPage'),
      'AnnouncementsPage',
    ),
  },
  {
    path: '/messenger',
    page: page(() => import('@/features/messaging/messenger/MessengerPage'), 'MessengerPage'),
  },
  {
    path: '/messenger/:id',
    page: page(() => import('@/features/messaging/messenger/MessengerPage'), 'MessengerPage'),
  },
  // ساکن ثبت می‌کند، مسئول پیگیری و مدیر واگذار — یک صفحه با دامنه‌ی دیدِ متفاوت
  {
    path: '/requests',
    page: page(() => import('@/features/requests/ServiceRequestsPage'), 'ServiceRequestsPage'),
  },
  // تاریخچه و تنظیماتِ اعلان برای همه‌ی نقش‌ها (R27)
  {
    path: '/notifications',
    page: page(() => import('@/features/notifications/NotificationsPage'), 'NotificationsPage'),
  },
  {
    path: '/my-bills',
    page: page(() => import('@/features/billing/my-bills/MyBillsPage'), 'MyBillsPage'),
  },
  { path: '/wallet', page: page(() => import('@/features/wallet/WalletPage'), 'WalletPage') },
  {
    path: '/pay/:billId',
    page: page(() => import('@/features/payments/pay/PayBillPage'), 'PayBillPage'),
  },
  {
    path: '/top-residents',
    page: page(() => import('@/features/finance/good-payers/GoodPayersPage'), 'GoodPayersPage'),
  },
  {
    path: '/search',
    page: page(() => import('@/features/tools/search/SearchResultsPage'), 'SearchResultsPage'),
  },
  {
    path: '/calculator',
    page: page(() => import('@/features/tools/calculator/CalculatorPage'), 'CalculatorPage'),
  },
  { path: '/profile', page: page(() => import('@/features/profile/ProfilePage'), 'ProfilePage') },
  { path: '/account', page: page(() => import('@/features/account/AccountPage'), 'AccountPage') },
  // خریدِ مجتمعِ تازه توسطِ عضو (مرحلهٔ ۱۰ · فاز ۵) — همان صفحه‌ی خریدِ حسابِ اولیه
  {
    path: '/new-complex',
    page: page(
      () => import('@/features/registrant/RegistrantPurchasePage'),
      'RegistrantPurchasePage',
    ),
  },
  // مالک/مستأجرِ فعلی یا مدیر — مجوز در سرور (مرحلهٔ ۱۰ · فاز ۷)
  {
    path: '/units/:unitId/information',
    page: page(() => import('@/features/units/UnitInformationPage'), 'UnitInformationPage'),
  },
]

/** مدیریتِ مجتمع: مدیر، و ادمینِ کل در مجتمعی که برگزیده. */
export const MANAGER_ROUTES: DashboardRoute[] = [
  { path: '/units', page: page(() => import('@/features/units/UnitsPage'), 'UnitsPage') },
  // سهمیه‌ی ماهانه‌ی پیامک — فقط مدیر (R27)
  {
    path: '/sms-campaign',
    page: page(() => import('@/features/notifications/SmsCampaignPage'), 'SmsCampaignPage'),
  },
  {
    path: '/residents',
    page: page(() => import('@/features/residents/ResidentsPage'), 'ResidentsPage'),
  },
  { path: '/bills', page: page(() => import('@/features/billing/bills/BillsPage'), 'BillsPage') },
  {
    path: '/managers',
    page: page(() => import('@/features/managers/ManagersPage'), 'ManagersPage'),
  },
  {
    path: '/charge-rules',
    page: page(() => import('@/features/billing/charge-rules/ChargeRulesPage'), 'ChargeRulesPage'),
  },
  { path: '/finance', page: page(() => import('@/features/finance/FinancePage'), 'FinancePage') },
  {
    path: '/payments',
    page: page(() => import('@/features/payments/review/PaymentReviewPage'), 'PaymentReviewPage'),
  },
  {
    path: '/discounts',
    page: page(() => import('@/features/billing/discounts/DiscountsPage'), 'DiscountsPage'),
  },
  {
    path: '/settings/information',
    page: page(
      () => import('@/features/settings/ComplexInformationPage'),
      'ComplexInformationPage',
    ),
  },
  {
    path: '/settings/complex',
    page: page(() => import('@/features/settings/ComplexSettingsPage'), 'ComplexSettingsPage'),
  },
  {
    path: '/settings/backup',
    page: page(() => import('@/features/settings/ComplexBackupPage'), 'ComplexBackupPage'),
  },
]

/** فقط ادمینِ کلِ سکو. */
export const PLATFORM_ROUTES: DashboardRoute[] = [
  {
    path: '/system/complexes',
    page: page(() => import('@/features/system/ComplexesPage'), 'ComplexesPage'),
  },
  // آمارِ کلِ پلتفرم (R29)
  {
    path: '/system/stats',
    page: page(() => import('@/features/system/PlatformStatsPage'), 'PlatformStatsPage'),
  },
  {
    path: '/system/subscriptions',
    page: page(() => import('@/features/system/SubscriptionsPage'), 'SubscriptionsPage'),
  },
  {
    path: '/system/ads',
    page: page(() => import('@/features/system/ads/AdvertisementsPage'), 'AdvertisementsPage'),
  },
  {
    path: '/system/blog',
    page: page(() => import('@/features/system/blog/BlogPostsPage'), 'BlogPostsPage'),
  },
  {
    path: '/system/blog/new',
    page: page(() => import('@/features/system/blog/BlogEditorPage'), 'BlogEditorPage'),
  },
  {
    path: '/system/blog/:id',
    page: page(() => import('@/features/system/blog/BlogEditorPage'), 'BlogEditorPage'),
  },
  {
    path: '/system/site',
    page: page(() => import('@/features/system/SiteSettingsPage'), 'SiteSettingsPage'),
  },
  {
    path: '/system/feedback',
    page: page(() => import('@/features/system/FeedbackInboxPage'), 'FeedbackInboxPage'),
  },
  {
    path: '/system/account-recovery',
    page: page(() => import('@/features/system/AccountRecoveryPage'), 'AccountRecoveryPage'),
  },
  {
    path: '/system/members',
    page: page(() => import('@/features/system/MembersPage'), 'MembersPage'),
  },
  { path: '/system/plans', page: page(() => import('@/features/system/PlansPage'), 'PlansPage') },
  { path: '/system/sms', page: page(() => import('@/features/system/SmsPage'), 'SmsPage') },
  {
    path: '/system/observability',
    page: page(
      () => import('@/features/system/observability/ObservabilityPage'),
      'ObservabilityPage',
    ),
  },
  {
    path: '/system/audit',
    page: page(() => import('@/features/system/AuditLogPage'), 'AuditLogPage'),
  },
  {
    path: '/system/backup',
    page: page(() => import('@/features/system/SystemBackupPage'), 'SystemBackupPage'),
  },
]

export type Dashboard = 'unit' | 'manager' | 'platform'

/** مسیرهای هر داشبورد — همان‌هایی که روترش ثبت می‌کند و نه بیشتر. */
export const DASHBOARD_ROUTES: Record<Dashboard, DashboardRoute[]> = {
  unit: SHARED_ROUTES,
  manager: [...SHARED_ROUTES, ...MANAGER_ROUTES],
  platform: [...SHARED_ROUTES, ...MANAGER_ROUTES, ...PLATFORM_ROUTES],
}

/** داشبوردِ هر نقش — نقشِ زمینه‌ی فعال، نه نقشِ ذخیره در حساب (۱۰/۳). */
export function dashboardFor(role: UserRole | undefined): Dashboard {
  if (role === 'super_admin') return 'platform'
  if (role === 'complex_admin') return 'manager'
  return 'unit'
}

/** آیا این نشانی مالِ یکی از داشبوردهاست (هر کدام)؟ */
export function isDashboardPath(pathname: string): boolean {
  return Object.values(DASHBOARD_ROUTES).some((routes) =>
    routes.some((route) => matchPath(route.path, pathname) !== null),
  )
}

/**
 * نشانی‌ای که در این داشبورد نیست: مالِ داشبوردِ دیگر ← «دسترسی ندارید» (همان رفتارِ پیشین)، ناشناخته ← ۴۰۴.
 * بیرون از نشست، مسیرِ داشبوردِ دیگر هم به ورود می‌رود — همان کاری که `ProtectedRoute` می‌کرد.
 */
function OutsideThisDashboard() {
  const { pathname } = useLocation()
  const { isAuthenticated, isLoading } = useAuth()

  if (!isDashboardPath(pathname)) return <NotFoundPage />

  // نقش هنوز نرسیده: شاید همین مسیر مالِ داشبوردی باشد که پس از `/me` برگزیده می‌شود
  if (isLoading) {
    return (
      <div
        className="flex min-h-screen items-center justify-center"
        style={{ backgroundColor: 'var(--surface-canvas)' }}
      >
        <Loader2 size={28} className="animate-spin" style={{ color: 'var(--color-brand-500)' }} />
      </div>
    )
  }

  if (!isAuthenticated) {
    leaveToAuth()
    return null
  }

  return <Navigate to="/forbidden" replace />
}

/** روترِ یک داشبورد: فقط مسیرهای خودش، در همان پوسته. */
export function DashboardRouter({ dashboard }: { dashboard: Dashboard }) {
  return (
    <Routes>
      {/* صفحه‌های عمومی (/ , /auth , /demo , /support) اینجا نیستند؛ لاراول آن‌ها را MPA سرو می‌کند */}
      <Route path="/forbidden" element={<ForbiddenPage />} />

      <Route element={<ProtectedRoute />}>
        <Route element={<DashboardLayout />}>
          {DASHBOARD_ROUTES[dashboard].map(({ path, page: Page }) => (
            <Route key={path} path={path} element={<Page />} />
          ))}
        </Route>
      </Route>

      <Route path="*" element={<OutsideThisDashboard />} />
    </Routes>
  )
}
