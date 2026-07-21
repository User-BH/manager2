interface LogoMarkProps {
  size?: number
  className?: string
  /** اگر true باشد، کل نشان با یک رنگ یکدست (سفید) رندر می‌شود - برای استفاده روی پس‌زمینه‌های تیره/تصویری */
  monochrome?: boolean
}

/**
 * مارک لوگو: ترکیب شکل سپر (امنیت و مدیریت) با نمای ساده‌شده‌ی یک ساختمان مسکونی.
 * فقط با رنگ‌های برند (var(--color-brand-*)) کار می‌کند تا در دارک/لایت‌مود هماهنگ بماند.
 */
export function LogoMark({ size = 36, className, monochrome = false }: LogoMarkProps) {
  if (monochrome) {
    return (
      <svg
        width={size}
        height={size}
        viewBox="0 0 48 48"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        className={className}
        role="img"
        aria-label="نشان ساکنا"
      >
        <path
          d="M24 2.5L42 9.5V22C42 33.5 34.7 42 24 45.5C13.3 42 6 33.5 6 22V9.5L24 2.5Z"
          fill="white"
          fillOpacity="0.16"
        />
        <path
          d="M24 2.5L42 9.5V22C42 33.5 34.7 42 24 45.5C13.3 42 6 33.5 6 22V9.5L24 2.5Z"
          stroke="white"
          strokeOpacity="0.55"
          strokeWidth="1.2"
        />
        <rect x="15.5" y="20" width="17" height="16.5" rx="1.5" fill="white" />
        <rect x="18" y="23" width="3" height="3" rx="0.6" fill="white" fillOpacity="0.3" />
        <rect x="22.5" y="23" width="3" height="3" rx="0.6" fill="white" fillOpacity="0.3" />
        <rect x="27" y="23" width="3" height="3" rx="0.6" fill="white" fillOpacity="0.3" />
        <rect x="18" y="27.5" width="3" height="3" rx="0.6" fill="white" fillOpacity="0.3" />
        <rect x="22.5" y="27.5" width="3" height="3" rx="0.6" fill="white" fillOpacity="0.3" />
        <rect x="27" y="27.5" width="3" height="3" rx="0.6" fill="white" fillOpacity="0.3" />
        <rect x="22" y="32" width="4" height="4.5" rx="0.6" fill="white" fillOpacity="0.3" />
      </svg>
    )
  }

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 48 48"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={className}
      role="img"
      aria-label="نشان ساکنا"
    >
      <path
        d="M24 2.5L42 9.5V22C42 33.5 34.7 42 24 45.5C13.3 42 6 33.5 6 22V9.5L24 2.5Z"
        fill="var(--color-brand-500)"
      />
      <path
        d="M24 2.5L42 9.5V22C42 33.5 34.7 42 24 45.5V2.5Z"
        fill="var(--color-brand-600)"
      />

      <rect x="15.5" y="20" width="17" height="16.5" rx="1.5" fill="white" fillOpacity="0.95" />

      <rect x="18" y="23" width="3" height="3" rx="0.6" fill="var(--color-brand-600)" />
      <rect x="22.5" y="23" width="3" height="3" rx="0.6" fill="var(--color-brand-600)" />
      <rect x="27" y="23" width="3" height="3" rx="0.6" fill="var(--color-brand-600)" />
      <rect x="18" y="27.5" width="3" height="3" rx="0.6" fill="var(--color-brand-600)" />
      <rect x="22.5" y="27.5" width="3" height="3" rx="0.6" fill="var(--color-brand-600)" />
      <rect x="27" y="27.5" width="3" height="3" rx="0.6" fill="var(--color-brand-600)" />

      <rect x="22" y="32" width="4" height="4.5" rx="0.6" fill="var(--color-brand-600)" />
    </svg>
  )
}
