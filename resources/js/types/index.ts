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

export interface ComplexSummary {
  id: number
  name: string
}

/** شکلی که /api/me برمی‌گرداند */
export interface CurrentUser {
  id: number
  name: string
  phone: string
  role: UserRole
  roleLabel: string
  isAdmin: boolean
  isSuperAdmin: boolean
  complex: ComplexSummary | null
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
  items: SearchItem[]
}

export interface SearchResponse {
  query: string
  total: number
  groups: SearchGroup[]
  message?: string
}

/** یک عبارت جستجوشده که در localStorage نگه داشته می‌شود. */
export interface RecentSearch {
  query: string
  total: number
  /** میلی‌ثانیه‌ی یونیکس. */
  at: number
}

/* --- اعلان‌ها --- */

export interface NotificationItem {
  id: number
  title: string
  excerpt: string
  isPinned: boolean
  isRead: boolean
  publishedAt: string | null
}

export interface NotificationsResponse {
  unreadCount: number
  items: NotificationItem[]
}

export interface Paginated<T> {
  data: T[]
  meta: { currentPage: number; lastPage: number; perPage: number; total: number }
}
