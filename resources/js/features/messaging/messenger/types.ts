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
  attachment?: { name: string; kind: 'image' | 'file'; url: string } | null
  poll?: MessagePoll | null
  /** شمارِ خواننده‌ها — فقط برای مدیر. */
  readCount?: number | null
  conversationId?: number | null
  /** شناسه‌ای که کلاینت پیش از ارسال ساخته؛ فقط برای پیامِ خودِ بیننده. */
  clientId?: string | null
  status?: DeliveryStatus | null
}

export interface MessagesResponse {
  messages: ChatMessage[]
  hasOlder: boolean
  nextCursor: number | null
  hiddenIds: number[]
  /** بزرگ‌ترین پیامِ من که کسِ دیگری خوانده؛ هر پیامِ من تا آن‌جا «خوانده شد» است. */
  readUpTo: number
  conversation: ConversationSummary
  canSend: boolean
  isAdmin: boolean
}
