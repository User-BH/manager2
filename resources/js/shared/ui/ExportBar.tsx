import type { ReactNode } from 'react'
import { FileText, Printer } from 'lucide-react'

export interface ExportLink {
  label: string
  href: string
}

/**
 * نوارِ خروجی و چاپ (R28).
 *
 * ─── چرا لینکِ ساده و نه fetch ─────────────────────────────────────────────
 * مسیرهای PDF روی گروهِ `web` هستند و نشستِ کوکی احراز هویت‌شان می‌کند، پس
 * یک `<a>` معمولی کافی است. با `fetch` باید blob و object URL می‌ساختیم و
 * بعد آزادش می‌کردیم — کدِ بیشتر برای همان نتیجه، به‌علاوه‌ی نگه‌داشتنِ کلِ
 * فایل در حافظه.
 *
 * ─── چرا `window.print` و نه یک صفحه‌ی چاپیِ جدا ───────────────────────────
 * قواعدِ `@media print` در `app.css` همین صفحه را برای کاغذ آماده می‌کنند؛
 * صفحه‌ی دومی یعنی دو جا باید هم‌زمان به‌روز بماند.
 */
export function ExportBar({
  links = [],
  children,
  onPrint,
}: {
  links?: ExportLink[]
  children?: ReactNode
  /** اگر داده شود جای `window.print` می‌نشیند؛ برای صفحه‌هایی که پیش از چاپ کاری دارند. */
  onPrint?: () => void
}) {
  return (
    // خودِ نوار هرگز چاپ نمی‌شود — دکمه‌ی «چاپ» روی کاغذ بی‌معناست
    <div className="no-print flex flex-wrap items-center gap-2">
      {links.map((link) => (
        <a
          key={link.href}
          href={link.href}
          className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-[12.5px] transition-colors"
          style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
        >
          <FileText size={13} />
          {link.label}
        </a>
      ))}

      {children}

      <button
        type="button"
        onClick={() => (onPrint ? onPrint() : window.print())}
        className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-[12.5px] transition-colors"
        style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
      >
        <Printer size={13} />
        چاپ
      </button>
    </div>
  )
}
