import { Suspense, lazy, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { AnimatePresence } from 'framer-motion'
import { Menu } from 'lucide-react'
import { SearchBox } from './SearchBox'
import { RecentSearchesButton } from './RecentSearchesButton'
import { TopBarActions } from './TopBarActions'
import { topBarDashboardOf, topBarItems } from '@/shared/config/topBar'
import { useAuth } from '@/shared/stores/authStore'
import { useSidebar } from '@/shared/stores/sidebarStore'

// پنلِ جست‌وجوی عرضِ کم تنبل است: تا کاربر آیکون را نزند بار نمی‌شود (۱۳/۴)
const SearchPanel = lazy(() => import('./SearchPanel').then((m) => ({ default: m.SearchPanel })))

/**
 * نوارِ بالای سه داشبوردِ عضو — واحد، مدیر، سوپر ادمین. ابزارها از ماتریسِ `topBar.ts` (مرحلهٔ ۱۳ · فاز ۴)؛ داشبوردِ
 * اولیه همان `TopBarActions` را با پوسته‌ی خودش دارد.
 */
export function Header() {
  const { setMobileOpen } = useSidebar()
  const { user } = useAuth()
  const dashboard = topBarDashboardOf(user)
  const items = topBarItems(dashboard)
  const { pathname, search } = useLocation()
  /*
   * پنل برای «همین نشانی» باز است: رفتن به صفحه‌ی دیگر (از جمله نتیجه‌ی جست‌وجو) خودبه‌خود می‌بندد — نتیجه در محتوای
   * اصلی است، نه زیرِ پنل.
   */
  const here = pathname + search
  const [openAt, setOpenAt] = useState<string | null>(null)
  const searchOpen = openAt === here
  const toggleRef = useRef<HTMLButtonElement>(null)

  function closeSearch(returnFocus: boolean) {
    setOpenAt(null)
    // بستن با × یا Escape فوکوس را به همان آیکون برمی‌گرداند، نه به آغازِ صفحه
    if (returnFocus) toggleRef.current?.focus()
  }

  return (
    /*
     * لایه‌ی سربرگ: z-45 («چند نکته»، بندِ ۵).
     *
     * `sticky` + `z-index` و `backdrop-blur` هر کدام سربرگ را یک stacking context می‌کنند؛ پس `z-40` ِ باکسِ اعلان و
     * منوی کاربر فقط **درونِ** سربرگ معنا دارد و از بیرون همه با z ِ خودِ سربرگ سنجیده می‌شوند. سربرگ z-20 بود و
     * نمایشگرِ چسبانِ ماشین‌حساب هم z-20 و بعدتر در DOM — برابری با ترتیب شکسته می‌شد و ماشین‌حساب روی باکس می‌نشست.
     * (`backdrop-filter` حتی `fixed` ِ موبایلِ باکس را هم به سربرگ می‌بندد.) پنلِ جست‌وجوی عرضِ کم هم درونِ همین لایه
     * است (۱۳/۴).
     *
     * ترتیبِ لایه‌ها: محتوای صفحه ≤ z-30 · کارتِ نصب (پایینِ صفحه) z-40 · سربرگ و باکس‌هایش z-45 · پوشش‌های مسدودکننده
     * (Modal، کشوی موبایل، نوارِ اتصال) z-50. `overlayLayers.test.ts` نمی‌گذارد محتوای صفحه به لایه‌ی سربرگ برسد.
     */
    <header
      className="sticky top-0 z-45 flex h-16 items-center gap-2 border-b px-3 backdrop-blur min-[400px]:gap-3 min-[400px]:px-4 sm:px-6"
      style={{
        backgroundColor: 'color-mix(in srgb, var(--surface-base) 92%, transparent)',
        borderColor: 'var(--border-subtle)',
      }}
    >
      <button
        onClick={() => setMobileOpen(true)}
        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition-colors hover:bg-(--surface-sunken) lg:hidden"
        style={{ color: 'var(--text-secondary)' }}
        aria-label="باز کردن منو"
      >
        <Menu size={20} />
      </button>

      {/* جست‌وجو و «جست‌وجوهای اخیر» کنارِ هم در عرضِ بزرگ (۱۲/۸، بندِ ۵۱)؛ در عرضِ کم آیکون و پنل (۱۳/۴) */}
      <div className="flex min-w-0 flex-1 items-center gap-2">
        {items.includes('search') && <SearchBox />}
        {items.includes('recentSearches') && <RecentSearchesButton />}
      </div>

      <TopBarActions
        dashboard={dashboard}
        searchOpen={searchOpen}
        onSearchToggle={() => setOpenAt(searchOpen ? null : here)}
        searchToggleRef={toggleRef}
      />

      <AnimatePresence>
        {searchOpen && (
          <Suspense fallback={null}>
            <SearchPanel onClose={closeSearch} toggleRef={toggleRef} />
          </Suspense>
        )}
      </AnimatePresence>
    </header>
  )
}
