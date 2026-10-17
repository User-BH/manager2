import { useNavigate } from 'react-router-dom'
import { useSearch } from '@/shared/stores/searchStore'

/**
 * جعبه‌ی جست‌وجوی موبایل (۱۲/۸، بندِ ۵۱): نوارِ بالا در موبایل جای جعبه ندارد و پیوندِ «جستجوهای اخیر» به همین صفحه
 * می‌آید. همان `searchStore` و همان قاعده‌ها (کمینه‌ی دو نویسه، ثبت در تاریخچه، نتیجه در همین ناحیه‌ی محتوا).
 *
 * ⚠️ جدا از `SearchBox` ِ نوارِ بالا: بازاستفاده‌ی آن این‌جا چانکِ مشترکِ تازه‌ای (۲ کیلوبایت) روی مسیرِ همه‌ی صفحه‌ها
 * می‌ساخت و بودجه‌ی نشستِ باندل از سقف می‌گذشت (سنجیده).
 */
export function MobileSearchField() {
  const navigate = useNavigate()
  const { query, setQuery, results, rememberSearch } = useSearch()
  const term = query.trim()

  return (
    <form
      role="search"
      className="sm:hidden"
      onSubmit={(event) => {
        event.preventDefault()
        if (term.length < 2) return
        rememberSearch(term, results?.query === term ? results.total : 0)
        void navigate(`/search?q=${encodeURIComponent(term)}`)
      }}
    >
      <input
        type="search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder="جستجو در واحدها، ساکنین، قبوض..."
        aria-label="جستجو در واحدها، ساکنین و قبوض"
        enterKeyHint="search"
        className="field w-full rounded-xl px-3.5 py-2.5 text-[14px]"
      />
    </form>
  )
}
