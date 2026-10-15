import type { MessagePoll } from '@/shared/ui/PollCard'

/** پیام‌رسانِ گفت‌وگومحور (مرحلهٔ ۶ · فاز ۱۲) — شکلِ پاسخ‌های API. */

/**
 * ۱۰/۹: `channel` گروهِ مجتمع است، `user` گفت‌وگوی خصوصیِ یک عضو با مدیریت، `pair` «مالک ↔ مستأجر»، `saved`
 * «پیام‌های ذخیره‌شده»، و `unit` بایگانیِ فقط‌خواندنیِ رشته‌ی مشترکِ پیشینِ واحد.
 */
export type ConversationType = 'channel' | 'unit' | 'user' | 'group' | 'pair' | 'saved'

export interface ConversationSummary {
  id: number
  type: ConversationType
  /** از دیدِ همین بیننده: ساکن «مدیریتِ ساختمان» می‌بیند، مدیر «واحد ۱۰۱». */
  title: string
  canPost: boolean
  /** چرا این بیننده نمی‌نویسد؛ وقتی می‌نویسد null. */
  readOnlyReason: string | null
  membersCanPost: boolean
  unreadCount: number
  /**
   * «آنلاین تا» ِ طرفِ گفت‌وگوی یک‌به‌یک (ثانیه‌ی یونیکس، ساعتِ سرور) — فقط «مالک ↔ مستأجر» و، برای مدیر،
   * گفت‌وگوی خصوصیِ عضو؛ بقیه null («چند نکته»، بندِ ۳).
   */
  peerOnlineUntil?: number | null
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
  /** فقط برای مدیر، برای ساختِ گروه. */
  units: { id: number; label: string }[]
  /** فقط برای مدیر: اعضای فعال، برای گفت‌وگوی خصوصی با یک نفر (۱۰/۹). */
  members: { id: number; label: string }[]
  /** ساعتِ سرور (ثانیه) برای `peerOnlineUntil` */
  serverTime?: number
}

/** اطلاعاتِ گروه (۱۰/۹) — همه از عضویتِ فعال، سمتِ سرور. */
export interface GroupInfo {
  conversation: {
    id: number
    type: ConversationType
    title: string
    membersCanPost: boolean
    canManage: boolean
  }
  /** `online`: اعضای فعالِ آنلاین («چند نکته»، بندِ ۳) */
  counts: { members: number; owners: number; tenants: number; managers: number; online?: number }
  serverTime?: number
  members: {
    id: number
    name: string
    avatarUrl: string | null
    role: string
    roleLabel: string
    unit: string | null
    isModerator: boolean
    /** «آنلاین تا» (ثانیه‌ی یونیکس، ساعتِ سرور) یا null */
    onlineUntil?: number | null
  }[]
  page: number
  hasMore: boolean
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
  /** تصویرِ **فعلیِ** نویسنده (مرحلهٔ ۱۰ · فاز ۷)؛ نام snapshot است، تصویر canonical */
  authorAvatarUrl?: string | null
  unitLabel: string
  isMine: boolean
  isHidden: boolean
  sentAt: string
  /** ۱۰/۱۲: ویدیو و پیامِ ویدیویی هم */
  attachment?: {
    name: string
    kind: 'image' | 'file' | 'audio' | 'video' | 'video_message'
    url: string
  } | null
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
  /** ۱۰/۱۰: در گروه مدیر و ناظر پیامِ دیگران را هم حذف می‌کنند — باز هم سرور می‌گوید */
  canDelete?: boolean
  /** سنجاق در این گفت‌وگو: گروه مدیر و ناظر، خصوصی هر دو طرف (۱۰/۱۰) */
  canPin?: boolean
  canForward?: boolean
  /** نسخه‌ی هدایت‌شده: نامِ نویسنده‌ی مبدأ همان لحظه */
  forwardedFrom?: { authorName: string } | null
  /** حذف به دستِ مدیر یا ناظر، نه نویسنده */
  removedByModeration?: boolean
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
  /** ۱۰/۱۰: چند سنجاق، تازه‌ترین اول */
  pins?: ChatMessage[]
  rights?: { isModerator: boolean; canPin: boolean; canDeleteOthers: boolean }
  uploads?: UploadRules
  conversation: ConversationSummary
  /** شمارِ اعضای آنلاینِ گروه؛ گفت‌وگوی یک‌به‌یک null («چند نکته»، بندِ ۳) */
  onlineCount?: number | null
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

/** سقفِ پیوست‌ها به کیلوبایت و پسوندهای پذیرفته — از سرور (۱۰/۱۲؛ بندِ ۴۶) */
export interface UploadRules {
  limitsKb: Record<'image' | 'file' | 'audio' | 'video' | 'voice' | 'video_message', number>
  extensions: string[]
}
