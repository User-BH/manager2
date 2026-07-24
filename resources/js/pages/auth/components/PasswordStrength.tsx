import { motion } from 'framer-motion'

/**
 * سنجه‌ی قدرت رمز عبور.
 *
 * چهار معیار مستقل: حرف کوچک، حرف بزرگ، رقم و نماد. طول هم شرط پایه است؛
 * رمزِ کوتاه هرچقدر هم متنوع باشد قوی حساب نمی‌شود، چون کوتاهی مهم‌ترین
 * ضعف در برابر حمله‌ی جست‌وجوی فراگیر است.
 *
 * معیارهای برآورده‌نشده فهرست می‌شوند تا کاربر بداند دقیقاً چه کم دارد.
 */
export function PasswordStrength({ value }: { value: string }) {
  if (!value) return null

  const checks = [
    { key: 'lower', label: 'حرف کوچک', ok: /[a-z]/.test(value) },
    { key: 'upper', label: 'حرف بزرگ', ok: /[A-Z]/.test(value) },
    { key: 'digit', label: 'عدد', ok: /\d/.test(value) },
    { key: 'symbol', label: 'نماد', ok: /[^A-Za-z0-9]/.test(value) },
  ]

  const met = checks.filter((c) => c.ok).length
  const longEnough = value.length >= 8

  // بدون طولِ کافی، سقف امتیاز «متوسط» است
  const score = !longEnough ? Math.min(met, 2) : met

  const levels = [
    { label: 'خیلی ضعیف', color: 'var(--color-danger)' },
    { label: 'ضعیف', color: 'var(--color-danger)' },
    { label: 'متوسط', color: 'var(--color-warning)' },
    { label: 'خوب', color: 'var(--color-brand-500)' },
    { label: 'قوی‌ترین حالت', color: 'var(--color-success)' },
  ]
  const level = levels[score] ?? levels[0]
  const missing = checks.filter((c) => !c.ok).map((c) => c.label)

  return (
    <div className="mt-1.5 flex flex-col gap-1.5">
      {/* چهار میله، هرکدام یک معیار */}
      <div className="flex gap-1">
        {[0, 1, 2, 3].map((i) => (
          <motion.span
            key={i}
            className="h-1.5 flex-1 rounded-full"
            animate={{ backgroundColor: i < score ? level.color : 'var(--border-subtle)' }}
            transition={{ duration: 0.25 }}
          />
        ))}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-x-2 text-[11px]">
        <span className="font-semibold" style={{ color: level.color }}>
          {level.label}
        </span>
        {!longEnough && (
          <span style={{ color: 'var(--text-tertiary)' }}>حداقل ۸ نویسه</span>
        )}
        {longEnough && missing.length > 0 && (
          <span style={{ color: 'var(--text-tertiary)' }}>برای قوی‌تر شدن: {missing.join('، ')}</span>
        )}
      </div>
    </div>
  )
}
