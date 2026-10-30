import {
  Activity,
  Award,
  BadgePercent,
  BarChart3,
  Building,
  Building2,
  CircleUserRound,
  ClipboardCheck,
  ClipboardList,
  Crown,
  DatabaseBackup,
  LayoutDashboard,
  Megaphone,
  Megaphone as MegaphoneIcon,
  Newspaper,
  BellRing,
  MessageSquare,
  MessageSquareWarning,
  Receipt,
  ScrollText,
  ScrollText as AuditIcon,
  Server,
  Settings2,
  MessageSquareHeart,
  Share2,
  Smartphone,
  UserCog,
  Users,
  Wallet,
} from 'lucide-react'
import type { NavSection, UserRole } from '@/shared/types'

const ADMINS: UserRole[] = ['super_admin', 'complex_admin']
const RESIDENTS: UserRole[] = ['owner', 'tenant']
const SUPER: UserRole[] = ['super_admin']
const MANAGERS: UserRole[] = ['complex_admin']

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
      // سهمیه‌ی ماهانه‌ی پیامک یادآوری شارژ (R27)
      { label: 'پیامک یادآوری', path: '/sms-campaign', icon: MessageSquareWarning, roles: ADMINS },
      { label: 'تخفیف و بخشودگی', path: '/discounts', icon: BadgePercent, roles: ADMINS },
    ],
  },
  {
    id: 'general',
    title: 'عمومی',
    items: [
      { label: 'صورت‌حساب‌های من', path: '/my-bills', icon: Receipt, roles: RESIDENTS },
      // کیف پول برای مدیر هم دیده می‌شود: موجودیِ همه‌ی واحدها را می‌بیند
      { label: 'کیف پول', path: '/wallet', icon: Wallet },
      { label: 'اطلاعیه‌ها', path: '/announcements', icon: Megaphone },
      { label: 'اعلان‌ها', path: '/notifications', icon: BellRing },
      { label: 'پیام‌رسان', path: '/messenger', icon: MessageSquare },
      // برای هر سه نقش: ساکن ثبت می‌کند، مسئول پیگیری، مدیر واگذار
      { label: 'درخواست‌ها', path: '/requests', icon: ClipboardList },
      { label: 'ساکنین خوش‌حساب', path: '/top-residents', icon: Award },
    ],
  },
  {
    id: 'settings',
    title: 'تنظیمات',
    items: [
      // «اطلاعاتِ مجتمع» — بخشِ داشبوردِ مدیر (مرحلهٔ ۱۰ · فاز ۷؛ بندهای ۷ و ۱۸)
      { label: 'اطلاعات مجتمع', path: '/settings/information', icon: Building2, roles: ADMINS },
      { label: 'تنظیمات مجتمع', path: '/settings/complex', icon: Settings2, roles: ADMINS },
      { label: 'بکاپ مجتمع', path: '/settings/backup', icon: DatabaseBackup, roles: ADMINS },
      /*
       * «تنظیماتِ حساب» در منوی کناری، نه منوی آدمک (۱۳/۳؛ ایرادات ۱۰، بندِ ۲ و «داشبورد های آزمایشی» ۵.۲): مدیر و
       * سوپر ادمین — نه واحد (سرور هم رد می‌کند، ۱۳/۶). مدیر: پلن، تمدید و پرداخت؛ سوپر ادمین: مدیریتِ کارت‌ها، پلن‌ها و
       * قیمت‌ها، بی هیچ خرید و پرداختی (بندِ ۵).
       */
      { label: 'تنظیمات حساب', path: '/account', icon: CircleUserRound, roles: MANAGERS },
    ],
  },
]

/**
 * منوی سوپر ادمین — گروه‌بندیِ بندِ ۷۷ ِ «سندِ داشبوردِ سوپر ادمین»، با «پنل پیامک» و «لاگ فعالیت» در «سیستم» (دستورِ
 * کارفرما ۲۰۲۶-۱۰-۳۰). آیتم‌هایی از آن بند که هنوز صفحه ندارند — اطلاعیه‌ها و پیامکِ عمومی (۱۴/۸)، تیکت‌ها (۱۴/۴) — با
 * ساختنِ صفحه‌شان اضافه می‌شوند؛ پیوندِ بی‌مقصد در منو نمی‌آید.
 */
export const platformSections: NavSection[] = [
  {
    id: 'overview',
    title: 'نمای کلی',
    items: [{ label: 'داشبورد', path: '/dashboard', icon: LayoutDashboard }],
  },
  {
    id: 'platform-management',
    title: 'مدیریت',
    items: [
      // پلن‌ها همان «تنظیماتِ حساب» ِ سوپر ادمین است (۱۳/۶، «داشبورد های آزمایشی» ۵.۲)
      {
        label: 'تنظیمات حساب و پلن‌ها',
        path: '/system/plans',
        icon: CircleUserRound,
        roles: SUPER,
      },
      { label: 'اشتراک‌ها', path: '/system/subscriptions', icon: Crown, roles: SUPER },
      { label: 'مجتمع‌ها', path: '/system/complexes', icon: Building, roles: SUPER },
      { label: 'کاربران', path: '/system/members', icon: Users, roles: SUPER },
      // صفِ فرمِ بازخوردِ سایت (مرحلهٔ ۷ · فاز ۴)
      { label: 'بازخوردها', path: '/system/feedback', icon: MessageSquareHeart, roles: SUPER },
      // بازیابیِ حساب بی شماره‌ی قبلی (مرحلهٔ ۹)
      { label: 'بازیابی حساب', path: '/system/account-recovery', icon: UserCog, roles: SUPER },
    ],
  },
  {
    id: 'platform-general',
    title: 'عمومی',
    items: [{ label: 'مرکز اعلان‌ها', path: '/notifications', icon: BellRing, roles: SUPER }],
  },
  {
    id: 'system',
    title: 'سیستم',
    items: [
      { label: 'آمار پلتفرم', path: '/system/stats', icon: BarChart3, roles: SUPER },
      { label: 'تبلیغات صفحه اصلی', path: '/system/ads', icon: MegaphoneIcon, roles: SUPER },
      { label: 'بلاگ', path: '/system/blog', icon: Newspaper, roles: SUPER },
      { label: 'فوتر و شبکه‌ها', path: '/system/site', icon: Share2, roles: SUPER },
      { label: 'پایش و تحلیل', path: '/system/observability', icon: Activity, roles: SUPER },
      { label: 'پنل پیامک', path: '/system/sms', icon: Smartphone, roles: SUPER },
      { label: 'لاگ فعالیت', path: '/system/audit', icon: AuditIcon, roles: SUPER },
    ],
  },
  {
    id: 'platform-settings',
    title: 'تنظیمات',
    items: [{ label: 'بکاپ و بازیابی', path: '/system/backup', icon: Server, roles: SUPER }],
  },
]

/**
 * بخش‌هایی از منو که این نقش اجازه‌ی دیدنشان را دارد (بخش‌های خالی حذف می‌شوند).
 *
 * سوپر ادمین منوی پلتفرم را دارد؛ ابزارهای یک مجتمع (واحدها، قبوض، پیام‌رسان…) فقط وقتی مجتمعی را از «مجتمع‌ها» برگزیده،
 * در بخشِ جدای «مجتمعِ برگزیده» — بی مجتمع همه‌شان فقط «ابتدا یک مجتمع را انتخاب کنید» می‌گفتند.
 */
export function visibleSections(
  role: UserRole | undefined,
  { complexSelected = false }: { complexSelected?: boolean } = {},
): NavSection[] {
  if (!role) return []

  if (role === 'super_admin') {
    const complexItems = navSections
      .filter((section) => section.id !== 'overview')
      .flatMap((section) => section.items)
      .filter(
        (item) =>
          item.roles?.includes('super_admin') || (!item.roles && item.path !== '/notifications'),
      )
    return complexSelected
      ? [
          ...platformSections,
          { id: 'selected-complex', title: 'مجتمعِ برگزیده', items: complexItems },
        ]
      : platformSections
  }

  return navSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => !item.roles || item.roles.includes(role)),
    }))
    .filter((section) => section.items.length > 0)
}
