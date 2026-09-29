import type { MessagePoll } from '@/shared/ui/PollCard'

/** پیام‌رسانِ گفت‌وگومحور (مرحلهٔ ۶ · فاز ۱۲) — شکلِ پاسخ‌های API. */

export type ConversationType = 'channel' | 'unit' | 'user' | 'group'

export interface ConversationSummary {
  id: number
  type: ConversationType
  /** از دیدِ همین بیننده: ساکن «مدیریتِ ساختمان» می‌بیند، مدیر «واحد ۱۰۱». */
  title: string
  canPost: boolean
  membersCanPost: boolean
  unreadCount: number
  lastMessage: {
    /** پیامِ پنهان‌شده برای ساکن پیش‌نمایش ندارد. */
    preview: string | null
    authorName: string
    isMine: boolean
    sentAt: string
  } | null
}

export interface ConversationsResponse {
  data: ConversationSummary[]
  hasMore: boolean
  nextCursor: string | null
  canSend: boolean
  reason: string | null
  isAdmin: boolean
  unreadTotal: number
  /** فقط برای مدیر، برای شروعِ رشته یا ساختِ گروه. */
  units: { id: number; label: string }[]
}

/**
 * وضعیتِ تحویل از دیدِ فرستنده.
 *
 * `pending` و `failed` فقط در کلاینت‌اند (پیش از پاسخِ سرور)؛ `sent` و `read`
 * از سرور می‌آیند — `read` یعنی کسِ دیگری رسیدِ خواندن زده.
 */
export type DeliveryStatus = 'pending' | 'failed' | 'sent' | 'read'

export interface ChatMessage {
  id: number
  /** پیامِ پنهان‌شده برای ساکن متن ندارد؛ سرور اصلاً نمی‌فرستدش. */
  body: string | null
  authorName: string
  unitLabel: string
  isMine: boolean
  isHidden: boolean
  sentAt: string
  attachment?: { name: string; kind: 'image' | 'file' | 'audio'; url: string } | null
  poll?: MessagePoll | null
  /** شمارِ خواننده‌ها — فقط برای مدیر. */
  readCount?: number | null
  conversationId?: number | null
  /** شناسه‌ای که کلاینت پیش از ارسال ساخته؛ فقط برای پیامِ خودِ بیننده. */
  clientId?: string | null
  status?: DeliveryStatus | null

  /* ─── غنی‌سازی (فاز ۱۴) — همه اختیاری: پاسخِ API قدیم هم همین شکل را می‌خواند ─── */
  /** حذف‌شده برای همه بی‌متن و بی‌پیوست است — حتی مدیر. */
  isDeleted?: boolean
  isEdited?: boolean
  /** سرور می‌گوید، نه کلاینت: پنجره‌ی ۱۵ دقیقه و پنهان‌نبودن را همان‌جا می‌سنجد. */
  canEdit?: boolean
  canDelete?: boolean
  replyTo?: ReplyPreview | null
  reactions?: ReactionSummary[]
}

export interface ReplyPreview {
  id: number
  authorName: string
  /** پنهان‌شده (برای ساکن) یا حذف‌شده: بی‌متن. */
  excerpt: string | null
  isDeleted: boolean
}

export interface ReactionSummary {
  emoji: string
  count: number
  /** فقط «من هم زده‌ام» — نه نامِ دیگران. */
  mine: boolean
}

/** همان فهرستِ ثابتِ سرور (`MessageReaction::ALLOWED`). */
export const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'] as const

export interface MessagesResponse {
  messages: ChatMessage[]
  hasOlder: boolean
  nextCursor: number | null
  hiddenIds: number[]
  /** بزرگ‌ترین پیامِ من که کسِ دیگری خوانده؛ هر پیامِ من تا آن‌جا «خوانده شد» است. */
  readUpTo: number
  /** پیام‌های از قبل دریافت‌شده که ویرایش، حذف، واکنش یا پنهان شده‌اند (فاز ۱۴). */
  changed?: ChatMessage[]
  /** برای `changed_after` در پرسشِ بعدی. */
  serverTime?: number
  pinned?: ChatMessage | null
  conversation: ConversationSummary
  canSend: boolean
  isAdmin: boolean
}

export interface SearchHit {
  id: number
  excerpt: string
  authorName: string
  sentAt: string
}

/** یک سطر در صفِ گزارش‌های مدیر — بی‌نامِ گزارش‌دهنده. */
export interface ReportItem {
  id: number
  messageId: number
  conversationId: number | null
  excerpt: string | null
  authorName: string | null
  isHidden: boolean
  reportCount: number
  reasons: string[]
  reportedAt: string
}
