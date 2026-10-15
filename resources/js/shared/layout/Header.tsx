import { NavLink } from 'react-router-dom'
import { Calculator, Menu } from 'lucide-react'
import { SearchBox } from './SearchBox'
import { NotificationBell } from './NotificationBell'
import { ThemeToggle } from './ThemeToggle'
import { UserMenu } from './UserMenu'
import { Tooltip } from '@/shared/ui/Tooltip'
import { useSidebar } from '@/shared/stores/sidebarStore'

export function Header() {
  const { setMobileOpen } = useSidebar()

  return (
    /*
     * لایه‌ی سربرگ: z-45 («چند نکته»، بندِ ۵).
     *
     * `sticky` + `z-index` و `backdrop-blur` هر کدام سربرگ را یک stacking context می‌کنند؛ پس `z-40` ِ باکسِ اعلان و
     * منوی کاربر فقط **درونِ** سربرگ معنا دارد و از بیرون همه با z ِ خودِ سربرگ سنجیده می‌شوند. سربرگ z-20 بود و
     * نمایشگرِ چسبانِ ماشین‌حساب هم z-20 و بعدتر در DOM — برابری با ترتیب شکسته می‌شد و ماشین‌حساب روی باکس می‌نشست.
     * (`backdrop-filter` حتی `fixed` ِ موبایلِ باکس را هم به سربرگ می‌بندد.)
     *
     * ترتیبِ لایه‌ها: محتوای صفحه ≤ z-30 · کارتِ نصب (پایینِ صفحه) z-40 · سربرگ و باکس‌هایش z-45 · پوشش‌های مسدودکننده
     * (Modal، کشوی موبایل، نوارِ اتصال) z-50. `overlayLayers.test.ts` نمی‌گذارد محتوای صفحه به لایه‌ی سربرگ برسد.
     */
    <header
      className="sticky top-0 z-45 flex h-16 items-center gap-3 border-b px-4 backdrop-blur sm:px-6"
      style={{
        backgroundColor: 'color-mix(in srgb, var(--surface-base) 92%, transparent)',
        borderColor: 'var(--border-subtle)',
      }}
    >
      <button
        onClick={() => setMobileOpen(true)}
        className="flex h-9 w-9 items-center justify-center rounded-xl transition-colors hover:bg-(--surface-sunken) lg:hidden"
        style={{ color: 'var(--text-secondary)' }}
        aria-label="باز کردن منو"
      >
        <Menu size={20} />
      </button>

      <div className="flex-1">
        <SearchBox />
      </div>

      <div className="flex items-center gap-2">
        {/*
          ماشین حساب یک صفحه‌ی معمولی است نه پاپ‌آپ: با NavLink هم آدرسش
          قابل بوکمارک می‌شود و هم هدر و سایدبار سر جایشان می‌مانند و فقط
          وسط صفحه عوض می‌شود — همان رفتاری که بقیه‌ی صفحه‌ها دارند.
          تولتیپ سفارشی به‌جای title بومی، تا در تم تاریک هم جذاب باشد.
        */}
        <Tooltip label="ماشین حساب مهندسی">
          <NavLink
            to="/calculator"
            aria-label="ماشین حساب"
            className="flex h-9 w-9 items-center justify-center rounded-full border transition-colors hover:bg-(--surface-sunken)"
            style={({ isActive }) => ({
              borderColor: isActive ? 'var(--color-brand-500)' : 'var(--border-subtle)',
              backgroundColor: isActive
                ? 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)'
                : undefined,
            })}
          >
            {({ isActive }) => (
              <Calculator
                size={17}
                style={{ color: isActive ? 'var(--color-brand-600)' : 'var(--text-secondary)' }}
              />
            )}
          </NavLink>
        </Tooltip>

        <NotificationBell />

        <ThemeToggle />

        <div className="mx-1 h-7 w-px" style={{ backgroundColor: 'var(--border-subtle)' }} />

        <UserMenu />
      </div>
    </header>
  )
}
