import { Check, Send, Users } from 'lucide-react'

export type Audience = 'all' | 'units'

export interface MessengerUnit {
  id: number
  label: string
}

/**
 * انتخابِ گیرنده‌ی پیام — فقط برای مدیر (R23).
 *
 * ساکن انتخابی ندارد و این کامپوننت اصلاً برایش رندر نمی‌شود؛ ولی نبودنِ آن
 * در رابط **محافظت نیست**. قاعده سمتِ سرور اعمال می‌شود و پیامِ ساکن هرچه
 * باشد به مدیریت می‌رود.
 */
export function AudiencePicker({
  audience,
  units,
  selected,
  onAudienceChange,
  onToggleUnit,
}: {
  audience: Audience
  units: MessengerUnit[]
  selected: number[]
  onAudienceChange: (value: Audience) => void
  onToggleUnit: (unitId: number) => void
}) {
  return (
    <div
      className="flex flex-col gap-2 border-b pb-3"
      style={{ borderColor: 'var(--border-subtle)' }}
    >
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs font-semibold" style={{ color: 'var(--text-tertiary)' }}>
          گیرنده:
        </span>

        <Choice active={audience === 'all'} onClick={() => onAudienceChange('all')}>
          <Users size={13} />
          همه‌ی ساکنین
        </Choice>

        <Choice active={audience === 'units'} onClick={() => onAudienceChange('units')}>
          <Send size={13} />
          واحدهای انتخابی
        </Choice>

        {audience === 'units' && (
          <span className="text-xs" style={{ color: 'var(--text-tertiary)' }}>
            {selected.length > 0 ? `${selected.length} واحد انتخاب شده` : 'واحدی انتخاب نشده'}
          </span>
        )}
      </div>

      {audience === 'units' && (
        <div className="flex max-h-28 flex-wrap gap-1.5 overflow-y-auto">
          {units.map((unit) => {
            const active = selected.includes(unit.id)
            return (
              <button
                key={unit.id}
                type="button"
                onClick={() => onToggleUnit(unit.id)}
                aria-pressed={active}
                className="flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors"
                style={{
                  borderColor: active ? 'var(--color-brand-500)' : 'var(--border-default)',
                  backgroundColor: active
                    ? 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)'
                    : 'transparent',
                  color: active ? 'var(--color-brand-600)' : 'var(--text-secondary)',
                }}
              >
                {active && <Check size={11} />}
                {unit.label}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}

function Choice({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className="flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors"
      style={{
        backgroundColor: active
          ? 'color-mix(in srgb, var(--color-brand-500) 15%, transparent)'
          : 'var(--surface-sunken)',
        color: active ? 'var(--color-brand-600)' : 'var(--text-secondary)',
      }}
    >
      {children}
    </button>
  )
}
