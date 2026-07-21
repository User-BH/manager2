import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface IconButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  children: ReactNode
  variant?: 'ghost' | 'outline'
}

export function IconButton({ children, variant = 'ghost', className, ...props }: IconButtonProps) {
  return (
    <button
      className={cn(
        'flex h-9 w-9 items-center justify-center rounded-full transition-colors duration-200 hover:bg-(--surface-sunken)',
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
