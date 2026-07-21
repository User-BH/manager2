import { LogoMark } from './LogoMark'
import { cn } from '@/lib/cn'
import { BRAND_NAME } from '@/config/brand'

interface LogoProps {
  size?: number
  showText?: boolean
  className?: string
  textClassName?: string
}

export function Logo({ size = 36, showText = true, className, textClassName }: LogoProps) {
  return (
    <div className={cn('flex items-center gap-2.5', className)}>
      <LogoMark size={size} />
      {showText && (
        <div className={cn('leading-tight', textClassName)}>
          <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
            {BRAND_NAME}
          </p>
        </div>
      )}
    </div>
  )
}
