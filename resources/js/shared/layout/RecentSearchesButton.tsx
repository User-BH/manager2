import { Link, useLocation } from 'react-router-dom'
import { History, Search } from 'lucide-react'
import { useSearch } from '@/shared/stores/searchStore'
import { formatNumber } from '@/shared/lib/format'

/**
 * «جست‌وجوهای اخیر» در نوارِ بالا، کنارِ جعبه‌ی جست‌وجو — مرحلهٔ ۱۲ · فاز ۸ (بندهای ۵۰ تا ۵۲ و ۵۵ ِ سندِ هسته).
 *
 * تا ۱۲/۸ فهرست پایینِ سایدبار بود. حالا یک کنترلِ مشخص در نوارِ بالا که فهرست را در **ناحیه‌ی محتوا** باز می‌کند
 * (`/search` بی عبارت — `SearchResultsPage`)، همان‌جا که نتیجه‌ی جست‌وجو هم می‌آید؛ نه در سایدبار و نه در پنجره‌ی
 * کوچک. حذفِ تکی، «پاک کردن همه» و حالتِ خالی همان صفحه است.
 *
 * موبایل (بندِ ۵۱): جعبه‌ی جست‌وجو در نوار جا نمی‌شود؛ همین پیوند با نشانِ ذره‌بین همان صفحه را باز می‌کند که جعبه‌ی
 * خودش را دارد. ⚠️ یک پیوند برای هر دو اندازه، نه دو: نوارِ بالا در باندلِ همه‌ی صفحه‌هاست (بودجه‌ی نشست).
 */
export function RecentSearchesButton() {
  const { recent } = useSearch()
  const { pathname, search } = useLocation()
  // فعال: صفحه‌ی جست‌وجوهای اخیر (بی عبارت)، نه صفحه‌ی نتیجه
  const active = pathname === '/search' && !new URLSearchParams(search).get('q')
  const count = recent.length > 0 ? formatNumber(recent.length) : null

  return (
    <Link
      to="/search"
      aria-current={active ? 'page' : undefined}
      aria-label={count ? `جستجوهای اخیر (${count})` : 'جستجوهای اخیر'}
      className="flex h-9 shrink-0 items-center gap-1.5 rounded-xl border px-2 text-[13px] font-semibold hover:bg-(--surface-sunken)"
      style={{
        borderColor: active ? 'var(--color-brand-500)' : 'var(--border-subtle)',
        color: active ? 'var(--color-brand-600)' : 'var(--text-secondary)',
      }}
    >
      <Search size={19} className="sm:hidden" aria-hidden="true" />
      <History size={16} className="hidden sm:block" aria-hidden="true" />
      <span className="hidden sm:block">جستجوهای اخیر</span>
      {count && (
        <span className="hidden text-[10px] tabular-nums sm:block" aria-hidden="true">
          {count}
        </span>
      )}
    </Link>
  )
}
