import { useEffect } from 'react'

import { formatNumber } from '@/shared/lib/format'

/**
 * صفحه‌بندِ مشترکِ فهرست‌های صفحه‌بندی‌شده (مرحلهٔ ۵ · فاز ۶ · بندِ ۵۰).
 *
 * 🔴 چرا ساخته شد: هفت مسیرِ API فهرستشان را صفحه‌بندی می‌کنند، ولی شش
 * صفحه‌ی مصرف‌کننده فقط صفحه‌ی اول را می‌خواستند و راهی به بقیه نداشتند —
 * واحدها، ساکنین، قبض‌های من، اطلاعیه‌ها، درخواست‌های خدمات و اعضا. سربرگ
 * «۴۰ واحد ثبت شده» می‌گفت و فقط ۲۰ ردیف نشان می‌داد؛ بیست‌وچند تای دیگر جز
 * با جست‌وجو پیدا نمی‌شدند. تنها صفحه‌ی درست (مجتمع‌ها) صفحه‌بندِ درون‌خطیِ
 * خودش را داشت؛ همان الگو این‌جا یک بار نوشته شد.
 *
 * ⚠️ وقتی فقط یک صفحه هست چیزی رسم نمی‌شود: دکمه‌های همیشه‌خاموش شلوغی‌اند.
 */
export interface PageMeta {
  currentPage: number
  lastPage: number
  total: number
}

interface PagerProps {
  meta: PageMeta
  onChange: (page: number) => void
  /** واحدِ شمارش در متن، مثلاً «واحد» یا «درخواست». */
  noun: string
}

export function Pager({ meta, onChange, noun }: PagerProps) {
  const { currentPage, lastPage, total } = meta

  /*
   * ⚠️ صفحه‌ای که دیگر وجود ندارد.
   *
   * حذفِ آخرین ردیفِ صفحه‌ی آخر، یا جست‌وجویی که نتیجه را کوچک کند، صفحه‌ی
   * فعلی را از انتها بیرون می‌برد؛ لاراول آن‌وقت فهرستِ خالی برمی‌گرداند و
   * کاربر «موردی نیست» می‌بیند در حالی که صفحه‌های قبلی پُرند.
   */
  useEffect(() => {
    if (lastPage >= 1 && currentPage > lastPage) onChange(lastPage)
  }, [currentPage, lastPage, onChange])

  if (lastPage <= 1) return null

  const buttonClass = 'rounded-lg border px-3 py-1.5 text-small disabled:opacity-40'
  const buttonStyle = { borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }

  return (
    <nav aria-label="صفحه‌بندی" className="mt-4 flex items-center justify-center gap-3">
      <button
        type="button"
        onClick={() => onChange(Math.max(1, currentPage - 1))}
        disabled={currentPage <= 1}
        className={buttonClass}
        style={buttonStyle}
      >
        قبلی
      </button>

      <span
        className="text-small tabular-nums"
        style={{ color: 'var(--text-tertiary)' }}
        aria-live="polite"
      >
        صفحه‌ی {formatNumber(currentPage)} از {formatNumber(lastPage)} · {formatNumber(total)}{' '}
        {noun}
      </span>

      <button
        type="button"
        onClick={() => onChange(Math.min(lastPage, currentPage + 1))}
        disabled={currentPage >= lastPage}
        className={buttonClass}
        style={buttonStyle}
      >
        بعدی
      </button>
    </nav>
  )
}
