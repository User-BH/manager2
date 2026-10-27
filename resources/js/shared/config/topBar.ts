/**
 * ماتریسِ نوارِ بالا (مرحلهٔ ۱۳ · فاز ۴؛ ایرادات ۱۱، بندهای ۲.۱ تا ۲.۱۰؛ ایرادات ۱۰، بندِ ۵۲).
 *
 * یک ساختارِ مشترک (`Header` و `TopBarActions`)، و برای هر داشبورد فقط فهرستِ ابزارهایش — نه چهار نوارِ جدا. کلید
 * همان `dashboard` ِ سرور است، و Preview همان کد و همین جدول را دارد: محیط آیکونی اضافه یا کم نمی‌کند (بندِ ۲.۸).
 *
 * ⚠️ آیکون مجوز نمی‌سازد و پنهان‌کردنش مجوز را نمی‌بندد (بندِ ۲.۶): هر ابزار پشتِ مسیر و API ِ خودش است که سرور
 * می‌سنجد. ترتیبِ آرایه همان ترتیبِ نمایش از راست به چپ (RTL) است.
 */
export type TopBarDashboard = 'initial' | 'unit' | 'manager' | 'platform'

export type TopBarItem =
  /** کادرِ جست‌وجو در عرضِ بزرگ، و آیکون و پنلِ زیرِ سربرگ در عرضِ کم */
  | 'search'
  /** «جستجوهای اخیر» کنارِ کادر — مدیریتِ کامل در محتوای اصلی (ایرادات ۹، بندهای ۵۰ تا ۵۵) */
  | 'recentSearches'
  | 'calculator'
  /** تقویمِ شمسی و یادآورها (۱۳/۷) — همیشه آیکون، در هر عرض (ایرادات ۱۰، بندِ ۶۷) */
  | 'calendar'
  | 'notifications'
  | 'theme'
  | 'account'

const MEMBER: readonly TopBarItem[] = [
  'search',
  'recentSearches',
  'calculator',
  'calendar',
  'notifications',
  'theme',
  'account',
]

export const TOP_BAR: Record<TopBarDashboard, readonly TopBarItem[]> = {
  // بندِ ۲.۴: فقط آدمک، اعلان و تم — جست‌وجو، ماشین‌حساب و تقویم (بندِ ۶۹ ِ ایرادات ۱۰) این‌جا نیستند
  initial: ['notifications', 'theme', 'account'],
  unit: MEMBER,
  manager: MEMBER,
  /*
   * سوپر ادمین همان ابزارها؛ جست‌وجو فقط در مجتمعی که از پنلِ سیستم برگزیده — بی آن سرور هیچ داده‌ی مجتمعی نمی‌دهد
   * (`SearchController`، `TopBarSearchTest`).
   */
  platform: MEMBER,
}

/*
 * دستیار (۱۳/۸) عمداً در این نوار نیست: ایرادات ۱۱، بندِ ۴.۳ دکمه‌ی دایره‌ایِ شناورِ **پایینِ چپ** می‌خواهد، مستقل از
 * نوار و منوی کناری (`features/assistant/AssistantDock`). نمایشش را سرور می‌گوید (`user.assistant` ← `AssistantAccess`):
 * داشبوردِ اولیه هرگز، مجتمع فقط با پلنِ پیشرفته.
 */

/** ابزارهای نوارِ بالای این داشبورد. */
export function topBarItems(dashboard: TopBarDashboard): readonly TopBarItem[] {
  return TOP_BAR[dashboard]
}

/**
 * داشبوردِ نوار از پاسخِ سرور (`dashboard`، و برای پاسخِ پیش از ۱۲/۱ نقش) — همان قاعده‌ی `dashboardFor`. صفحه‌ی انتخابِ
 * مجتمع (`select`) نواری ندارد و این‌جا نمی‌رسد.
 */
export function topBarDashboardOf(
  user: { role: string; dashboard?: string; accountState: string } | null,
): TopBarDashboard {
  if (user?.dashboard === 'initial' || user?.accountState === 'initial') return 'initial'
  if (
    user?.dashboard === 'unit' ||
    user?.dashboard === 'manager' ||
    user?.dashboard === 'platform'
  ) {
    return user.dashboard
  }
  if (user?.role === 'super_admin') return 'platform'
  return user?.role === 'complex_admin' ? 'manager' : 'unit'
}
