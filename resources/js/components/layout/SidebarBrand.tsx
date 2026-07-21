import { AnimatePresence, motion } from 'framer-motion'
import { LogoMark } from '@/components/common/LogoMark'
import { BRAND_NAME } from '@/config/brand'
import { useAuth } from '@/context/AuthContext'
import { cn } from '@/lib/cn'

export function SidebarBrand({ collapsed }: { collapsed: boolean }) {
  const { user } = useAuth()

  return (
    <div
      className={cn('flex h-16 items-center gap-2.5 border-b px-4', collapsed && 'justify-center px-0')}
      style={{ borderColor: 'var(--border-subtle)' }}
    >
      <LogoMark size={34} className="shrink-0" />

      <AnimatePresence>
        {!collapsed && (
          <motion.div
            initial={{ opacity: 0, width: 0 }}
            animate={{ opacity: 1, width: 'auto' }}
            exit={{ opacity: 0, width: 0 }}
            transition={{ duration: 0.18 }}
            className="overflow-hidden whitespace-nowrap"
          >
            <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
              {BRAND_NAME}
            </p>
            <p className="truncate text-xs" style={{ color: 'var(--text-tertiary)' }}>
              {user?.complex?.name ?? 'پنل مدیریتی ساکنین'}
            </p>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
