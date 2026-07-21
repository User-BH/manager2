import { AnimatePresence, motion } from 'framer-motion'
import { SidebarLink } from './SidebarLink'
import { cn } from '@/lib/cn'
import type { NavSection } from '@/types'

interface SidebarSectionProps {
  section: NavSection
  collapsed: boolean
  isFirst: boolean
  onNavigate?: () => void
}

export function SidebarSection({ section, collapsed, isFirst, onNavigate }: SidebarSectionProps) {
  return (
    <div className={cn(!isFirst && 'mt-5')}>
      <AnimatePresence>
        {!collapsed && (
          <motion.p
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="mb-1.5 px-2.5 text-[11px] font-semibold tracking-wide"
            style={{ color: 'var(--text-tertiary)' }}
          >
            {section.title}
          </motion.p>
        )}
      </AnimatePresence>

      <ul className="flex flex-col gap-0.5">
        {section.items.map((item) => (
          <li key={item.path}>
            <SidebarLink
              label={item.label}
              path={item.path}
              icon={item.icon}
              collapsed={collapsed}
              onNavigate={onNavigate}
            />
          </li>
        ))}
      </ul>
    </div>
  )
}
