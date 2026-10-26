import type { LucideIcon } from 'lucide-react'

export interface NavItem {
  label: string
  path: string
  icon: LucideIcon
  /** اگر خالی باشد برای همه‌ی نقش‌ها نمایش داده می‌شود */
  roles?: UserRole[]
}

export interface NavSection {
  id: string
  title: string
  items: NavItem[]
}

export type UserRole = 'super_admin' | 'complex_admin' | 'owner' | 'tenant'

/** نوعِ مجتمع (R67) — یک مجتمع یا مسکونی است یا تجاری، هرگز هر دو. */
export type ComplexType = 'residential' | 'commercial'

export interface ComplexSummary {
  id: number
  name: string

  /**
   * ⚠️ واژگان از سرور می‌آید، نه از رشته‌ی ثابت در فرانت.
   *
   * تفاوتش با «تغییرِ چند Label» همین است: نوعِ سومی که روزی اضافه شود،
   * یک‌جا در `ComplexType`ِ PHP تعریف می‌شود — نه با گشتنِ دنبالِ کلمه‌ی
   * «ساکن» در ده‌ها فایلِ فرانت.
   */
  type: ComplexType
  typeLabel: string
  occupantLabel: string
  occupantPluralLabel: string
  unitLabel: string
}

/** شکلی که /api/me برمی‌گرداند */
export interface CurrentUser {
  id: number
  /** نامِ نمایشی؛ از نام و نام خانوادگی ساخته می‌شود */
  name: string
  /** تصویرِ canonical ِ حساب (مرحلهٔ ۱۰ · فاز ۷) — نشانیِ نسخه‌دار، یا null */
  avatarUrl?: string | null
  firstName: string | null
  lastName: string | null
  /** حسابِ پیش از مرحلهٔ ۹ که نام و نام خانوادگی‌اش هنوز جدا ثبت نشده */
  needsName: boolean
  phone: string
  role: UserRole
  roleLabel: string
  isAdmin: boolean
  isSuperAdmin: boolean
  /** سوپر ادمین بیرون از Production: کلیدِ «شاملِ دادهٔ آزمایشی» معنا دارد (سرور هم همین را می‌سنجد) */
  demoData?: boolean
  /** سوپر ادمینِ Production بی Authenticator: فقط راه‌اندازیِ عاملِ دوم باز است (سرور بقیه را می‌بندد) */
  twoFactorSetupRequired?: boolean
  complex: ComplexSummary | null

  /**
   * حالتِ حساب (R21) — از واقعیت مشتق می‌شود، نه از نقش.
   *
   * `initial` یعنی ثبت‌نام کرده ولی نه خریده و نه مدیری اضافه‌اش کرده.
   * داشبوردش فقط‌خواندنی است و سرور هم همین را جداگانه اعمال می‌کند.
   */
  accountState: AccountState
  /**
   * داشبوردِ همین نشست (مرحلهٔ ۱۲ · فاز ۱) — سرور از حالتِ حساب و نقشِ عضویتِ زمینه‌ی نشست می‌گوید.
   * اختیاری فقط برای پاسخِ سرورِ پیش از ۱۲/۱. `select` (۱۳/۳): حسابِ چندمجتمعی که تازه وارد شده و هنوز مجتمعی
   * برنگزیده — فقط صفحه‌ی انتخاب.
   */
  dashboard?: 'select' | 'initial' | 'manager' | 'unit' | 'platform'
  canWrite: boolean
  pendingInvitations: number
  /**
   * نشانِ آدمک (۱۳/۵): اعلان‌های دعوتِ خوانده‌نشده که دعوتشان هنوز زنده است — از سرور، در هر زمینه همان عدد.
   * اختیاری فقط برای پاسخِ سرورِ پیشین.
   */
  unreadInvitations?: number

  /**
   * عضویت‌های فعالِ حساب (مرحلهٔ ۱۰ · فاز ۴) — یکی برای هر مجتمع؛ `current` زمینه‌ی
   * فعال است. ادمینِ کل عضویت ندارد.
   */
  memberships: MembershipSummary[]
}

export interface MembershipSummary {
  complexId: number
  complexName: string
  /** «مسکونی» یا «تجاری» (۱۳/۳)؛ اختیاری فقط برای پاسخِ سرورِ پیشین */
  complexTypeLabel?: string
  role: UserRole
  roleLabel: string
  /** معلق به دستِ مدیرِ همان مجتمع (۱۰/۶): دیده می‌شود ولی تعویض به آن ممکن نیست */
  status: 'active' | 'suspended'
  current: boolean
}

export type AccountState = 'initial' | 'member' | 'platform'

/** دعوتی که مدیرِ یک مجتمع برای کاربر فرستاده (R21). */
export interface Invitation {
  id: number
  complexName: string | null
  complexAddress: string | null
  unitLabel: string | null
  roleLabel: string
  invitedBy: string | null
  createdAt: string | null
}

export type Theme = 'light' | 'dark'

/** وضعیت پرداخت یک قبض */
export type BillStatus = 'unpaid' | 'partial' | 'pending' | 'paid'

export interface DashboardStats {
  period: string
  periodLabel: string
  income: number
  expense: number
  balance: number
  totalDebt: number
  currency: string
  trend: { label: string; income: number; expense: number }[]
  statusCounts: Record<BillStatus, number>
  debtors: { id: number; label: string; floor: number; balance: number }[]
  goodPayers: { id: number; label: string; onTime: number }[]
}

/* --- جستجوی سراسری --- */

export interface SearchItem {
  id: number
  title: string
  subtitle: string
  /** برچسب کوچک سمت چپ ردیف، مثلاً مبلغ بدهی یا تاریخ. */
  badge: string | null
  /** مسیر داخل SPA که کلیک روی نتیجه به آن می‌رود. */
  path: string
}

export interface SearchGroup {
  id: string
  title: string
  /** نام آیکون lucide که سرور فرستاده؛ کلاینت آن را به کامپوننت نگاشت می‌کند. */
  icon: string
  path: string
  count: number
  /** نتیجه‌ی بیشتری از آنچه آمده هست — فهرست بریده شده است. */
  hasMore: boolean
  /** صفحه‌ی بعدِ همین گروه، فقط وقتی گروه باز شده (`group`)؛ در نمای کلی null. */
  nextPage: number | null
  items: SearchItem[]
}

export interface SearchResponse {
  query: string
  total: number
  groups: SearchGroup[]
  message?: string
  /** گروهِ بازشده، یا null در نمای کلی. */
  group?: string | null
  page?: number
}

/** یک عبارت جستجوشده که در localStorage نگه داشته می‌شود. */
export interface RecentSearch {
  query: string
  total: number
  /** میلی‌ثانیه‌ی یونیکس. */
  at: number
}

/* --- اعلان‌ها --- */

/**
 * یک آیتمِ زنگوله — از دو منبعِ متفاوت، با یک شکل.
 *
 * `kind` تعیین می‌کند کلیک روی آن کجا می‌برد و «خوانده شد» چطور ثبت می‌شود:
 * اطلاعیه‌ی همگانی در `announcement_reads` و اعلانِ شخصی در ستونِ `read_at`
 * خودش. `id` رشته است (`a:12` / `n:uuid`) چون دو فضای شناسه با هم ادغام
 * شده‌اند و عددِ خالی می‌توانست بینشان تصادف کند.
 */
export interface NotificationItem {
  id: string
  kind: 'announcement' | 'personal'
  title: string
  excerpt: string
  isPinned: boolean
  isRead: boolean
  publishedAt: string | null
  /** فقط برای اطلاعیه‌ها — شناسه‌ی خامِ عددی برای مسیرِ فوکوس. */
  announcementId?: number
  /** فقط برای اعلانِ شخصی — مقصدِ کلیک. */
  link?: string | null
}

export interface NotificationsResponse {
  unreadCount: number
  /** نشانِ آدمک (۱۳/۵) */
  unreadInvitations?: number
  items: NotificationItem[]
}

export interface Paginated<T> {
  data: T[]
  meta: { currentPage: number; lastPage: number; perPage: number; total: number }
}
