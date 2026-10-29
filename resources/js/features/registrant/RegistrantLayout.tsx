import { DashboardLayout } from '@/shared/layout/DashboardLayout'
import type { NavSection } from '@/shared/types'
import { REGISTRANT_NAV } from './navigation'

/** منوی سایدبارِ همین حالت — همان پنج مقصد، به شکلِ بخشِ سایدبار */
const SECTIONS: NavSection[] = [
  {
    id: 'registrant',
    title: 'حساب من',
    items: REGISTRANT_NAV.map(({ to, label, icon }) => ({ label, path: to, icon })),
  },
]

/**
 * پوسته‌ی تجربه‌ی «ثبت‌نام‌کننده‌ی اولیه» (مرحلهٔ ۸ · فاز ۱).
 *
 * از ۲۰۲۶-۱۰-۲۹ (بندِ ۷) همان پوسته‌ی سه داشبوردِ دیگر است: آیتم‌ها در سایدبارِ سمتِ راست (و کشوی موبایل)، نه
 * زبانه‌های زیرِ سرصفحه — همان رفتار، همان جمع‌شدن و همان نوارِ بالا. فقط منو مالِ خودش است (پنج مقصدِ همین حالت)،
 * و ابزارهای نوار از ماتریسِ `topBar.ts` (`initial`: آدمک، اعلان و تم — بی جست‌وجو، ماشین‌حساب و تقویم).
 *
 * ⚠️ مرزِ اصلی سمتِ سرور است، نه این منو: `LockInitialAccount` و `RegistrantRouter`.
 */
export function RegistrantLayout() {
  return (
    <DashboardLayout
      sections={SECTIONS}
      navLabel="منوی حساب"
      experience="registrant"
      presence={false}
    />
  )
}
