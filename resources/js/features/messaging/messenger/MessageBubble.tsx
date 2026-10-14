import { useRef, useState } from 'react'
import {
  AlertCircle,
  Check,
  CheckCheck,
  Clock,
  Eye,
  EyeOff,
  FileText,
  MoreHorizontal,
  Pin,
  RotateCcw,
  Trash2,
} from 'lucide-react'
import { PollCard } from '@/shared/ui/PollCard'
import { cn } from '@/shared/lib/cn'
import { UserAvatar } from '@/shared/ui/UserAvatar'
import { MessageMenu, type MessageMenuActions } from './MessageMenu'
import type { ChatMessage, DeliveryStatus } from './types'

const STATUS_LABEL: Record<DeliveryStatus, string> = {
  pending: 'در حال ارسال',
  failed: 'ارسال نشد',
  sent: 'ارسال شد',
  read: 'خوانده شد',
}

function StatusIcon({ status }: { status: DeliveryStatus }) {
  const Icon = { pending: Clock, failed: AlertCircle, sent: Check, read: CheckCheck }[status]

  return (
    <span className="flex items-center" title={STATUS_LABEL[status]}>
      <Icon size={12} aria-hidden="true" />
      <span className="sr-only">{STATUS_LABEL[status]}</span>
    </span>
  )
}

/** جای لازم برای منوی کامل زیرِ پیام (شش واکنش و چند گزینه). */
const MENU_ROOM = 280

/** لمسِ طولانی برای منوی پیام روی موبایل */
const LONG_PRESS_MS = 500

export interface BubbleHandlers extends MessageMenuActions {
  onRetry: () => void
  onDiscard: () => void
  onJump: (id: number) => void
  onPollChange: (poll: NonNullable<ChatMessage['poll']>) => void
}

/**
 * یک پیام (مرحلهٔ ۶ · فاز ۱۲ و ۱۴).
 *
 * ⚠️ متن همیشه به‌صورتِ **متن** رندر می‌شود (`{message.body}`)، هرگز HTML —
 * React آن را escape می‌کند. تکه‌متنِ پاسخ و نامِ پیوست هم همین‌طور.
 */
export function MessageBubble({
  message,
  isAdmin,
  isPinned,
  menuOpen,
  onToggleMenu,
  handlers,
}: {
  message: ChatMessage
  isAdmin: boolean
  isPinned: boolean
  menuOpen: boolean
  onToggleMenu: (open: boolean) => void
  handlers: BubbleHandlers
}) {
  const muted = message.isMine ? 'rgba(255,255,255,0.75)' : 'var(--text-tertiary)'
  const hasMenu = message.id > 0
  const triggerRef = useRef<HTMLButtonElement>(null)
  // لمسِ طولانی روی موبایل همان منو را باز می‌کند (بندِ ۵۰)؛ حرکت یا رها کردن لغوش می‌کند
  const pressTimer = useRef<number | null>(null)
  const cancelPress = () => {
    if (pressTimer.current !== null) window.clearTimeout(pressTimer.current)
    pressTimer.current = null
  }
  // پیامِ پایینِ فهرست: منو رو به بالا، وگرنه لبه‌ی قابِ اسکرول می‌بُردش
  const [openUp, setOpenUp] = useState(false)

  function openMenu(open: boolean) {
    if (open) {
      const wrapper = triggerRef.current?.closest('.chat-row')
      const frame = wrapper?.closest('.overflow-y-auto')
      if (wrapper && frame) {
        setOpenUp(
          frame.getBoundingClientRect().bottom - wrapper.getBoundingClientRect().bottom < MENU_ROOM,
        )
      }
    }
    onToggleMenu(open)
  }

  return (
    <div
      className="relative max-w-[80%]"
      onContextMenu={(event) => {
        if (!hasMenu) return
        event.preventDefault()
        openMenu(true)
      }}
      onTouchStart={() => {
        if (!hasMenu) return
        cancelPress()
        pressTimer.current = window.setTimeout(() => openMenu(true), LONG_PRESS_MS)
      }}
      onTouchEnd={cancelPress}
      onTouchMove={cancelPress}
      onTouchCancel={cancelPress}
    >
      <div
        className={cn(
          'rounded-2xl px-4 py-2.5 text-body',
          message.isHidden && 'opacity-50',
          message.status === 'pending' && 'opacity-70',
        )}
        style={
          message.isMine
            ? { backgroundColor: 'var(--color-brand-500)', color: '#fff' }
            : { backgroundColor: 'var(--surface-sunken)', color: 'var(--text-primary)' }
        }
      >
        <div className="mb-1 flex items-center gap-2 text-tiny" style={{ color: muted }}>
          {!message.isMine && (
            <UserAvatar name={message.authorName} url={message.authorAvatarUrl} size={18} />
          )}
          <span className="font-semibold">{message.authorName}</span>
          {message.unitLabel && (
            <>
              <span>·</span>
              <span>{message.unitLabel}</span>
            </>
          )}
          {isPinned && <Pin size={11} aria-label="سنجاق‌شده" />}
        </div>

        {message.replyTo && (
          <button
            type="button"
            onClick={() => message.replyTo && handlers.onJump(message.replyTo.id)}
            className="mb-1.5 block w-full rounded-lg border-r-2 px-2 py-1 text-right text-tiny"
            style={{
              borderColor: 'currentColor',
              backgroundColor: message.isMine ? 'rgba(255,255,255,0.15)' : 'var(--surface-base)',
            }}
          >
            <span className="block font-semibold">{message.replyTo.authorName}</span>
            <span className="block truncate opacity-80">
              {message.replyTo.excerpt ??
                (message.replyTo.isDeleted ? 'این پیام حذف شد' : 'پیامِ در دسترس نیست')}
            </span>
          </button>
        )}

        {message.forwardedFrom && !message.isDeleted && (
          <p className="mb-1 text-tiny opacity-80">
            هدایت‌شده از {message.forwardedFrom.authorName}
          </p>
        )}

        {message.isDeleted ? (
          <p className="flex items-center gap-1.5 text-small italic opacity-80">
            <Trash2 size={12} />{' '}
            {message.removedByModeration
              ? 'این پیام به دستِ مدیریتِ گروه حذف شد.'
              : 'این پیام حذف شد.'}
          </p>
        ) : message.body === null ? (
          <p className="flex items-center gap-1.5 text-small italic opacity-80">
            <EyeOff size={12} /> این پیام توسط مدیر پنهان شده است.
          </p>
        ) : (
          <>
            {message.body && <p className="whitespace-pre-line leading-6">{message.body}</p>}

            {message.attachment?.kind === 'audio' ? (
              // ⚠️ پیامِ صوتیِ ساکن متنِ پیاده‌شده ندارد؛ `<track>`ِ خالی فقط قاعده را ساکت می‌کرد.
              // eslint-disable-next-line jsx-a11y/media-has-caption -- کمبودِ ثبت‌شده در CANONICAL_INVENTORY (فاز ۱۴)
              <audio
                controls
                preload="none"
                src={message.attachment.url}
                className="mt-2 w-60 max-w-full"
                aria-label="پیامِ صوتی"
              />
            ) : message.attachment?.kind === 'image' ? (
              /* cls-safe: ابعاد از ظرفِ ثابت می‌آید */
              <a
                href={message.attachment.url}
                target="_blank"
                rel="noreferrer"
                className="mt-2 block h-64 w-full max-w-xs"
              >
                <img
                  src={message.attachment.url}
                  alt={message.attachment.name}
                  loading="lazy"
                  decoding="async"
                  className="h-full w-full rounded-lg object-contain object-right"
                />
              </a>
            ) : message.attachment ? (
              <a
                href={message.attachment.url}
                target="_blank"
                rel="noreferrer"
                className="mt-2 flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-small underline"
                style={{
                  backgroundColor: message.isMine
                    ? 'rgba(255,255,255,0.18)'
                    : 'var(--surface-base)',
                }}
              >
                <FileText size={13} />
                {message.attachment.name}
              </a>
            ) : null}

            {message.poll && (
              <PollCard
                poll={message.poll}
                isMine={message.isMine}
                isAdmin={isAdmin}
                onVoted={handlers.onPollChange}
              />
            )}
          </>
        )}

        <div className="mt-1.5 flex items-center gap-2 text-tiny" style={{ color: muted }}>
          {message.sentAt && <span className="tabular-nums">{message.sentAt}</span>}
          {message.isEdited && <span>ویرایش‌شده</span>}
          {message.isMine && message.status && <StatusIcon status={message.status} />}

          {typeof message.readCount === 'number' && message.readCount > 0 && (
            <span className="flex items-center gap-0.5 tabular-nums" title="شمارِ خواننده‌ها">
              <Eye size={11} />
              {message.readCount}
            </span>
          )}

          {message.status === 'failed' && (
            <>
              <button
                type="button"
                onClick={handlers.onRetry}
                className="flex items-center gap-1 underline"
              >
                <RotateCcw size={11} /> دوباره
              </button>
              <button type="button" onClick={handlers.onDiscard} className="underline">
                حذف
              </button>
            </>
          )}

          {hasMenu && (
            <button
              ref={triggerRef}
              type="button"
              // ⚠️ بی‌این، «کلیک بیرونِ منو» پیش از کلیکِ همین دکمه منو را می‌بست و دکمه دوباره بازش می‌کرد
              onMouseDown={(event) => event.stopPropagation()}
              onClick={() => openMenu(!menuOpen)}
              aria-label="کارهای پیام"
              aria-haspopup="menu"
              aria-expanded={menuOpen}
              className="mr-auto flex h-6 w-6 items-center justify-center rounded"
            >
              <MoreHorizontal size={14} />
            </button>
          )}
        </div>
      </div>

      {message.reactions && message.reactions.length > 0 && (
        <div className="mt-1 flex flex-wrap gap-1" aria-label="واکنش‌ها">
          {message.reactions.map((reaction) => (
            <button
              key={reaction.emoji}
              type="button"
              onClick={() => handlers.onReact(reaction.emoji)}
              aria-pressed={reaction.mine}
              aria-label={`${reaction.emoji} ${reaction.count}`}
              className="flex items-center gap-1 rounded-full border px-2 py-0.5 text-tiny tabular-nums"
              style={{
                borderColor: reaction.mine ? 'var(--color-brand-500)' : 'var(--border-subtle)',
                backgroundColor: 'var(--surface-base)',
                color: 'var(--text-secondary)',
              }}
            >
              <span>{reaction.emoji}</span>
              {reaction.count}
            </button>
          ))}
        </div>
      )}

      {menuOpen && (
        <MessageMenu
          message={message}
          isAdmin={isAdmin}
          isPinned={isPinned}
          openUp={openUp}
          onClose={() => onToggleMenu(false)}
          onEscape={() => {
            onToggleMenu(false)
            triggerRef.current?.focus()
          }}
          actions={handlers}
        />
      )}
    </div>
  )
}
