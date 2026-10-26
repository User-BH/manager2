import { useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import {
  Building2,
  ChevronDown,
  LogOut,
  Mail,
  UserPlus,
  UserRound,
  type LucideIcon,
} from 'lucide-react'
import { useToggle, useClickOutside } from '@/shared/hooks'
import { useAction } from '@/shared/hooks/useAction'
import { enterInitialContext, switchContext } from '@/shared/lib/accountContext'
import { leaveToAuth } from '@/shared/lib/leaveToAuth'
import { useAuth } from '@/shared/stores/authStore'
import type { MembershipSummary } from '@/shared/types'
import { UserAvatar } from '@/shared/ui/UserAvatar'

export function UserMenu() {
  const [open, , setOpen] = useToggle(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()
  const { user, logout } = useAuth()
  const { run, pendingKey } = useAction()

  useClickOutside(menuRef, () => setOpen(false))

  /*
   * منوی آدمک (مرحلهٔ ۱۳ · فاز ۳؛ ایرادات ۱۰، بندهای ۳۶–۳۸ و ۵۰–۵۱؛ ایرادات ۱۱، بندهای ۵.۳، ۵.۴ و ۵.۷؛ «داشبورد های
   * آزمایشی» ۵.۲):
   *
   *   - داشبوردِ مجتمع: اطلاعاتِ کاربری · **مجتمع‌های دیگر** (فقط عضویتِ فعال، و هرگز مجتمعِ همین زمینه) ·
   *     «ایجادِ حسابِ جدید» (همیشه، حتی تک‌مجتمعی) · خروج.
   *   - داشبوردِ اولیه: اطلاعاتِ کاربری · مجتمع‌های قبلی برای بازگشتِ بی خروج · خروج — بی «ایجادِ حسابِ جدید».
   *   - سوپر ادمین: فقط اطلاعاتِ کاربری و خروج؛ تنظیماتِ حساب در منوی کناری است.
   *
   * رفتن به مجتمعِ دیگر را سرور از عضویت می‌سنجد و `switchContext` با ناوبریِ کامل همه‌ی دادهٔ مجتمعِ قبلی را از
   * رابط بیرون می‌برد؛ «ایجادِ حسابِ جدید» هم همان راه را به زمینه‌ی بی‌مجتمع می‌رود (`enterInitialContext`).
   */
  const platform = user?.isSuperAdmin === true
  const inCommunity = user?.accountState === 'member'
  const others = platform
    ? []
    : (user?.memberships ?? []).filter(
        (m) => m.status === 'active' && !m.current && m.complexId !== user?.complex?.id,
      )
  const invitations = inCommunity ? (user?.pendingInvitations ?? 0) : 0

  const switchTo = (complexId: number) => run(() => switchContext(complexId), { key: complexId })
  const createNewAccount = () => run(enterInitialContext, { key: 'new-account' })

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
        /*
         * نامِ دسترس‌پذیرِ ثابت (۱۳/۴، ایرادات ۱۱ بندِ ۲.۹): زیرِ `sm` نام و نقش پنهان‌اند و دکمه فقط آواتار بود — صفحه‌خوان
         * «دکمه» ِ بی‌نام می‌خواند.
         */
        aria-label={`حساب کاربری: ${user?.name ?? 'کاربر'}`}
        aria-expanded={open}
        aria-haspopup="true"
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

        {/* تصویرِ canonical ِ حساب (مرحلهٔ ۱۰ · فاز ۷)؛ بی تصویر همان شمایلِ قبلی */}
        {user?.avatarUrl ? (
          <UserAvatar name={user.name} url={user.avatarUrl} size={32} />
        ) : (
          <div
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-white"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            <UserRound size={16} />
          </div>
        )}

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
            className={`absolute left-0 top-[calc(100%+8px)] z-40 overflow-hidden rounded-xl border shadow-lg ${others.length > 0 ? 'w-60' : 'w-52'}`}
            style={{
              backgroundColor: 'var(--surface-raised)',
              borderColor: 'var(--border-subtle)',
            }}
          >
            <UserMenuItem
              icon={UserRound}
              label="اطلاعات کاربری"
              onClick={() => {
                setOpen(false)
                void navigate('/profile')
              }}
            />
            {others.length > 0 && (
              <OtherCommunities
                // در داشبوردِ اولیه همه «قبلی»‌اند؛ در داشبوردِ مجتمع، مجتمعِ همین‌جا در فهرست نیست
                label={inCommunity ? 'مجتمع‌های دیگر' : 'مجتمع‌های من'}
                communities={others}
                disabled={pendingKey !== null}
                onPick={(complexId) => void switchTo(complexId)}
              />
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
            {/*
              «ایجادِ حسابِ جدید» = همان داشبوردِ اولیه با همان Account (ایرادات ۱۱، بندِ ۵.۶): درخواستِ عضویت، دعوت‌ها،
              خریدِ مجتمعِ تازه. فقط در داشبوردِ مجتمع، و بعد از هر بار استفاده هم سرِ جایش.
            */}
            {inCommunity && (
              <UserMenuItem
                icon={UserPlus}
                label="ایجاد حساب جدید"
                disabled={pendingKey !== null}
                onClick={() => void createNewAccount()}
              />
            )}
            <div className="h-px" style={{ backgroundColor: 'var(--border-subtle)' }} />
            <UserMenuItem icon={LogOut} label="خروج از حساب" danger onClick={handleLogout} />
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}

/** مجتمع‌هایی که حساب عضوِ فعالشان است، جز همین زمینه — کلیک یعنی تعویضِ واقعیِ زمینه در سرور. */
function OtherCommunities({
  label,
  communities,
  disabled,
  onPick,
}: {
  label: string
  communities: MembershipSummary[]
  disabled: boolean
  onPick: (complexId: number) => void
}) {
  return (
    <div role="group" aria-label={label}>
      <div className="h-px" style={{ backgroundColor: 'var(--border-subtle)' }} />
      <p className="px-3.5 pt-2.5 pb-1 text-xs" style={{ color: 'var(--text-tertiary)' }}>
        {label}
      </p>
      {communities.map((m) => (
        <button
          key={m.complexId}
          onClick={() => onPick(m.complexId)}
          disabled={disabled}
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
        </button>
      ))}
    </div>
  )
}

function UserMenuItem({
  icon: Icon,
  label,
  danger,
  disabled,
  onClick,
}: {
  icon: LucideIcon
  label: string
  danger?: boolean
  disabled?: boolean
  onClick?: () => void
}) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-[13px] transition-colors duration-150 hover:bg-(--surface-sunken) disabled:opacity-60"
      style={{ color: danger ? 'var(--color-danger)' : 'var(--text-secondary)' }}
    >
      <Icon size={16} />
      {label}
    </button>
  )
}
