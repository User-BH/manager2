import { lazy } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { RegistrantLayout } from '@/features/registrant/RegistrantLayout'
import { REGISTRANT_NAV } from '@/features/registrant/navigation'
import { ProtectedRoute } from './ProtectedRoute'

/**
 * تجربه‌ی «ثبت‌نام‌کننده‌ی اولیه» — چهارمین داشبورد (مرحلهٔ ۸ · فاز ۱).
 *
 * ─── چرا روترِ جدا و نه پنهان‌کردنِ آیتم‌ها ────────────────────────────────
 * پیش از این، کسی که ثبت‌نام کرده ولی به مجتمعی وصل نبود همان پوسته و همان
 * مسیرهای ساکن را می‌گرفت (قبض‌های من، کیف پول، درخواست‌ها، پیام‌رسان …) و
 * فقط صفحه‌ی `/dashboard` برایش شاخه‌ی دیگری داشت؛ بقیه‌ی صفحه‌ها یا خالی
 * بودند یا نوشتن را رد می‌کردند. خواستِ کارفرما چهار تجربه‌ی مستقل است، نه
 * یک داشبورد که با نقش چیزهایی در آن پنهان شود.
 *
 * پس این‌جا **فقط** مسیرهای همین حالت تعریف شده‌اند و هر آدرسِ دیگری به
 * داشبوردِ خودش برمی‌گردد. چیدمان و صفحه‌ها (`features/registrant`) مالِ خودش‌اند؛ اجزای مشترک
 * (اعلان، تم، منوی کاربر، صفحه‌های پروفایل و حساب) بازاستفاده‌اند.
 *
 * ⚠️ مرزِ اصلی سمتِ سرور است، نه این‌جا: `LockInitialAccount` هر نوشتنی جز
 * راه‌های بیرون‌رفتن از این حالت را رد می‌کند و دامنه‌ی داده‌ی کاربرِ
 * بی‌مجتمع «هیچ‌چیز» است (`InitialAccountTest`).
 *
 * ⚠️ عمداً قابلیتِ تازه‌ای این‌جا نیامده: جزئیاتِ این داشبورد را کارفرما در
 * فایلِ داشبورد مشخص می‌کند. فقط پایه‌ای درست و قابلِ استفاده.
 */
const RegistrantDashboardPage = lazy(() =>
  import('@/features/registrant/RegistrantDashboardPage').then((m) => ({
    default: m.RegistrantDashboardPage,
  })),
)
const NotificationsPage = lazy(() =>
  import('@/features/notifications/NotificationsPage').then((m) => ({
    default: m.NotificationsPage,
  })),
)
const ProfilePage = lazy(() =>
  import('@/features/profile/ProfilePage').then((m) => ({ default: m.ProfilePage })),
)
/*
 * «حساب و اشتراک» ِ حسابِ اولیه صفحه‌ی خودش را دارد (مرحلهٔ ۹ · فاز ۵): ساختِ
 * مجتمع و خریدِ آنلاین، بی رسید. `AccountPage` ِ مشترک مالِ مدیر است و دست نمی‌خورد.
 */
const RegistrantPurchasePage = lazy(() =>
  import('@/features/registrant/RegistrantPurchasePage').then((m) => ({
    default: m.RegistrantPurchasePage,
  })),
)

/* کیفِ شخصی — همان صفحه‌ی مشترک؛ سرور فقط کیفِ خودِ کاربر را می‌دهد */
const WalletPage = lazy(() =>
  import('@/features/wallet/WalletPage').then((m) => ({ default: m.WalletPage })),
)

const PAGES: Record<(typeof REGISTRANT_NAV)[number]['to'], React.ReactElement> = {
  '/dashboard': <RegistrantDashboardPage />,
  '/notifications': <NotificationsPage />,
  '/profile': <ProfilePage />,
  '/account': <RegistrantPurchasePage />,
  '/wallet': <WalletPage />,
}

export function RegistrantRouter() {
  return (
    <Routes>
      <Route element={<ProtectedRoute />}>
        <Route element={<RegistrantLayout />}>
          {REGISTRANT_NAV.map((item) => (
            <Route key={item.to} path={item.to} element={PAGES[item.to]} />
          ))}
        </Route>
      </Route>

      {/* هر مسیرِ دیگری — صفحه‌های ساکن، مدیر یا ادمینِ کل — مالِ این حالت نیست */}
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}
