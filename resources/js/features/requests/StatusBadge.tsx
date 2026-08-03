import { cn } from '@/shared/lib/cn'

/**
 * نشانِ رنگیِ وضعیت و فوریت (R25).
 *
 * رنگ از **سرور** می‌آید و نه از نگاشتِ کلاینتی: وضعیت‌ها در enum پی‌اچ‌پی
 * تعریف شده‌اند و اگر رنگشان اینجا هم تکرار می‌شد، افزودنِ وضعیتِ تازه یعنی
 * ویرایشِ دو فایل و فراموش‌کردنِ یکی.
 */
const TONES: Record<string, { bg: string; text: string }> = {
  sky: { bg: 'rgba(14,165,233,0.12)', text: '#0284c7' },
  amber: { bg: 'rgba(245,158,11,0.14)', text: '#b45309' },
  violet: { bg: 'rgba(139,92,246,0.14)', text: '#7c3aed' },
  emerald: { bg: 'rgba(16,185,129,0.14)', text: '#059669' },
  rose: { bg: 'rgba(244,63,94,0.12)', text: '#e11d48' },
  slate: { bg: 'rgba(100,116,139,0.12)', text: '#475569' },
}

export function StatusBadge({
  label,
  color,
  className,
}: {
  label: string
  color: string
  className?: string
}) {
  const tone = TONES[color] ?? TONES.slate

  return (
    <span
      className={cn(
        'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
        className,
      )}
      style={{ backgroundColor: tone.bg, color: tone.text }}
    >
      {label}
    </span>
  )
}
