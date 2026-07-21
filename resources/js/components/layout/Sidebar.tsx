import { motion, AnimatePresence } from 'framer-motion'
import { ChevronsRight, X } from 'lucide-react'
import { useSidebar } from '@/context/SidebarContext'
import { SidebarContent } from './SidebarContent'

const EXPANDED_WIDTH = 272
const COLLAPSED_WIDTH = 76

export function Sidebar() {
  const { collapsed, toggleCollapsed, mobileOpen, setMobileOpen } = useSidebar()

  return (
    <>
      {/* بک‌دراپ موبایل */}
      <AnimatePresence>
        {mobileOpen && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={() => setMobileOpen(false)}
            className="fixed inset-0 z-40 bg-black/40 lg:hidden"
          />
        )}
      </AnimatePresence>

      {/* سایدبار دسکتاپ - ثابت سمت راست، عرضش انیمیت می‌شود */}
      <motion.aside
        animate={{ width: collapsed ? COLLAPSED_WIDTH : EXPANDED_WIDTH }}
        transition={{ type: 'spring', stiffness: 320, damping: 32 }}
        className="relative z-30 hidden h-screen shrink-0 flex-col border-l lg:flex"
        style={{ backgroundColor: 'var(--surface-base)', borderColor: 'var(--border-subtle)' }}
      >
        <SidebarContent collapsed={collapsed} />

        <button
          onClick={toggleCollapsed}
          className="absolute -left-3 top-7 flex h-6 w-6 items-center justify-center rounded-full border shadow-sm transition-colors hover:bg-(--surface-sunken)"
          style={{
            backgroundColor: 'var(--surface-base)',
            borderColor: 'var(--border-default)',
            color: 'var(--text-secondary)',
          }}
          aria-label={collapsed ? 'باز کردن منو' : 'جمع کردن منو'}
        >
          <motion.span
            animate={{ rotate: collapsed ? 180 : 0 }}
            transition={{ duration: 0.25 }}
            className="flex items-center justify-center"
          >
            <ChevronsRight size={14} />
          </motion.span>
        </button>
      </motion.aside>

      {/* سایدبار موبایل - drawer از سمت راست */}
      <AnimatePresence>
        {mobileOpen && (
          <motion.aside
            initial={{ x: '100%' }}
            animate={{ x: 0 }}
            exit={{ x: '100%' }}
            transition={{ type: 'spring', stiffness: 320, damping: 32 }}
            className="fixed inset-y-0 right-0 z-50 flex w-[272px] flex-col border-l lg:hidden"
            style={{ backgroundColor: 'var(--surface-base)', borderColor: 'var(--border-subtle)' }}
          >
            <div className="flex items-center justify-end px-3 pt-3">
              <button
                onClick={() => setMobileOpen(false)}
                className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                style={{ color: 'var(--text-secondary)' }}
                aria-label="بستن منو"
              >
                <X size={18} />
              </button>
            </div>
            <SidebarContent collapsed={false} onNavigate={() => setMobileOpen(false)} />
          </motion.aside>
        )}
      </AnimatePresence>
    </>
  )
}
