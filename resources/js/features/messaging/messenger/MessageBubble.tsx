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
import { MessageMenu, type MessageMenuActions } from './MessageMenu'
import { nameTone, splitSentAt } from './messageLayout'
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
      <Icon size={14} aria-hidden="true" />
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
 * یک پیام (مرحلهٔ ۶ · فاز ۱۲ و ۱۴؛ ظاهر از مرحلهٔ ۱۲ · فاز ۷).
 *
 * ⚠️ متن همیشه به‌صورتِ **متن** رندر می‌شود (`{message.body}`)، هرگز HTML —
 * React آن را escape می‌کند. تکه‌متنِ پاسخ و نامِ پیوست هم همین‌طور.
 *
 * ─── ظاهر (۱۲/۷، نمونه‌ی تأییدشده) ───────────────────────────────────────────
 * پیام‌های پشتِ‌سرِهمِ یک نفر یک دسته‌اند (`messageLayout.ts`): نامِ فرستنده فقط روی اولی (`showSender`)، گوشه‌ی
 * دُم‌دار روی آخری (`tail`). زمان و وضعیتِ خواندن کنارِ متن؛ تاریخِ کامل برای صفحه‌خوان و راهنمای نشانگر. دکمه‌ی
 * «کارهای پیام» بیرونِ حباب و رو به میانه است و با نشانگر، فوکوس یا منوی باز دیده می‌شود (`.msg-more`).
 */
export function MessageBubble({
  message,
  isAdmin,
  isPinned,
  menuOpen,
  onToggleMenu,
  handlers,
  showSender = true,
  tail = true,
}: {
  message: ChatMessage
  isAdmin: boolean
  isPinned: boolean
  menuOpen: boolean
  onToggleMenu: (open: boolean) => void
  handlers: BubbleHandlers
  /** نام و برچسبِ فرستنده — نخستینِ دسته‌ی پیامِ دیگران */
  showSender?: boolean
  /** گوشه‌ی دُم‌دار — آخرینِ دسته */
  tail?: boolean
}) {
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
  const { time } = splitSentAt(message.sentAt)
  const isManagement = message.unitLabel === 'مدیریت'

  function openMenu(open: boolean) {
    if (open) {
      const wrapper = triggerRef.current?.closest('.chat-row')
      const frame = wrapper?.closest('.overflow-y-auto')
      if (wrapper && frame) {
        const row = wrapper.getBoundingClientRect()
        const box = frame.getBoundingClientRect()
        /*
         * 🔴 ۱۲/۷ (سفرِ مرورگرِ `messenger-journey`): فقط جای **پایین** سنجیده می‌شد؛ پیامِ بالای قاب با جای کمِ پایین
         * منو را رو به بالا باز می‌کرد، بیرون از قابِ اسکرول — زیرِ سربرگِ گفت‌وگو و کلیک‌ناپذیر. حالا رو به بالا
         * فقط وقتی پایین جا نیست **و** بالا جای بیشتری هست.
         */
        const below = box.bottom - row.bottom
        const above = row.top - box.top
        setOpenUp(below < MENU_ROOM && above > below)
      }
    }
    onToggleMenu(open)
  }

  return (
    <div
      className="msg-wrap relative"
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
          'msg-bubble',
          message.isMine ? 'msg-mine' : 'msg-theirs',
          tail && 'msg-tail',
          message.isHidden && 'opacity-50',
          message.status === 'pending' && 'opacity-70',
        )}
      >
        {showSender && !message.isMine && (
          <div className="msg-sender text-tiny">
            <span className={cn('font-bold', `msg-name-${nameTone(message.authorName)}`)}>
              {message.authorName}
            </span>
            {message.unitLabel &&
              (isManagement ? (
                <span className="msg-badge rounded-full px-1.5 font-medium">
                  {message.unitLabel}
                </span>
              ) : (
                <span style={{ color: 'var(--text-tertiary)' }}>{message.unitLabel}</span>
              ))}
          </div>
        )}

        {message.replyTo && (
          <button
            type="button"
            onClick={() => message.replyTo && handlers.onJump(message.replyTo.id)}
            className="msg-quote mb-1 mt-0.5 block w-full rounded-lg px-2 py-0.5 text-right text-tiny leading-5"
          >
            <span className={cn('block font-bold', !message.isMine && 'msg-name-0')}>
              {message.replyTo.authorName}
            </span>
            <span className="block truncate opacity-80">
              {message.replyTo.excerpt ??
                (message.replyTo.isDeleted ? 'این پیام حذف شد' : 'پیامِ در دسترس نیست')}
            </span>
          </button>
        )}

        {message.forwardedFrom && !message.isDeleted && (
          <p className="mb-0.5 text-tiny italic opacity-80">
            هدایت‌شده از {message.forwardedFrom.authorName}
          </p>
        )}

        {message.isDeleted ? (
          <p className="inline-flex items-center gap-1.5 text-small italic opacity-80">
            <Trash2 size={12} />{' '}
            {message.removedByModeration
              ? 'این پیام به دستِ مدیریتِ گروه حذف شد.'
              : 'این پیام حذف شد.'}
          </p>
        ) : message.body === null ? (
          <p className="inline-flex items-center gap-1.5 text-small italic opacity-80">
            <EyeOff size={12} /> این پیام توسط مدیر پنهان شده است.
          </p>
        ) : (
          <>
            {message.attachment?.kind === 'video' ||
            message.attachment?.kind === 'video_message' ? (
              // ۱۰/۱۲: پخش از همان مسیرِ مجاز با Range؛ پیامِ ویدیویی گرد، مثلِ پیام‌رسان‌های آشنا.
              // ⚠️ ویدیوی کاربر زیرنویس ندارد؛ `<track>`ِ خالی فقط قاعده را ساکت می‌کرد — همان کمبودِ ثبت‌شده‌ی پیامِ صوتی.
              // eslint-disable-next-line jsx-a11y/media-has-caption -- کمبودِ ثبت‌شده در CANONICAL_INVENTORY (۱۰/۱۲)
              <video
                controls
                playsInline
                preload="metadata"
                src={message.attachment.url}
                aria-label={
                  message.attachment.kind === 'video_message'
                    ? 'پیامِ ویدیویی'
                    : message.attachment.name
                }
                className={cn(
                  'msg-media max-w-full',
                  message.attachment.kind === 'video_message'
                    ? 'h-48 w-48 rounded-full object-cover'
                    : 'max-h-72 rounded-lg',
                )}
              />
            ) : message.attachment?.kind === 'audio' ? (
              // ⚠️ پیامِ صوتیِ ساکن متنِ پیاده‌شده ندارد؛ `<track>`ِ خالی فقط قاعده را ساکت می‌کرد.
              // eslint-disable-next-line jsx-a11y/media-has-caption -- کمبودِ ثبت‌شده در CANONICAL_INVENTORY (فاز ۱۴)
              <audio
                controls
                preload="none"
                src={message.attachment.url}
                className="msg-media block w-60 max-w-full"
                aria-label="پیامِ صوتی"
              />
            ) : message.attachment?.kind === 'image' ? (
              /* cls-safe: ابعاد از ظرفِ ثابت می‌آید */
              <a
                href={message.attachment.url}
                target="_blank"
                rel="noreferrer"
                className="msg-media block h-64 w-full max-w-xs"
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
                className="msg-quote msg-media flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-small underline"
              >
                <FileText size={14} />
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

            {message.body && <span className="whitespace-pre-line">{message.body}</span>}
          </>
        )}

        {/* زمان و وضعیت کنارِ متن (`.msg-meta` شناور در لبه‌ی پایانی) */}
        <span className="msg-meta">
          {isPinned && <Pin size={11} aria-label="سنجاق‌شده" />}
          {message.isEdited && <span>ویرایش‌شده</span>}
          {typeof message.readCount === 'number' && message.readCount > 0 && (
            <span className="flex items-center gap-0.5 tabular-nums" title="شمارِ خواننده‌ها">
              <Eye size={11} />
              {message.readCount}
            </span>
          )}
          {message.sentAt && (
            <time className="tabular-nums" title={message.sentAt}>
              <span aria-hidden="true">{time || message.sentAt}</span>
              <span className="sr-only">{message.sentAt}</span>
            </time>
          )}
          {message.isMine && message.status && <StatusIcon status={message.status} />}
        </span>

        {message.status === 'failed' && (
          <span className="mt-1 flex items-center gap-3 text-tiny">
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
          </span>
        )}
        <span className="msg-clear" aria-hidden="true" />
      </div>

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
          className={cn(
            'msg-more absolute top-1 flex h-8 w-8 items-center justify-center rounded-full border',
            message.isMine ? 'msg-more-mine' : 'msg-more-theirs',
          )}
          style={{
            backgroundColor: 'var(--surface-raised)',
            borderColor: 'var(--border-subtle)',
            color: 'var(--text-secondary)',
          }}
        >
          <MoreHorizontal size={16} />
        </button>
      )}

      {message.reactions && message.reactions.length > 0 && (
        <div className="msg-reacts relative flex flex-wrap gap-1 px-2" aria-label="واکنش‌ها">
          {message.reactions.map((reaction) => (
            <button
              key={reaction.emoji}
              type="button"
              onClick={() => handlers.onReact(reaction.emoji)}
              aria-pressed={reaction.mine}
              aria-label={`${reaction.emoji} ${reaction.count}`}
              className="msg-react flex items-center gap-1 rounded-full border px-2 text-tiny tabular-nums"
              style={{
                borderColor: reaction.mine ? 'var(--color-brand-300)' : 'var(--border-subtle)',
                backgroundColor: reaction.mine ? 'var(--tone-brand-bg)' : 'var(--surface-raised)',
                color: 'var(--text-secondary)',
                boxShadow: 'var(--msg-shadow)',
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
