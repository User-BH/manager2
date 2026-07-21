import { useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import { ChevronDown, LogOut, Settings, UserRound, type LucideIcon } from 'lucide-react'
import { useToggle, useClickOutside } from '@/hooks'
import { useAuth } from '@/context/AuthContext'

export function UserMenu() {
  const [open, , setOpen] = useToggle(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()
  const { user, logout } = useAuth()

  useClickOutside(menuRef, () => setOpen(false))

  async function handleLogout() {
    await logout()
    setOpen(false)
    navigate('/auth', { replace: true })
  }

  return (
    <div className="relative" ref={menuRef}>
      <button
        onClick={() => setOpen((prev) => !prev)}
        className="flex items-center gap-2 rounded-xl border py-1.5 pr-1.5 pl-2.5 transition-colors duration-200 hover:bg-(--surface-sunken)"
        style={{ borderColor: 'var(--border-subtle)' }}
      >
        <div className="text-right">
          <p className="text-[13px] font-semibold leading-tight" style={{ color: 'var(--text-primary)' }}>
            {user?.name ?? 'کاربر'}
          </p>
          <p className="text-[11px] leading-tight" style={{ color: 'var(--text-tertiary)' }}>
            {user?.roleLabel ?? ''}
          </p>
        </div>

        <div
          className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <UserRound size={16} />
        </div>

        <motion.span
          animate={{ rotate: open ? 180 : 0 }}
          transition={{ duration: 0.2 }}
          style={{ color: 'var(--text-tertiary)' }}
        >
          <ChevronDown size={15} />
        </motion.span>
      </button>

      <AnimatePresence>
        {open && (
          <motion.div
            initial={{ opacity: 0, y: -6, scale: 0.97 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -6, scale: 0.97 }}
            transition={{ duration: 0.16 }}
            className="absolute left-0 top-[calc(100%+8px)] z-40 w-48 overflow-hidden rounded-xl border shadow-lg"
            style={{ backgroundColor: 'var(--surface-raised)', borderColor: 'var(--border-subtle)' }}
          >
            <UserMenuItem icon={UserRound} label="پروفایل من" />
            <UserMenuItem icon={Settings} label="تنظیمات حساب" />
            <div className="h-px" style={{ backgroundColor: 'var(--border-subtle)' }} />
            <UserMenuItem icon={LogOut} label="خروج از حساب" danger onClick={handleLogout} />
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}

function UserMenuItem({
  icon: Icon,
  label,
  danger,
  onClick,
}: {
  icon: LucideIcon
  label: string
  danger?: boolean
  onClick?: () => void
}) {
  return (
    <button
      onClick={onClick}
      className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-[13px] transition-colors duration-150 hover:bg-(--surface-sunken)"
      style={{ color: danger ? 'var(--color-danger)' : 'var(--text-secondary)' }}
    >
      <Icon size={16} />
      {label}
    </button>
  )
}
