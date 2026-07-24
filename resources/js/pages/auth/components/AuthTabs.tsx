import { motion } from 'framer-motion'

export type AuthTab = 'login' | 'register'

interface AuthTabsProps {
  active: AuthTab
  onChange: (tab: AuthTab) => void
}

const tabs: { id: AuthTab; label: string }[] = [
  { id: 'login', label: 'ورود' },
  { id: 'register', label: 'ثبت‌نام' },
]

/** دو دکمه‌ی بالای فرم، در کنارِ لینکِ تعویضِ پایین صفحه. */
export function AuthTabs({ active, onChange }: AuthTabsProps) {
  return (
    <div
      className="relative grid grid-cols-2 rounded-2xl border p-1"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
    >
      {tabs.map((tab) => (
        <button
          key={tab.id}
          type="button"
          onClick={() => onChange(tab.id)}
          className="relative z-10 rounded-xl py-2 text-[13px] font-semibold transition-colors duration-200"
          style={{ color: active === tab.id ? 'var(--text-on-brand)' : 'var(--text-secondary)' }}
        >
          {active === tab.id && (
            <motion.span
              layoutId="auth-tab-pill"
              transition={{ type: 'spring', stiffness: 400, damping: 32 }}
              className="absolute inset-0 -z-10 rounded-xl"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            />
          )}
          {tab.label}
        </button>
      ))}
    </div>
  )
}
