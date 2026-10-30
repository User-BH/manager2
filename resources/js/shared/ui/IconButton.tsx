import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { cn } from '@/shared/lib/cn'

interface IconButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  children: ReactNode
  variant?: 'ghost' | 'outline'
  /** کلاس‌های اندازه؛ پیش‌فرض ۳۶px (نوارِ بالا اندازه‌ی واکنش‌گرای خودش را می‌دهد، `TOOL_SIZE`) */
  sizeClass?: string
}

export function IconButton({
  children,
  variant = 'ghost',
  sizeClass = 'h-9 w-9',
  className,
  ...props
}: IconButtonProps) {
  return (
    <button
      className={cn(
        'flex items-center justify-center rounded-full transition-colors duration-200 hover:bg-(--surface-sunken)',
        sizeClass,
        variant === 'outline' && 'border',
        className,
      )}
      style={{ borderColor: variant === 'outline' ? 'var(--border-subtle)' : undefined }}
      {...props}
    >
      {children}
    </button>
  )
}
