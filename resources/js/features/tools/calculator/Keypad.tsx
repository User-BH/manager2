/**
 * تکه‌ای از ماشین‌حساب که از `CalculatorPage.tsx` بیرون کشیده شد (R39 · آیتم ⑤).
 *
 * ⚠️ این جابه‌جایی عمداً **هیچ منطقی را عوض نمی‌کند**: فایلِ اصلی ۹۶۸ خط بود
 * و همه‌ی زیرکامپوننت‌هایش را هم داخلِ خودش داشت. تجزیه از قبل انجام شده
 * بود؛ چیزی که نبود، مرزِ فایلی بود.
 */

import { cn } from '@/shared/lib/cn'
import { SCIENTIFIC, SECOND_ROWS, NUMPAD } from './keys'
import type { KeySpec } from './types'

export function Keypad({
  secondFn,
  onToggleSecond,
  onInsert,
  onBackspace,
  onClear,
  onEquals,
}: {
  secondFn: boolean
  onToggleSecond: () => void
  onInsert: (text: string) => void
  onBackspace: () => void
  onClear: () => void
  onEquals: () => void
}) {
  const rows = secondFn ? SECOND_ROWS : SCIENTIFIC

  function press(key: KeySpec) {
    if (key.label === '2nd') return onToggleSecond()
    if (key.label === 'AC') return onClear()
    if (key.label === '⌫') return onBackspace()

    onInsert(key.insert ?? key.label)
  }

  return (
    <div className="mt-4 flex flex-col gap-2" dir="ltr">
      {rows.map((row, rowIndex) => (
        <div key={`sci-${rowIndex}`} className="grid grid-cols-5 gap-2">
          {row.map((key) => (
            <Key
              key={`${key.label}-${rowIndex}`}
              spec={key}
              active={key.label === '2nd' && secondFn}
              onPress={() => press(key)}
            />
          ))}
        </div>
      ))}

      <div className="mt-1 grid grid-cols-4 gap-2">
        {NUMPAD.flat().map((key, index) => (
          <Key key={`pad-${key.label}-${index}`} spec={key} onPress={() => press(key)} />
        ))}
      </div>

      <button
        onClick={onEquals}
        className="mt-1 rounded-xl py-3.5 text-[17px] font-extrabold text-white transition-transform hover:scale-[1.01] active:scale-[0.99]"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        =
      </button>
    </div>
  )
}

/**
 * رنگ پایه، متن و رنگ هاورِ هر تنوعِ کلید.
 *
 * رنگ هاور عمداً یک ته‌رنگ سبزِ دیده‌شدنی است، نه سفیدِ محو. چون
 * background-color این‌لاین بر :hover در CSS غلبه می‌کند، این مقادیر به‌جای
 * style این‌لاین در متغیرهای CSS گذاشته می‌شوند و کلاس .calc-key در app.css
 * هم پایه و هم هاور را از همان متغیرها می‌خواند.
 */
function keyColors(variant: NonNullable<KeySpec['variant']>, active: boolean) {
  if (active) {
    return {
      bg: 'var(--color-brand-500)',
      fg: '#fff',
      hover: 'color-mix(in srgb, #fff 12%, var(--color-brand-500))',
    }
  }

  switch (variant) {
    case 'digit':
      return {
        bg: 'var(--surface-sunken)',
        fg: 'var(--text-primary)',
        hover: 'color-mix(in srgb, var(--color-brand-500) 14%, var(--surface-sunken))',
      }
    case 'operator':
      return {
        bg: 'var(--surface-base)',
        fg: 'var(--color-brand-600)',
        hover: 'color-mix(in srgb, var(--color-brand-500) 20%, var(--surface-base))',
      }
    case 'danger':
      return {
        bg: 'color-mix(in srgb, var(--state-danger) 10%, transparent)',
        fg: 'var(--state-danger)',
        hover: 'color-mix(in srgb, var(--state-danger) 22%, var(--surface-base))',
      }
    default: // function
      return {
        bg: 'var(--surface-base)',
        fg: 'var(--text-secondary)',
        hover: 'color-mix(in srgb, var(--color-brand-500) 13%, var(--surface-base))',
      }
  }
}

function Key({ spec, active, onPress }: { spec: KeySpec; active?: boolean; onPress: () => void }) {
  const variant = spec.variant ?? 'digit'
  const colors = keyColors(variant, Boolean(active))

  return (
    <button
      onClick={onPress}
      title={spec.title}
      type="button"
      className={cn(
        'calc-key rounded-xl border py-2.5 font-mono text-[14px] font-bold',
        variant === 'digit' && 'text-[16px]',
      )}
      style={{
        ['--key-bg' as string]: colors.bg,
        ['--key-fg' as string]: colors.fg,
        ['--key-hover' as string]: colors.hover,
      }}
    >
      {spec.label}
    </button>
  )
}

/* ------------------------------- تاریخچه ------------------------------- */
