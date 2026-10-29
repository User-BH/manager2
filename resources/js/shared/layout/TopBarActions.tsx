import type { Ref } from 'react'
import { NavLink } from 'react-router-dom'
import { Calculator, CalendarDays, Search, X } from 'lucide-react'
import { NotificationBell } from './NotificationBell'
import { ThemeToggle } from './ThemeToggle'
import { UserMenu } from './UserMenu'
import { Tooltip } from '@/shared/ui/Tooltip'
import { topBarItems, type TopBarDashboard } from '@/shared/config/topBar'

/** شناسه‌ی پنلِ جست‌وجو برای `aria-controls` ِ آیکون */
export const SEARCH_PANEL_ID = 'top-bar-search-panel'

/**
 * ابزارهای سمتِ چپِ نوارِ بالا — یکی برای هر چهار داشبورد، از ماتریسِ `topBar.ts` (مرحلهٔ ۱۳ · فاز ۴؛ ایرادات ۱۱، بندِ
 * ۲.۱۰). داشبوردِ اولیه همین را با پوسته‌ی خودش می‌گیرد؛ سه داشبوردِ عضو در `Header`.
 *
 * هر آیکون نامِ دسترس‌پذیر و tooltip ِ خودش را دارد (بندِ ۲.۹)؛ اعلان، تم و آدمک این را درونِ خودشان دارند.
 */
export function TopBarActions({
  dashboard,
  searchOpen = false,
  onSearchToggle,
  searchToggleRef,
}: {
  dashboard: TopBarDashboard
  /** پنلِ جست‌وجو (فقط Header؛ داشبوردِ اولیه جست‌وجو ندارد) */
  searchOpen?: boolean
  onSearchToggle?: () => void
  searchToggleRef?: Ref<HTMLButtonElement>
}) {
  const items = topBarItems(dashboard)
  /*
   * نوارِ پُر در عرضِ گوشی (۲۰۲۶-۱۰-۲۹، بندِ ۸): هفت کنترلِ ۴۴ پیکسلیِ لمسی + آدمک در ‎۳۶۰px جا نمی‌شد و آدمک و منویش
   * از لبه‌ی چپ بیرون می‌زدند. زیرِ `sm` ماشین‌حساب و تم به منوی آدمک می‌روند (همان کار، همان مقصد)؛ منو، جست‌وجو، تقویم
   * (همیشه‌آیکون، بندِ ۶۷)، اعلان و آدمک در نوار می‌مانند. داشبوردِ اولیه سه ابزار دارد و دست نمی‌خورد.
   */
  const crowded = items.includes('calculator')
  const narrowTools = crowded
    ? { calculator: items.includes('calculator'), theme: items.includes('theme') }
    : undefined

  return (
    <div className="flex shrink-0 items-center gap-1.5 min-[400px]:gap-2">
      {/*
        آیکونِ جست‌وجو زیرِ `lg`، کنارِ بقیه‌ی آیکون‌ها (ایرادات ۱۰، بندهای ۵۴ تا ۵۸؛ از ۲۰۲۶-۱۰-۲۹ تا `lg`، نه `sm`: در
        عرضِ تبلت کادر و «جستجوهای اخیر» روی ماشین‌حساب و تقویم می‌افتادند — پنل همان «اخیر» را دارد): کادرِ کامل جای نوار را
        نمی‌گیرد؛ کلیک پنلِ زیرِ سربرگ را باز و دوباره‌کلیک می‌بندد.
      */}
      {items.includes('search') && onSearchToggle && (
        <div className="flex lg:hidden">
          <Tooltip label="جستجو">
            <button
              ref={searchToggleRef}
              type="button"
              onClick={onSearchToggle}
              aria-label="جستجو"
              aria-expanded={searchOpen}
              aria-controls={SEARCH_PANEL_ID}
              className="flex h-9 w-9 items-center justify-center rounded-full border transition-colors hover:bg-(--surface-sunken)"
              style={{
                borderColor: searchOpen ? 'var(--color-brand-500)' : 'var(--border-subtle)',
                color: searchOpen ? 'var(--color-brand-600)' : 'var(--text-secondary)',
              }}
            >
              {searchOpen ? (
                <X size={17} aria-hidden="true" />
              ) : (
                <Search size={17} aria-hidden="true" />
              )}
            </button>
          </Tooltip>
        </div>
      )}

      {/*
        ماشین حساب یک صفحه‌ی معمولی است نه پاپ‌آپ: با NavLink هم آدرسش قابل بوکمارک می‌شود و هم هدر و سایدبار سر
        جایشان می‌مانند و فقط وسط صفحه عوض می‌شود. تولتیپ سفارشی به‌جای title بومی، تا در تم تاریک هم جذاب باشد.
      */}
      {items.includes('calculator') && (
        <div className="hidden sm:flex">
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
        </div>
      )}

      {/* تقویم (۱۳/۷؛ ایرادات ۱۰، بندهای ۶۷ و ۷۰): آیکونِ همیشگی در هر عرض؛ تقویم در محتوای اصلی باز می‌شود */}
      {items.includes('calendar') && (
        <Tooltip label="تقویم و یادآورها">
          <NavLink
            to="/calendar"
            aria-label="تقویم"
            className="flex h-9 w-9 items-center justify-center rounded-full border transition-colors hover:bg-(--surface-sunken)"
            style={({ isActive }) => ({
              borderColor: isActive ? 'var(--color-brand-500)' : 'var(--border-subtle)',
              backgroundColor: isActive
                ? 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)'
                : undefined,
            })}
          >
            {({ isActive }) => (
              <CalendarDays
                size={17}
                style={{ color: isActive ? 'var(--color-brand-600)' : 'var(--text-secondary)' }}
              />
            )}
          </NavLink>
        </Tooltip>
      )}

      {items.includes('notifications') && <NotificationBell />}
      {items.includes('theme') &&
        (crowded ? (
          <div className="hidden sm:flex">
            <ThemeToggle />
          </div>
        ) : (
          <ThemeToggle />
        ))}

      {items.includes('account') && (
        <>
          <div
            className="mx-0.5 h-7 w-px min-[400px]:mx-1"
            style={{ backgroundColor: 'var(--border-subtle)' }}
          />
          <UserMenu narrowTools={narrowTools} />
        </>
      )}
    </div>
  )
}
