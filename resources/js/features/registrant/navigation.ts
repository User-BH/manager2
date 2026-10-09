import { Bell, LayoutDashboard, UserRound, Wallet, WalletCards } from 'lucide-react'

/**
 * منوی تجربه‌ی «ثبت‌نام‌کننده‌ی اولیه» (مرحلهٔ ۸ · فاز ۱).
 *
 * فقط صفحه‌هایی که این حالت واقعاً به کارشان دارد و همین حالا وجود دارند:
 * داشبوردِ خودش (دعوت‌ها و دو راهِ پیوستن)، اعلان‌ها، پروفایل، و حساب — که
 * خریدِ اشتراک، راهِ دومِ بیرون‌رفتن از این حالت، در آن است.
 *
 * و کیفِ پول (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶): کیف مالِ حسابِ شخص است؛ کسی که
 * عضویتش پایان یافته باید همچنان به مانده و گردشِ خودش برسد.
 */
export const REGISTRANT_NAV = [
  { to: '/dashboard', label: 'داشبورد', icon: LayoutDashboard },
  { to: '/notifications', label: 'اعلان‌ها', icon: Bell },
  { to: '/profile', label: 'پروفایل', icon: UserRound },
  { to: '/account', label: 'حساب و اشتراک', icon: WalletCards },
  { to: '/wallet', label: 'کیف پول', icon: Wallet },
] as const
