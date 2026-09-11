import { cn } from '@/shared/lib/cn'

/**
 * نشانِ رنگیِ وضعیت و فوریت (R25).
 *
 * رنگ از **سرور** می‌آید و نه از نگاشتِ کلاینتی: وضعیت‌ها در enum پی‌اچ‌پی
 * تعریف شده‌اند و اگر رنگشان اینجا هم تکرار می‌شد، افزودنِ وضعیتِ تازه یعنی
 * ویرایشِ دو فایل و فراموش‌کردنِ یکی.
 */
/**
 * ─── 🔴 چرا دیگر hex نیست (مرحلهٔ ۵، فاز ۵ — بند ۴۹) ────────────────────
 * پیش از این هر رنگ یک hexِ ثابت بود: `#475569` برای متنِ خاکستری، `#b45309`
 * برای کهربایی. در حالتِ تیره، همین‌ها روی پس‌زمینهٔ تیره می‌نشستند و
 * کنتراست از دست می‌رفت — و هیچ تستی نمی‌دیدش چون فقط در تمِ تیره رخ می‌داد.
 *
 * ⚠️ در همان حال، `app.css` یک نظامِ کاملِ `--tone-*-bg/fg` داشت که در `.dark`
 * بازتعریف می‌شود. این فایل آن را دور زده بود.
 *
 * نامِ رنگ همچنان از سرور می‌آید؛ فقط نگاشتش به توکن رفت.
 */
const TONES: Record<string, { bg: string; text: string }> = {
  sky: { bg: 'var(--tone-info-bg)', text: 'var(--tone-info-fg)' },
  amber: { bg: 'var(--tone-warning-bg)', text: 'var(--tone-warning-fg)' },
  violet: { bg: 'var(--tone-accent-bg)', text: 'var(--tone-accent-fg)' },
  emerald: { bg: 'var(--tone-success-bg)', text: 'var(--tone-success-fg)' },
  rose: { bg: 'var(--tone-danger-bg)', text: 'var(--tone-danger-fg)' },
  slate: { bg: 'var(--tone-neutral-bg)', text: 'var(--tone-neutral-fg)' },
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
