import { useEffect, useState } from 'react'
import { Link, useNavigate } from '@/shared/lib/mpaNav'
import { motion } from 'framer-motion'
import { X } from 'lucide-react'
import { useToggle } from '@/shared/hooks'
import { ThemeToggle } from '@/shared/layout/ThemeToggle'
import { Logo } from '@/shared/common/Logo'
import { scrollToSection } from '@/shared/lib/scroll'
import { isViewerAuthenticated } from '@/shared/lib/viewer'

/** به‌جای href لنگری، شناسه‌ی بخش؛ حرکت با اسکرول نرم انجام می‌شود و آدرس
    سایت دست‌نخورده می‌ماند. */
const sectionLinks = [
  { label: 'ویژگی‌ها', section: 'features' },
  { label: 'گالری', section: 'gallery' },
  { label: 'نظرات', section: 'testimonials' },
]

export type NavbarPage = 'home' | 'demo' | 'support'

/**
 * مقصدهایی که در **هر سه** صفحه دیده می‌شوند (R31).
 *
 * «سوالات متداول» یک بخش از صفحه‌ی پشتیبانی است و نه صفحه‌ی جدا، پس با
 * `?topic=faq` می‌رود — همان قراردادی که فوتر از قبل استفاده می‌کند و
 * خودِ `SupportPage` بلد است بازش کند و به آن اسکرول کند.
 */
const pageLinks: Array<{ id: NavbarPage; label: string; to: string }> = [
  { id: 'demo', label: 'دمو', to: '/demo' },
  { id: 'support', label: 'سوالات متداول', to: '/support?topic=faq' },
]

/**
 * هدرِ صفحه‌های عمومی.
 *
 * ─── چرا `page` جای `minimal` نشست (R31) ───────────────────────────────────
 * `minimal` یک بولی بود با معنای «این صفحه بخش‌های صفحه‌ی فرود را ندارد».
 * حالا هدر باید بداند **کدام** صفحه است، نه فقط «فرود نیست»: در `/demo`
 * آیتمِ «دمو» و در `/support` آیتمِ «سوالات متداول» باید حذف شوند، چون
 * لینکی که به صفحه‌ی فعلی برمی‌گردد فقط جای مفید را اشغال می‌کند.
 */
export function HomeNavbar({ page = 'home' }: { page?: NavbarPage } = {}) {
  const navigate = useNavigate()
  const [scrolled, setScrolled] = useState(false)
  const [mobileOpen, toggleMobileOpen, setMobileOpen] = useToggle(false)

  /*
   * سرور این را در `<head>` گذاشته، پس همان اولین رندر درست است و کاربرِ
   * واردشده هیچ‌وقت دکمه‌ی «ورود» را نمی‌بیند تا بعد عوض شود. مقدارش در طولِ
   * عمرِ صفحه ثابت است، پس state لازم ندارد.
   */
  const authenticated = isViewerAuthenticated()

  // لینکِ صفحه‌ی جاری حذف می‌شود؛ بقیه می‌مانند
  const links = pageLinks.filter((link) => link.id !== page)

  useEffect(() => {
    function handleScroll() {
      setScrolled(window.scrollY > 24)
    }
    handleScroll()
    window.addEventListener('scroll', handleScroll, { passive: true })
    return () => window.removeEventListener('scroll', handleScroll)
  }, [])

  function go(to: string) {
    setMobileOpen(false)
    navigate(to)
  }

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
      <div
        className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6"
        dir="rtl"
      >
        {page === 'home' ? (
          <Logo size={34} />
        ) : (
          <Link
            to="/"
            aria-label="بازگشت به صفحه اصلی"
            className="transition-opacity hover:opacity-80"
          >
            <Logo size={34} />
          </Link>
        )}

        <nav className="hidden items-center gap-7 md:flex">
          {/* بخش‌های صفحه‌ی فرود فقط در خودِ صفحه‌ی فرود معنا دارند */}
          {page === 'home' &&
            sectionLinks.map((link) => (
              <NavItem
                key={link.section}
                label={link.label}
                onClick={() => scrollToSection(link.section)}
              />
            ))}

          {/* «دمو» و «سوالات متداول» در هر سه صفحه (منهای صفحه‌ی خودشان) */}
          {links.map((link) => (
            <NavItem key={link.id} label={link.label} onClick={() => go(link.to)} />
          ))}
        </nav>

        {/*
          تم و دکمه‌های ورود/ثبت‌نام از ۶۰۰ به بالا به‌صورت خطی دیده می‌شوند؛
          یعنی بینِ ۶۰۰ تا ۷۶۸ (که لینک‌ها رفته‌اند ولی برگر هنوز نیامده) هم
          همین‌ها نمایش داده می‌شوند.
        */}
        <div className="hidden items-center gap-3 min-[600px]:flex">
          <ThemeToggle />
          {authenticated ? (
            <button
              onClick={() => go('/dashboard')}
              className="rounded-xl px-4 py-2 text-[13.5px] font-semibold text-white shadow-sm transition-transform duration-200 hover:scale-105"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              ورود به داشبورد
            </button>
          ) : (
            <>
              <button
                onClick={() => go('/auth')}
                className="rounded-xl px-4 py-2 text-[13.5px] font-semibold transition-colors hover:opacity-90"
                style={{ color: 'var(--text-secondary)' }}
              >
                ورود
              </button>
              <button
                onClick={() => go('/auth?tab=register')}
                className="rounded-xl px-4 py-2 text-[13.5px] font-semibold text-white shadow-sm transition-transform duration-200 hover:scale-105"
                style={{ backgroundColor: 'var(--color-brand-500)' }}
              >
                ثبت‌نام رایگان
              </button>
            </>
          )}
        </div>

        {/*
          تم **بیرون** از منوی برگر و کنارِ خودش (R31).

          پیش از این تنها راهِ تغییرِ تم در موبایل، بازکردنِ منو بود — یعنی
          کاری که کاربر روزی چند بار می‌کند پشتِ دو کلیک بود، در حالی که
          دکمه‌اش جا داشت کنارِ برگر بنشیند.
        */}
        <div className="flex items-center gap-2 min-[600px]:hidden">
          <ThemeToggle />

          <motion.button
            onClick={toggleMobileOpen}
            whileHover={{ scale: 1.06 }}
            whileTap={{ scale: 0.92 }}
            className="group flex h-10 w-10 flex-col items-center justify-center gap-[3.5px] rounded-xl border transition-colors hover:bg-(--surface-sunken)"
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
      </div>

      {/*
        منوی برگر: فقط مقصدها (R31).

        ─── دو تغییر ────────────────────────────────────────────────────────
        ۱. تمِ سایت از اینجا بیرون رفت و کنارِ خودِ برگر نشست.
        ۲. پس‌زمینه دیگر `var(--surface-base)` نیست.

        دومی یک باگِ تأییدشده بود: آن متغیر در تمِ روشن دقیقاً `#ffffff` است
        و پنل هیچ blur نداشت، پس منو مثل یک ورقه‌ی سفیدِ چسبیده روی صفحه
        دیده می‌شد — در حالی که خودِ هدر پس از اسکرول شفاف و مات است. حالا
        همان فرمولِ هدر را می‌گیرد و با صفحه یکی می‌شود.
      */}
      {mobileOpen && (
        <motion.div
          initial={{ opacity: 0, height: 0 }}
          animate={{ opacity: 1, height: 'auto' }}
          exit={{ opacity: 0, height: 0 }}
          className="overflow-hidden border-t px-4 pb-5 pt-3 min-[600px]:hidden"
          style={{
            backgroundColor: 'color-mix(in srgb, var(--surface-base) 88%, transparent)',
            backdropFilter: 'blur(16px)',
            borderColor: 'var(--border-subtle)',
          }}
          dir="rtl"
        >
          <div className="flex flex-col gap-2">
            {authenticated ? (
              <MenuButton primary label="ورود به داشبورد" onClick={() => go('/dashboard')} />
            ) : (
              <>
                <MenuButton label="ورود" onClick={() => go('/auth')} />
                <MenuButton
                  primary
                  label="ثبت‌نام رایگان"
                  onClick={() => go('/auth?tab=register')}
                />
              </>
            )}

            {links.map((link) => (
              <MenuButton key={link.id} label={link.label} onClick={() => go(link.to)} />
            ))}
          </div>
        </motion.div>
      )}
    </motion.header>
  )
}

/** آیتمِ نوارِ بالا، با خطِ زیرین که از وسط باز می‌شود. */
function NavItem({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      className="group relative text-[13.5px] font-medium transition-colors hover:opacity-80"
      style={{ color: 'var(--text-secondary)' }}
    >
      {label}
      <span
        className="absolute -bottom-1 left-1/2 h-0.5 w-0 -translate-x-1/2 rounded-full transition-all duration-300 group-hover:w-full"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      />
    </button>
  )
}

/**
 * دکمه‌ی منوی برگر.
 *
 * تمام‌عرض و بلند است، نه دو دکمه‌ی کنار هم: با چهار آیتم، ردیفِ دوتایی
 * یعنی متن‌ها کوچک و هدفِ لمس تنگ می‌شد.
 */
function MenuButton({
  label,
  onClick,
  primary = false,
}: {
  label: string
  onClick: () => void
  primary?: boolean
}) {
  return (
    <button
      onClick={onClick}
      className="w-full rounded-xl border py-2.5 text-sm font-semibold transition-transform active:scale-[0.98]"
      style={
        primary
          ? {
              backgroundColor: 'var(--color-brand-500)',
              borderColor: 'transparent',
              color: '#fff',
            }
          : { borderColor: 'var(--border-subtle)', color: 'var(--text-primary)' }
      }
    >
      {label}
    </button>
  )
}
