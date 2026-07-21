import { NavLink } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/cn'

interface SidebarLinkProps {
  label: string
  path: string
  icon: LucideIcon
  collapsed: boolean
  onNavigate?: () => void
}

export function SidebarLink({ label, path, icon: Icon, collapsed, onNavigate }: SidebarLinkProps) {
  return (
    <NavLink
      to={path}
      onClick={onNavigate}
      title={collapsed ? label : undefined}
      className={({ isActive }) =>
        cn(
          'group relative flex items-center gap-3 rounded-xl px-2.5 py-2.5 text-[13.5px] font-medium transition-colors duration-150',
          collapsed && 'justify-center',
          !isActive && 'hover:bg-(--surface-sunken)',
        )
      }
      style={({ isActive }) => ({
        color: isActive ? 'var(--text-on-brand)' : 'var(--text-secondary)',
      })}
    >
      {({ isActive }) => (
        <>
          {isActive && (
            <motion.span
              layoutId="active-nav-pill"
              transition={{ type: 'spring', stiffness: 400, damping: 32 }}
              className="absolute inset-0 rounded-xl"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            />
          )}

          <span className="relative z-10 flex shrink-0 items-center justify-center transition-transform duration-200 group-hover:scale-110">
            <Icon size={19} strokeWidth={1.9} />
          </span>

          <AnimatePresence>
            {!collapsed && (
              <motion.span
                initial={{ opacity: 0, width: 0 }}
                animate={{ opacity: 1, width: 'auto' }}
                exit={{ opacity: 0, width: 0 }}
                transition={{ duration: 0.15 }}
                className="relative z-10 overflow-hidden whitespace-nowrap"
              >
                {label}
              </motion.span>
            )}
          </AnimatePresence>
        </>
      )}
    </NavLink>
  )
}
