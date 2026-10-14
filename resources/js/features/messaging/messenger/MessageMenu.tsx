import { useCallback, useEffect, useRef } from 'react'
import {
  Copy,
  Download,
  Eye,
  EyeOff,
  Flag,
  Pencil,
  Pin,
  PinOff,
  Reply,
  Share2,
  Trash2,
} from 'lucide-react'
import { useClickOutside } from '@/shared/hooks'
import { cn } from '@/shared/lib/cn'
import { REACTIONS, type ChatMessage } from './types'

export interface MessageMenuActions {
  onReply: () => void
  onReact: (emoji: string) => void
  onEdit: () => void
  onDelete: () => void
  onPin: () => void
  onReport: () => void
  onHide: () => void
  // ۱۰/۱۰ (بندِ ۵۰): کپی، هدایت، دانلود
  onCopy: () => void
  onForward: () => void
  onDownload: () => void
}

/**
 * منوی پیام (مرحلهٔ ۶ · فاز ۱۴) — با دکمه‌ی «⋯»، کلیکِ راست، یا کیبورد.
 *
 * ─── دسترس‌پذیری ─────────────────────────────────────────────────────────
 * `role="menu"`، فوکوس با باز شدن روی اولین گزینه، ↑/↓ برای جابه‌جایی و
 * Escape برای بستن که فوکوس را به دکمه‌ی بازکننده برمی‌گرداند.
 *
 * ⚠️ گزینه‌ها همان‌هایی‌اند که سرور اجازه داده (`canEdit`، `canDelete`)؛ منو
 * قاعده نمی‌سازد، فقط نشان می‌دهد. سرور به‌هرحال خودش دوباره می‌سنجد.
 */
export function MessageMenu({
  message,
  isAdmin,
  isPinned,
  openUp = false,
  onClose,
  onEscape,
  actions,
}: {
  message: ChatMessage
  isAdmin: boolean
  isPinned: boolean
  /** نزدیکِ پایینِ فهرست رو به بالا باز می‌شود. */
  openUp?: boolean
  onClose: () => void
  /** بستن با Escape — فوکوس به دکمه‌ی بازکننده برمی‌گردد. */
  onEscape: () => void
  actions: MessageMenuActions
}) {
  const menuRef = useRef<HTMLDivElement>(null)
  const close = useCallback(() => onClose(), [onClose])
  useClickOutside(menuRef, close)

  useEffect(() => {
    menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
  }, [])

  const live = !message.isDeleted && !(message.isHidden && !isAdmin)
  const canReact = live && !message.isHidden

  function onKeyDown(event: React.KeyboardEvent) {
    const items = Array.from(
      menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [],
    )
    const index = items.indexOf(document.activeElement as HTMLElement)

    if (event.key === 'Escape') {
      event.preventDefault()
      onEscape()
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      const step = event.key === 'ArrowDown' ? 1 : -1
      items[(index + step + items.length) % items.length]?.focus()
    }
  }

  const run = (action: () => void) => () => {
    close()
    action()
  }

  return (
    <div
      ref={menuRef}
      role="menu"
      tabIndex={-1}
      aria-label="کارهای پیام"
      onKeyDown={onKeyDown}
      className={cn(
        'absolute z-20 w-52 rounded-xl border p-1 text-small shadow-lg',
        openUp ? 'bottom-full mb-1' : 'top-full mt-1',
      )}
      style={{
        backgroundColor: 'var(--surface-raised)',
        borderColor: 'var(--border-subtle)',
        color: 'var(--text-primary)',
      }}
    >
      {canReact && (
        <div className="flex justify-between px-1 pb-1" aria-label="واکنش">
          {REACTIONS.map((emoji) => (
            <button
              key={emoji}
              type="button"
              role="menuitem"
              aria-label={`واکنش ${emoji}`}
              onClick={run(() => actions.onReact(emoji))}
              className="flex h-8 w-8 items-center justify-center rounded-lg text-base hover:bg-[var(--surface-sunken)]"
            >
              {emoji}
            </button>
          ))}
        </div>
      )}

      {live && <Item icon={Reply} label="پاسخ" onClick={run(actions.onReply)} />}
      {live && message.body && <Item icon={Copy} label="کپی" onClick={run(actions.onCopy)} />}
      {message.canForward && message.id > 0 && (
        <Item icon={Share2} label="هدایت" onClick={run(actions.onForward)} />
      )}
      {live && message.attachment && (
        <Item icon={Download} label="دانلود" onClick={run(actions.onDownload)} />
      )}
      {message.canEdit && <Item icon={Pencil} label="ویرایش" onClick={run(actions.onEdit)} />}
      {message.canPin && live && (!message.isHidden || isAdmin) && (
        <Item
          icon={isPinned ? PinOff : Pin}
          label={isPinned ? 'برداشتنِ سنجاق' : 'سنجاق'}
          onClick={run(actions.onPin)}
        />
      )}
      {!isAdmin && !message.isMine && canReact && (
        <Item icon={Flag} label="گزارش به مدیریت" onClick={run(actions.onReport)} />
      )}
      {isAdmin && !message.isDeleted && (
        <Item
          icon={message.isHidden ? Eye : EyeOff}
          label={message.isHidden ? 'نمایش برای ساکنین' : 'پنهان کردن'}
          onClick={run(actions.onHide)}
        />
      )}
      {message.canDelete && (
        <Item icon={Trash2} label="حذف" danger onClick={run(actions.onDelete)} />
      )}
    </div>
  )
}

function Item({
  icon: Icon,
  label,
  onClick,
  danger = false,
}: {
  icon: typeof Reply
  label: string
  onClick: () => void
  danger?: boolean
}) {
  return (
    <button
      type="button"
      role="menuitem"
      onClick={onClick}
      className="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-right hover:bg-[var(--surface-sunken)] focus:bg-[var(--surface-sunken)] focus:outline-none"
      style={danger ? { color: 'var(--color-danger)' } : undefined}
    >
      <Icon size={14} aria-hidden="true" />
      {label}
    </button>
  )
}
