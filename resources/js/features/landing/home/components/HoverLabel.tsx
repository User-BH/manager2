import type { ReactNode } from 'react'

/**
 * تولتیپِ سفارشیِ کنارِ دکمه‌های شناور.
 *
 * جایگزینِ attribute ‏`title` است؛ آن یکی تولتیپِ خاکستریِ سیستمی و بدریخت
 * می‌آورد و با تاخیر. این نسخه هم‌رنگِ سایت است، فوری با هاور می‌آید و چون
 * سمتِ راستِ صفحه‌ایم، به چپِ دکمه باز می‌شود.
 *
 * والدِ دکمه باید کلاسِ `group` داشته باشد تا هاورِ دکمه این را نشان دهد.
 */
export function HoverLabel({ children }: { children: ReactNode }) {
  return (
    <span
      role="tooltip"
      className="pointer-events-none absolute right-full top-1/2 mr-3 -translate-y-1/2 translate-x-1 whitespace-nowrap rounded-lg px-2.5 py-1 text-xs font-medium opacity-0 shadow-lg transition-all duration-200 group-hover:translate-x-0 group-hover:opacity-100"
      style={{
        backgroundColor: 'var(--text-primary)',
        color: 'var(--surface-base)',
      }}
    >
      {children}
    </span>
  )
}
