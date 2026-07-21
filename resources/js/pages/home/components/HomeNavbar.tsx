import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import { Menu, X } from 'lucide-react'
import { useToggle } from '@/hooks'
import { ThemeToggle } from '@/components/layout/ThemeToggle'
import { Logo } from '@/components/common/Logo'

const navLinks = [
  { label: 'ویژگی‌ها', href: '#features' },
  { label: 'گالری', href: '#gallery' },
  { label: 'نظرات', href: '#testimonials' },
]

export function HomeNavbar() {
  const navigate = useNavigate()
  const [scrolled, setScrolled] = useState(false)
  const [mobileOpen, toggleMobileOpen, setMobileOpen] = useToggle(false)

  useEffect(() => {
    function handleScroll() {
      setScrolled(window.scrollY > 24)
    }
    handleScroll()
    window.addEventListener('scroll', handleScroll, { passive: true })
    return () => window.removeEventListener('scroll', handleScroll)
  }, [])

  return (
    <motion.header
      initial={{ y: -80, opacity: 0 }}
      animate={{ y: 0, opacity: 1 }}
      transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
      className="fixed inset-x-0 top-0 z-50 transition-all duration-300"
      style={{
        backgroundColor: scrolled
          ? 'color-mix(in srgb, var(--surface-base) 85%, transparent)'
          : 'transparent',
        boxShadow: scrolled ? '0 1px 0 var(--border-subtle)' : 'none',
        backdropFilter: scrolled ? 'blur(14px)' : 'none',
      }}
    >
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6" dir="rtl">
        <Logo size={34} />

        <nav className="hidden items-center gap-7 md:flex">
          {navLinks.map((link) => (
            <a
              key={link.href}
              href={link.href}
              className="text-[13.5px] font-medium transition-colors hover:opacity-80"
              style={{ color: 'var(--text-secondary)' }}
            >
              {link.label}
            </a>
          ))}
        </nav>

        <div className="hidden items-center gap-3 md:flex">
          <ThemeToggle />
          <button
            onClick={() => navigate('/auth')}
            className="rounded-xl px-4 py-2 text-[13.5px] font-semibold transition-colors hover:opacity-90"
            style={{ color: 'var(--text-secondary)' }}
          >
            ورود
          </button>
          <button
            onClick={() => navigate('/auth?tab=register')}
            className="rounded-xl px-4 py-2 text-[13.5px] font-semibold text-white shadow-sm transition-transform duration-200 hover:scale-105"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            ثبت‌نام رایگان
          </button>
        </div>

        <button
          onClick={toggleMobileOpen}
          className="flex h-9 w-9 items-center justify-center rounded-xl md:hidden"
          style={{ color: 'var(--text-primary)' }}
          aria-label="منو"
        >
          {mobileOpen ? <X size={20} /> : <Menu size={20} />}
        </button>
      </div>

      {mobileOpen && (
        <motion.div
          initial={{ opacity: 0, height: 0 }}
          animate={{ opacity: 1, height: 'auto' }}
          exit={{ opacity: 0, height: 0 }}
          className="border-t px-4 pb-5 pt-3 md:hidden"
          style={{ backgroundColor: 'var(--surface-base)', borderColor: 'var(--border-subtle)' }}
          dir="rtl"
        >
          <div className="flex flex-col gap-3">
            {navLinks.map((link) => (
              <a
                key={link.href}
                href={link.href}
                onClick={() => setMobileOpen(false)}
                className="text-sm font-medium"
                style={{ color: 'var(--text-secondary)' }}
              >
                {link.label}
              </a>
            ))}
            <div className="mt-2 flex gap-2">
              <button
                onClick={() => navigate('/auth')}
                className="flex-1 rounded-xl border py-2.5 text-sm font-semibold"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-primary)' }}
              >
                ورود
              </button>
              <button
                onClick={() => navigate('/auth?tab=register')}
                className="flex-1 rounded-xl py-2.5 text-sm font-semibold text-white"
                style={{ backgroundColor: 'var(--color-brand-500)' }}
              >
                ثبت‌نام
              </button>
            </div>
          </div>
        </motion.div>
      )}
    </motion.header>
  )
}
