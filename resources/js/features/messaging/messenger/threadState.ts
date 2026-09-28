import type { ChatMessage } from './types'

/**
 * منطقِ خالصِ فهرستِ پیام‌های یک گفت‌وگو (مرحلهٔ ۶ · فاز ۱۲).
 *
 * جدا از کامپوننت تا بی‌رندر آزموده شود — همان جایی که پیام تکرار یا گم
 * می‌شد، اگر می‌شد.
 *
 * ─── سه قاعده ────────────────────────────────────────────────────────────
 * ۱. ترتیب = شناسه‌ی سرور. پیامِ در راه (شناسه‌ی منفی) همیشه ته است.
 * ۲. هیچ پیامی دو بار نیست: هم با شناسه، هم با `clientId` — پاسخِ ارسال و
 *    پرسشِ دوره‌ای ممکن است همان پیام را هم‌زمان بیاورند.
 * ۳. پیامی که پس از رسیدن پنهان شده، متنش در کلاینتِ ساکن هم پاک می‌شود.
 */

/** شناسه‌ی ارسال: یکتا، و قابلِ فرستادنِ دوباره با همان مقدار. */
export function newClientId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  return `c-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`
}

function order(list: ChatMessage[]): ChatMessage[] {
  return [...list].sort((a, b) => {
    const pendingA = a.id < 0
    const pendingB = b.id < 0
    if (pendingA !== pendingB) return pendingA ? 1 : -1
    return pendingA ? b.id - a.id : a.id - b.id
  })
}

/**
 * پیام‌های تازه (پرسشِ دوره‌ای، صفحه‌ی قدیمی‌تر، یا پاسخِ ارسال) را در فهرست
 * می‌نشاند.
 */
export function mergeMessages(
  current: ChatMessage[],
  incoming: ChatMessage[],
  hiddenIds: number[] = [],
  isAdmin = false,
): ChatMessage[] {
  const byId = new Map<number, ChatMessage>()
  const confirmedClientIds = new Set(
    incoming.map((m) => m.clientId).filter((id): id is string => Boolean(id)),
  )

  for (const message of current) {
    // نسخه‌ی خوش‌بینانه‌ای که حالا پاسخِ سرورش رسیده، کنار می‌رود
    if (message.id < 0 && message.clientId && confirmedClientIds.has(message.clientId)) continue
    byId.set(message.id, message)
  }

  for (const message of incoming) byId.set(message.id, message)

  const hidden = new Set(hiddenIds)

  return order(
    [...byId.values()].map((m) =>
      hidden.has(m.id) && !m.isHidden ? { ...m, isHidden: true, body: isAdmin ? m.body : null } : m,
    ),
  )
}

/**
 * پیام‌های خودم تا `readUpTo` «خوانده شد» می‌شوند.
 *
 * 🔴 بدونِ این، پیامی که پیش از خواندنِ طرفِ مقابل رسیده بود هرگز عوض
 * نمی‌شد: پرسشِ `since` فقط پیامِ تازه می‌آورد (آزمونِ مرورگر پیدایش کرد).
 */
export function applyReadUpTo(list: ChatMessage[], readUpTo: number): ChatMessage[] {
  if (readUpTo <= 0) return list

  return list.map((m) =>
    m.isMine && m.id > 0 && m.id <= readUpTo && m.status === 'sent' ? { ...m, status: 'read' } : m,
  )
}

/** بزرگ‌ترین شناسه‌ی سرور — نقطه‌ی شروعِ پرسشِ `since`. */
export function newestId(list: ChatMessage[]): number {
  return list.reduce((max, m) => (m.id > max ? m.id : max), 0)
}

/** پیامِ در راهی که ارسالش شکست خورد: می‌ماند، با دکمه‌ی «دوباره». */
export function markFailed(list: ChatMessage[], clientId: string): ChatMessage[] {
  return list.map((m) => (m.clientId === clientId && m.id < 0 ? { ...m, status: 'failed' } : m))
}

export function markPending(list: ChatMessage[], clientId: string): ChatMessage[] {
  return list.map((m) => (m.clientId === clientId && m.id < 0 ? { ...m, status: 'pending' } : m))
}

export function removeByClientId(list: ChatMessage[], clientId: string): ChatMessage[] {
  return list.filter((m) => !(m.id < 0 && m.clientId === clientId))
}
