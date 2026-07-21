import { useEffect } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  Building2,
  ChevronLeft,
  Clock,
  History,
  Megaphone,
  MessageSquare,
  Receipt,
  Search,
  SearchX,
  Trash2,
  Users,
  Wallet,
  X,
  type LucideIcon,
} from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { ErrorState, LoadingState } from '@/components/ui/PageState'
import { useSearch } from '@/context/SearchContext'
import { useDocumentTitle } from '@/hooks'
import { formatNumber, formatRelative } from '@/lib/format'
import type { RecentSearch, SearchGroup, SearchItem } from '@/types'

/** نگاشت نام آیکونی که سرور می‌فرستد به کامپوننت lucide. */
const GROUP_ICONS: Record<string, LucideIcon> = {
  Building2,
  Users,
  Receipt,
  Wallet,
  Megaphone,
  MessageSquare,
}

/**
 * صفحه‌ی «نتایج جستجو».
 *
 * فقط وسط صفحه است: هدر (با همان باکس جستجو) و سایدبار سر جای خودشان
 * می‌مانند چون این صفحه هم مثل بقیه داخل DashboardLayout رندر می‌شود.
 */
export function SearchResultsPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const urlQuery = params.get('q')?.trim() ?? ''

  const { query, setQuery, results, isSearching, error, recent, removeRecent, clearRecent } =
    useSearch()

  useDocumentTitle(urlQuery ? `نتایج جستجو: ${urlQuery}` : 'جستجوهای اخیر')

  /*
   * ورود مستقیم به این آدرس (رفرش، بوکمارک، یا کلیک روی «جستجوهای اخیر»)
   * باید همان جستجو را اجرا کند. با نوشتن عبارت در context، هم باکس هدر
   * پر می‌شود و هم درخواست خودکار زده می‌شود؛ پس اینجا fetch جدا لازم نیست.
   */
  useEffect(() => {
    if (urlQuery && urlQuery !== query.trim()) {
      setQuery(urlQuery)
    }
    // فقط با تغییر آدرس، نه با هر تایپ کاربر در هدر
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [urlQuery])

  const isStale = results?.query !== urlQuery
  const groups = isStale ? [] : (results?.groups ?? [])

  // بدون عبارت (مثلاً بعد از زدن ضربدرِ باکس جستجو) این صفحه جای «جستجوهای
  // اخیر» را می‌گیرد، تا کاربر روی یک صفحه‌ی خالی نیفتد.
  if (!urlQuery) {
    return (
      <RecentSearchesView
        recent={recent}
        onOpen={(q) => {
          setQuery(q)
          navigate(`/search?q=${encodeURIComponent(q)}`)
        }}
        onRemove={removeRecent}
        onClear={clearRecent}
      />
    )
  }

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="flex items-center gap-2 text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          <Search size={19} style={{ color: 'var(--color-brand-500)' }} />
          نتایج جستجو
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          <>
            برای عبارت{' '}
            <span className="font-bold" style={{ color: 'var(--text-secondary)' }}>
              «{urlQuery}»
            </span>
            {!isStale && results && ` — ${formatNumber(results.total)} مورد یافت شد`}
          </>
        </p>
      </header>

      {(isSearching || (urlQuery && isStale && !error)) && <LoadingState rows={4} />}
      {error && <ErrorState message={error} onRetry={() => setQuery(urlQuery)} />}

      {!isSearching && !error && !isStale && groups.length === 0 && urlQuery && (
        <Card>
          <div className="flex flex-col items-center gap-3 py-10 text-center">
            <SearchX size={34} style={{ color: 'var(--text-tertiary)' }} />
            <p className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>
              چیزی با «{urlQuery}» پیدا نشد.
            </p>
            <p className="max-w-md text-[12.5px] leading-7" style={{ color: 'var(--text-tertiary)' }}>
              می‌توانید شماره‌ی واحد، نام یا شماره‌ی تلفن ساکن، دوره‌ی قبض (مثل ۱۴۰۴-۰۳)،
              عنوان اطلاعیه یا متن پیام را جستجو کنید.
            </p>
          </div>
        </Card>
      )}

      {!isSearching &&
        !isStale &&
        groups.map((group, index) => (
          <GroupCard key={group.id} group={group} delay={index * 0.06} onOpen={navigate} />
        ))}
    </div>
  )
}

/**
 * نمای «جستجوهای اخیر» وقتی عبارتی در آدرس نیست.
 *
 * همان داده‌ی سایدبار است، ولی اینجا بزرگ‌تر و کامل‌تر تا مقصدِ زدنِ
 * ضربدرِ باکس جستجو یک صفحه‌ی معنادار باشد نه خالی.
 */
function RecentSearchesView({
  recent,
  onOpen,
  onRemove,
  onClear,
}: {
  recent: RecentSearch[]
  onOpen: (query: string) => void
  onRemove: (query: string) => void
  onClear: () => void
}) {
  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1
            className="flex items-center gap-2 text-xl font-extrabold"
            style={{ color: 'var(--text-primary)' }}
          >
            <History size={19} style={{ color: 'var(--color-brand-500)' }} />
            جستجوهای اخیر
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            {recent.length > 0
              ? `${formatNumber(recent.length)} جستجوی اخیر`
              : 'هنوز چیزی جستجو نکرده‌اید.'}
          </p>
        </div>

        {recent.length > 0 && (
          <button
            onClick={onClear}
            className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-[13px] font-semibold"
            style={{ borderColor: 'var(--border-default)', color: 'var(--color-danger)' }}
          >
            <Trash2 size={15} />
            پاک کردن همه
          </button>
        )}
      </header>

      {recent.length === 0 ? (
        <Card>
          <div className="flex flex-col items-center gap-3 py-12 text-center">
            <SearchX size={34} style={{ color: 'var(--text-tertiary)' }} />
            <p className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>
              تاریخچه‌ی جستجو خالی است.
            </p>
            <p className="max-w-md text-[12.5px] leading-7" style={{ color: 'var(--text-tertiary)' }}>
              از باکس جستجوی بالای صفحه دنبال واحد، ساکن، قبض یا اطلاعیه بگردید؛
              نتایج جستجوهای شما اینجا نگهداری می‌شود.
            </p>
          </div>
        </Card>
      ) : (
        <Card>
          <ul className="flex flex-col">
            {recent.map((item, index) => (
              <motion.li
                key={item.query}
                initial={{ opacity: 0, x: 8 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.2, delay: Math.min(index * 0.03, 0.3) }}
                className="group flex items-center gap-2 border-b last:border-b-0"
                style={{ borderColor: 'var(--border-subtle)' }}
              >
                <button
                  onClick={() => onOpen(item.query)}
                  className="flex flex-1 items-center gap-3 rounded-xl px-2 py-3 text-right transition-colors hover:bg-(--surface-sunken)"
                >
                  <Search size={15} className="shrink-0" style={{ color: 'var(--color-brand-500)' }} />
                  <span className="min-w-0 flex-1">
                    <span
                      className="block truncate text-[13.5px] font-semibold"
                      style={{ color: 'var(--text-primary)' }}
                    >
                      {item.query}
                    </span>
                    <span
                      className="mt-0.5 flex items-center gap-1.5 text-[11px]"
                      style={{ color: 'var(--text-tertiary)' }}
                    >
                      <span>{formatNumber(item.total)} نتیجه</span>
                      <span>·</span>
                      <span className="flex items-center gap-1">
                        <Clock size={10} />
                        {formatRelative(item.at)}
                      </span>
                    </span>
                  </span>
                  <ChevronLeft size={15} className="shrink-0" style={{ color: 'var(--text-tertiary)' }} />
                </button>

                <button
                  onClick={() => onRemove(item.query)}
                  aria-label={`حذف «${item.query}» از تاریخچه`}
                  className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  <X size={14} />
                </button>
              </motion.li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

function GroupCard({
  group,
  delay,
  onOpen,
}: {
  group: SearchGroup
  delay: number
  onOpen: (path: string) => void
}) {
  const Icon = GROUP_ICONS[group.icon] ?? Search

  return (
    <Card delay={delay}>
      <div className="mb-3 flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-[14px] font-bold" style={{ color: 'var(--text-primary)' }}>
          <Icon size={16} style={{ color: 'var(--color-brand-500)' }} />
          {group.title}
          <span
            className="rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums"
            style={{
              backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 13%, transparent)',
              color: 'var(--color-brand-600)',
            }}
          >
            {formatNumber(group.count)}
          </span>
        </h2>

        <Link
          to={group.path}
          className="flex items-center gap-1 text-[12px] font-semibold"
          style={{ color: 'var(--color-brand-600)' }}
        >
          رفتن به {group.title}
          <ChevronLeft size={13} />
        </Link>
      </div>

      <ul className="flex flex-col gap-1">
        {group.items.map((item, index) => (
          <ResultRow key={`${group.id}-${item.id}`} item={item} delay={index * 0.03} onOpen={onOpen} />
        ))}
      </ul>
    </Card>
  )
}

function ResultRow({
  item,
  delay,
  onOpen,
}: {
  item: SearchItem
  delay: number
  onOpen: (path: string) => void
}) {
  return (
    <motion.li
      initial={{ opacity: 0, x: 8 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.22, delay: Math.min(delay, 0.3) }}
    >
      <button
        onClick={() => onOpen(item.path)}
        className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-right transition-colors hover:bg-(--surface-sunken)"
      >
        <div className="min-w-0 flex-1">
          <p className="truncate text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>
            {item.title}
          </p>
          <p className="truncate text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
            {item.subtitle}
          </p>
        </div>

        {item.badge && (
          <span
            className="shrink-0 rounded-lg px-2 py-0.5 text-[10.5px] font-medium tabular-nums"
            style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
          >
            {item.badge}
          </span>
        )}

        <ChevronLeft size={14} className="shrink-0" style={{ color: 'var(--text-tertiary)' }} />
      </button>
    </motion.li>
  )
}
