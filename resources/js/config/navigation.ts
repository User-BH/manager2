import {
  Building2,
  Users,
  UserCog,
  ScrollText,
  Wallet,
  Receipt,
  ClipboardCheck,
  BadgePercent,
  Megaphone,
  MessageSquare,
  Award,
  Settings2,
  DatabaseBackup,
  LayoutDashboard,
  Building,
  Smartphone,
  Server,
} from 'lucide-react'
import type { NavSection, UserRole } from '@/types'

const ADMINS: UserRole[] = ['super_admin', 'complex_admin']
const RESIDENTS: UserRole[] = ['owner', 'tenant']
const SUPER: UserRole[] = ['super_admin']

/**
 * ساختار منوی داشبورد.
 *
 * `roles` تعیین می‌کند هر آیتم برای چه نقش‌هایی دیده شود؛ همان تفکیکی که
 * میدل‌ور `role:` سمت سرور اعمال می‌کند، تا منو چیزی نشان ندهد که کاربر با
 * کلیک روی آن ۴۰۳ بگیرد.
 */
export const navSections: NavSection[] = [
  {
    id: 'overview',
    title: 'نمای کلی',
    items: [{ label: 'داشبورد', path: '/dashboard', icon: LayoutDashboard }],
  },
  {
    id: 'management',
    title: 'مدیریت',
    items: [
      { label: 'واحدها', path: '/units', icon: Building2, roles: ADMINS },
      { label: 'ساکنین', path: '/residents', icon: Users, roles: ADMINS },
      { label: 'مدیران مجتمع', path: '/managers', icon: UserCog, roles: ADMINS },
      { label: 'قوانین شارژ', path: '/charge-rules', icon: ScrollText, roles: ADMINS },
      { label: 'هزینه‌ها و درآمدها', path: '/finance', icon: Wallet, roles: ADMINS },
      { label: 'قبوض و شارژ', path: '/bills', icon: Receipt, roles: ADMINS },
      { label: 'بررسی پرداخت‌ها', path: '/payments', icon: ClipboardCheck, roles: ADMINS },
      { label: 'تخفیف و بخشودگی', path: '/discounts', icon: BadgePercent, roles: ADMINS },
    ],
  },
  {
    id: 'general',
    title: 'عمومی',
    items: [
      { label: 'صورت‌حساب‌های من', path: '/my-bills', icon: Receipt, roles: RESIDENTS },
      { label: 'اطلاعیه‌ها', path: '/announcements', icon: Megaphone },
      { label: 'پیام‌رسان', path: '/messenger', icon: MessageSquare },
      { label: 'ساکنین خوش‌حساب', path: '/top-residents', icon: Award },
    ],
  },
  {
    id: 'settings',
    title: 'تنظیمات',
    items: [
      { label: 'تنظیمات مجتمع', path: '/settings/complex', icon: Settings2, roles: ADMINS },
      { label: 'بکاپ مجتمع', path: '/settings/backup', icon: DatabaseBackup, roles: ADMINS },
    ],
  },
  {
    id: 'system',
    title: 'سیستم',
    items: [
      { label: 'مدیریت مجتمع‌ها', path: '/system/complexes', icon: Building, roles: SUPER },
      { label: 'پنل پیامک', path: '/system/sms', icon: Smartphone, roles: SUPER },
      { label: 'بکاپ کل سیستم', path: '/system/backup', icon: Server, roles: SUPER },
    ],
  },
]

/** بخش‌هایی از منو که این نقش اجازه‌ی دیدنشان را دارد (بخش‌های خالی حذف می‌شوند). */
export function visibleSections(role: UserRole | undefined): NavSection[] {
  if (!role) return []

  return navSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => !item.roles || item.roles.includes(role)),
    }))
    .filter((section) => section.items.length > 0)
}
