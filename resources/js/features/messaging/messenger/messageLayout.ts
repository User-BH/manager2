import { formatJalaliDate } from '@/shared/lib/format'
import type { ChatMessage } from './types'

/**
 * چیدمانِ رشته‌ی پیام — مرحلهٔ ۱۲ · فاز ۷ (بندهای ۴۱ و ۴۳ ِ سندِ هسته).
 *
 * منطقِ خالص، جدا از رندر تا آزمون‌پذیر باشد:
 *
 *   • **گروه‌بندی:** پیام‌های پشتِ‌سرِهمِ یک نفر در یک روز یک دسته‌اند — نام فقط روی اولی، آواتار و گوشه‌ی
 *     دُم‌دار روی آخری. پیام‌ها شلوغ و تکراری دیده نمی‌شوند.
 *   • **جداکننده‌ی روز:** با عوض شدنِ روز («امروز»، «دیروز» یا تاریخِ شمسی).
 *   • **مرزِ نخوانده:** پیش از نخستین پیامِ نخوانده‌ی دیگران، فقط همان لحظه‌ی باز کردن.
 *
 * زمان از رشته‌ی `sentAt` ِ سرور («۱۴۰۵/۰۷/۲۵ ۱۵:۳۸»، وقتِ تهران) خوانده می‌شود، نه ساعتِ دستگاه.
 */

export interface RowLayout {
  /** برچسبِ روز اگر این پیام نخستینِ روزش است */
  day: string | null
  /** نخستینِ دسته: نامِ فرستنده */
  groupStart: boolean
  /** آخرینِ دسته: آواتار و گوشه‌ی دُم‌دار */
  groupEnd: boolean
  /** پیش از این پیام «پیام‌های خوانده‌نشده» */
  unreadStart: boolean
}

/** «۱۴۰۵/۰۷/۲۵ ۱۵:۳۸» ← روز و ساعت؛ رشته‌ی بی‌ساعت همه روز است */
export function splitSentAt(sentAt: string): { day: string; time: string } {
  const [day = '', time = ''] = sentAt.trim().split(/\s+/)

  return { day, time }
}

/** همان قالبِ تاریخِ سرور، از ساعتِ دستگاه — فقط برای «امروز» و «دیروز» */
function jalali(date: Date): string {
  return formatJalaliDate(date).replace(/[‎‏]/g, '')
}

export function dayLabel(day: string, now: Date = new Date()): string {
  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)

  if (day === jalali(now)) return 'امروز'
  if (day === jalali(yesterday)) return 'دیروز'

  return day
}

function sameAuthor(a: ChatMessage, b: ChatMessage): boolean {
  if (a.isMine || b.isMine) return a.isMine && b.isMine

  return a.authorName === b.authorName && a.unitLabel === b.unitLabel
}

export function layoutRows(
  messages: ChatMessage[],
  firstUnreadId: number | null = null,
  now: Date = new Date(),
): RowLayout[] {
  return messages.map((message, index) => {
    const previous = messages[index - 1]
    const next = messages[index + 1]
    const day = splitSentAt(message.sentAt).day
    const newDay = !previous || splitSentAt(previous.sentAt).day !== day
    const unreadStart = firstUnreadId !== null && message.id === firstUnreadId
    const nextBreaks =
      !next ||
      !sameAuthor(message, next) ||
      splitSentAt(next.sentAt).day !== day ||
      (firstUnreadId !== null && next.id === firstUnreadId)

    return {
      day: newDay && day !== '' ? dayLabel(day, now) : null,
      groupStart: newDay || unreadStart || !previous || !sameAuthor(previous, message),
      groupEnd: nextBreaks,
      unreadStart,
    }
  })
}

/**
 * نخستین پیامِ نخوانده از شمارِ نخوانده‌ی گفت‌وگو هنگامِ باز شدن: n پیامِ آخرِ دیگران.
 * بیشتر از آن‌چه بار شده؟ مرز روی قدیمی‌ترینِ بارشده.
 */
export function firstUnreadId(messages: ChatMessage[], unreadCount: number): number | null {
  if (unreadCount <= 0) return null
  const theirs = messages.filter((m) => !m.isMine && m.id > 0)
  if (theirs.length === 0) return null

  return theirs[Math.max(0, theirs.length - unreadCount)].id
}

/** رنگِ ثابتِ نامِ هر فرستنده (چهار رنگِ دسترس‌پذیر در `app.css`) */
export function nameTone(name: string): number {
  let hash = 0
  for (const char of name) hash = (hash * 31 + (char.codePointAt(0) ?? 0)) >>> 0

  return hash % 4
}
