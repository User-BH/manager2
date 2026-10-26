import { Link, useLocation } from 'react-router-dom'
import { History } from 'lucide-react'
import { useSearch } from '@/shared/stores/searchStore'
import { formatNumber } from '@/shared/lib/format'

/**
 * «جست‌وجوهای اخیر» در نوارِ بالا، کنارِ جعبه‌ی جست‌وجو — مرحلهٔ ۱۲ · فاز ۸ (بندهای ۵۰ تا ۵۲ و ۵۵ ِ سندِ هسته).
 *
 * تا ۱۲/۸ فهرست پایینِ سایدبار بود. حالا یک کنترلِ مشخص در نوارِ بالا که فهرست را در **ناحیه‌ی محتوا** باز می‌کند
 * (`/search` بی عبارت — `SearchResultsPage`)، همان‌جا که نتیجه‌ی جست‌وجو هم می‌آید؛ نه در سایدبار و نه در پنجره‌ی
 * کوچک. حذفِ تکی، «پاک کردن همه» و حالتِ خالی همان صفحه است.
 *
 * عرضِ کم (از ۱۳/۴): این پیوند پنهان است؛ آیکونِ «جستجو» پنلِ زیرِ سربرگ را باز می‌کند که چند جست‌وجوی آخر و پیوندِ
 * همین صفحه را دارد (ایرادات ۱۰، بندهای ۵۴ تا ۶۰). تا ۱۳/۴ همین پیوند با نشانِ ذره‌بین به صفحه‌ی جست‌وجو می‌برد.
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
      className="hidden h-9 shrink-0 items-center gap-1.5 rounded-xl border px-2 text-[13px] font-semibold hover:bg-(--surface-sunken) sm:flex"
      style={{
        borderColor: active ? 'var(--color-brand-500)' : 'var(--border-subtle)',
        color: active ? 'var(--color-brand-600)' : 'var(--text-secondary)',
      }}
    >
      <History size={16} aria-hidden="true" />
      <span>جستجوهای اخیر</span>
      {count && (
        <span className="text-[10px] tabular-nums" aria-hidden="true">
          {count}
        </span>
      )}
    </Link>
  )
}
