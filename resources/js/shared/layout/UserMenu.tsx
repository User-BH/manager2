import { useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import {
  Building2,
  Check,
  ChevronDown,
  LogOut,
  Mail,
  Settings,
  UserRound,
  type LucideIcon,
} from 'lucide-react'
import { useToggle, useClickOutside } from '@/shared/hooks'
import { useAction } from '@/shared/hooks/useAction'
import { switchContext } from '@/shared/lib/accountContext'
import { leaveToAuth } from '@/shared/lib/leaveToAuth'
import { useAuth } from '@/shared/stores/authStore'

export function UserMenu() {
  const [open, , setOpen] = useToggle(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()
  const { user, logout } = useAuth()
  const { run, pendingKey } = useAction()

  useClickOutside(menuRef, () => setOpen(false))

  /*
   * مجتمع‌های من (مرحلهٔ ۱۰ · فاز ۴): فقط وقتی حساب در بیش از یک مجتمع عضو است دیده
   * می‌شود — برای همه‌ی حساب‌های تک‌مجتمعی این منو همان قبلی است. رفتن به مجتمعِ دیگر
   * را سرور از عضویت می‌سنجد و `switchContext` با ناوبریِ کامل همه‌ی دادهٔ مجتمعِ قبلی
   * را از رابط بیرون می‌برد.
   */
  const memberships = user?.memberships ?? []
  const invitations = user?.accountState === 'member' ? user.pendingInvitations : 0

  const switchTo = (complexId: number) => run(() => switchContext(complexId), { key: complexId })

  async function handleLogout() {
    /*
     * 🔴 خطای شبکه نباید کاربر را روی صفحه نگه دارد (فاز ۵).
     *
     * ─── چه چیزی خراب بود ──────────────────────────────────────────────
     * ⚠️ `logout()` خطا را دوباره پرتاب می‌کند (`try/finally` بدونِ
     * `catch`). پس اگر شبکه قطع بود، دو خطِ بعدی **هرگز اجرا نمی‌شدند**:
     * منو باز می‌ماند و ناوبری انجام نمی‌شد.
     *
     * کاربر روی دستگاهِ مشترک «خروج» را می‌زد، داشبورد را می‌دید، و
     * می‌رفت — با این باور که خارج نشده. در حالی که حالتِ محلی پاک شده
     * و کشِ دستگاه هم خالی شده بود.
     *
     * ─── چرا `catch`ِ خالی اینجا درست است ──────────────────────────────
     * 🔴 چیزی که واقعاً اهمیت دارد در `finally`ِ خودِ `logout` انجام شده:
     * پاک‌شدنِ کاربر و پاک‌سازیِ کش. نشستِ سرور هم با انقضا یا ورودِ
     * بعدی بسته می‌شود.
     *
     * ⚠️ ماندن روی صفحه بدترین نتیجه است، نه امن‌ترین.
     */
    try {
      await logout()
    } catch {
      // حالتِ محلی و کش از قبل پاک شده‌اند؛ بیرون بردنِ کاربر مهم‌تر است
    }

    setOpen(false)
    // ورود یک سندِ MPAِ جداست؛ خروج با ناوبریِ واقعیِ مرورگر انجام می‌شود —
    // و فقط یک بار، چون ProtectedRoute هم با پاک‌شدنِ کاربر همین را می‌خواهد
    leaveToAuth()
  }

  return (
    <div className="relative" ref={menuRef}>
      <button
        onClick={() => setOpen((prev) => !prev)}
        className="flex items-center gap-2 rounded-xl border py-1.5 pr-1.5 pl-2.5 transition-colors duration-200 hover:bg-(--surface-sunken)"
        style={{ borderColor: 'var(--border-subtle)' }}
      >
        {/*
          روی موبایل فقط آواتار می‌ماند. دلیل: نام در عرض کم به دو خط می‌شکست
          و ارتفاع دکمه از ۶۴ پیکسلِ هدر بیشتر می‌شد (اندازه‌گیری: ۷۶px) و از
          هدر بیرون می‌زد. در عرض‌های بزرگ‌تر هم نام تک‌خطی و کوتاه‌شده است تا
          نام‌های بلند همان مشکل را نسازند.
        */}
        <div className="hidden max-w-[9rem] text-right sm:block">
          <p
            className="truncate whitespace-nowrap text-[13px] font-semibold leading-tight"
            style={{ color: 'var(--text-primary)' }}
            title={user?.name ?? undefined}
          >
            {user?.name ?? 'کاربر'}
          </p>
          <p
            className="truncate whitespace-nowrap text-[11px] leading-tight"
            style={{ color: 'var(--text-tertiary)' }}
          >
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
            className={`absolute left-0 top-[calc(100%+8px)] z-40 overflow-hidden rounded-xl border shadow-lg ${memberships.length > 1 ? 'w-60' : 'w-48'}`}
            style={{
              backgroundColor: 'var(--surface-raised)',
              borderColor: 'var(--border-subtle)',
            }}
          >
            {memberships.length > 1 && (
              <div role="group" aria-label="مجتمع‌های من">
                <p className="px-3.5 pt-2.5 pb-1 text-xs" style={{ color: 'var(--text-tertiary)' }}>
                  مجتمع‌های من
                </p>
                {memberships.map((m) => (
                  <button
                    key={m.complexId}
                    onClick={() => (m.current ? setOpen(false) : void switchTo(m.complexId))}
                    disabled={pendingKey !== null}
                    aria-current={m.current ? 'true' : undefined}
                    className="flex w-full items-center gap-2.5 px-3.5 py-2 text-right text-sm transition-colors duration-150 hover:bg-(--surface-sunken) disabled:opacity-60"
                    style={{ color: 'var(--text-secondary)' }}
                  >
                    <Building2 size={15} className="shrink-0" />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate" style={{ color: 'var(--text-primary)' }}>
                        {m.complexName}
                      </span>
                      <span className="block text-xs" style={{ color: 'var(--text-tertiary)' }}>
                        {m.roleLabel}
                      </span>
                    </span>
                    {m.current && <Check size={14} style={{ color: 'var(--color-brand-500)' }} />}
                  </button>
                ))}
                <div className="h-px" style={{ backgroundColor: 'var(--border-subtle)' }} />
              </div>
            )}
            {invitations > 0 && (
              <UserMenuItem
                icon={Mail}
                label={`دعوت‌ها (${invitations})`}
                onClick={() => {
                  setOpen(false)
                  void navigate('/profile')
                }}
              />
            )}
            <UserMenuItem
              icon={UserRound}
              label="پروفایل من"
              onClick={() => {
                setOpen(false)
                void navigate('/profile')
              }}
            />
            {/* خریدِ مجتمعِ تازه با همین حساب (مرحلهٔ ۱۰ · فاز ۵؛ تصمیمِ کارفرما) — حسابِ اولیه در داشبوردِ خودش می‌خرد */}
            {user?.accountState === 'member' && (
              <UserMenuItem
                icon={Building2}
                label="خریدِ مجتمعِ تازه"
                onClick={() => {
                  setOpen(false)
                  void navigate('/new-complex')
                }}
              />
            )}
            <UserMenuItem
              icon={Settings}
              label="تنظیمات حساب"
              onClick={() => {
                setOpen(false)
                void navigate('/account')
              }}
            />
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
