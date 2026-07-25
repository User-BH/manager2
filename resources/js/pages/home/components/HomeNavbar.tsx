import { useEffect, useState } from 'react'
import { Link, useNavigate } from '@/lib/mpaNav'
import { motion } from 'framer-motion'
import { X } from 'lucide-react'
import { useToggle } from '@/hooks'
import { ThemeToggle } from '@/components/layout/ThemeToggle'
import { Logo } from '@/components/common/Logo'
import { scrollToSection } from '@/lib/scroll'

/** به‌جای href لنگری، شناسه‌ی بخش؛ حرکت با اسکرول نرم انجام می‌شود و آدرس
    سایت دست‌نخورده می‌ماند. */
const navLinks = [
  { label: 'ویژگی‌ها', section: 'features' },
  { label: 'گالری', section: 'gallery' },
  { label: 'نظرات', section: 'testimonials' },
]

/**
 * @param minimal برای صفحه‌هایی مثل پشتیبانی و دمو که بخش‌های صفحه‌ی فرود را
 *   ندارند: لینک‌های «ویژگی‌ها/گالری/نظرات» حذف می‌شوند (چون به بخشی اشاره
 *   می‌کنند که در این صفحه وجود ندارد) و لوگو خودش دکمه‌ی بازگشت به خانه است.
 */
export function HomeNavbar({ minimal = false }: { minimal?: boolean } = {}) {
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
        {minimal ? (
          <Link to="/" aria-label="بازگشت به صفحه اصلی" className="transition-opacity hover:opacity-80">
            <Logo size={34} />
          </Link>
        ) : (
          <Logo size={34} />
        )}

        {/* لینک‌های بخش‌ها فقط از ۷۶۸ به بالا؛ پایین‌تر کاملاً حذف می‌شوند. */}
        <nav className="hidden items-center gap-7 md:flex">
          {!minimal && navLinks.map((link) => (
            <button
              key={link.section}
              onClick={() => scrollToSection(link.section)}
              className="group relative text-[13.5px] font-medium transition-colors hover:opacity-80"
              style={{ color: 'var(--text-secondary)' }}
            >
              {link.label}
              {/* خط زیرِ متن که با هاور از وسط باز می‌شود */}
              <span
                className="absolute -bottom-1 left-1/2 h-0.5 w-0 -translate-x-1/2 rounded-full transition-all duration-300 group-hover:w-full"
                style={{ backgroundColor: 'var(--color-brand-500)' }}
              />
            </button>
          ))}
        </nav>

        {/*
          تم و دکمه‌های ورود/ثبت‌نام از ۶۰۰ به بالا به‌صورت خطی دیده می‌شوند؛
          یعنی بینِ ۶۰۰ تا ۷۶۸ (که لینک‌ها رفته‌اند ولی برگر هنوز نیامده) هم
          همین‌ها نمایش داده می‌شوند.
        */}
        <div className="hidden items-center gap-3 min-[600px]:flex">
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

        {/* برگر فقط زیر ۶۰۰، با انیمیشنِ هاور (خط‌ها با هاور جابه‌جا می‌شوند). */}
        <motion.button
          onClick={toggleMobileOpen}
          whileHover={{ scale: 1.06 }}
          whileTap={{ scale: 0.92 }}
          className="group flex h-10 w-10 flex-col items-center justify-center gap-[3.5px] rounded-xl border transition-colors hover:bg-(--surface-sunken) min-[600px]:hidden"
          style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-primary)' }}
          aria-label="منو"
          aria-expanded={mobileOpen}
        >
          {mobileOpen ? (
            <X size={20} />
          ) : (
            <>
              <span className="h-0.5 w-4 rounded-full bg-current transition-all duration-300 group-hover:w-5" />
              <span className="h-0.5 w-5 rounded-full bg-current transition-all duration-300 group-hover:w-3" />
              <span className="h-0.5 w-4 rounded-full bg-current transition-all duration-300 group-hover:w-5" />
            </>
          )}
        </motion.button>
      </div>

      {/*
        منوی برگر فقط زیر ۶۰۰ باز می‌شود و فقط ورودی/ثبت‌نام (و تمِ سایت) را
        دارد؛ لینک‌های بخش‌ها اینجا نیستند چون زیر ۷۶۸ کلاً حذف شده‌اند.
      */}
      {mobileOpen && (
        <motion.div
          initial={{ opacity: 0, height: 0 }}
          animate={{ opacity: 1, height: 'auto' }}
          exit={{ opacity: 0, height: 0 }}
          className="border-t px-4 pb-5 pt-3 min-[600px]:hidden"
          style={{ backgroundColor: 'var(--surface-base)', borderColor: 'var(--border-subtle)' }}
          dir="rtl"
        >
          <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium" style={{ color: 'var(--text-tertiary)' }}>
                تغییر تم
              </span>
              <ThemeToggle />
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => {
                  setMobileOpen(false)
                  navigate('/auth')
                }}
                className="flex-1 rounded-xl border py-2.5 text-sm font-semibold"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-primary)' }}
              >
                ورود
              </button>
              <button
                onClick={() => {
                  setMobileOpen(false)
                  navigate('/auth?tab=register')
                }}
                className="flex-1 rounded-xl py-2.5 text-sm font-semibold text-white"
                style={{ backgroundColor: 'var(--color-brand-500)' }}
              >
                ثبت‌نام رایگان
              </button>
            </div>
          </div>
        </motion.div>
      )}
    </motion.header>
  )
}
