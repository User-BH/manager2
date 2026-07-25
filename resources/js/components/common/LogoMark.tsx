interface LogoMarkProps {
  size?: number
  className?: string
  /** روی پس‌زمینه‌های تیره/رنگی، لوگو یک‌دستِ سفید می‌شود تا واضح بماند. */
  monochrome?: boolean
}

/**
 * نشانِ ساکنا: تصویرِ برند (پیچکِ سبز دورِ ساختمان با آسانسور).
 *
 * فایلِ `public/logo.webp` است (پس‌زمینه‌ی شفاف). روی زمینه‌های رنگی با یک
 * فیلتر به سفیدِ یک‌دست تبدیل می‌شود تا مثل متنِ کنارش دیده شود.
 */
export function LogoMark({ size = 36, className, monochrome = false }: LogoMarkProps) {
  return (
    <img
      src="/logo.webp"
      width={size}
      height={size}
      alt="نشان ساکنا"
      loading="eager"
      decoding="async"
      className={className}
      style={{
        objectFit: 'contain',
        filter: monochrome ? 'brightness(0) invert(1)' : undefined,
      }}
    />
  )
}
