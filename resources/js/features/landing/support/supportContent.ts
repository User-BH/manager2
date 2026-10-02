import { FileText, HelpCircle, MessageCircle, ShieldCheck, type LucideIcon } from 'lucide-react'
import content from '@/../content/support-topics.json'

export type SupportTopicId = 'faq' | 'terms' | 'privacy' | 'contact'

/**
 * راهِ ارتباطیِ واقعی که زیرِ پاسخ نشان داده می‌شود (مرحلهٔ ۷ · فاز ۴).
 *
 * شماره و پیوندِ شبکه‌ها در متن نوشته نمی‌شوند: از همان تنظیماتِ سایت می‌آیند
 * که فوتر می‌خواند و ادمینِ کل ویرایش می‌کند — پس دو جا از هم جدا نمی‌افتند.
 */
export type SupportChannel = 'phone' | 'social' | 'feedback'

export interface SupportEntry {
  question: string
  /** متن؛ `**این**` پررنگ نمایش داده می‌شود. */
  answer: string
  channel?: SupportChannel
}

export interface SupportTopic {
  id: SupportTopicId
  title: string
  short: string
  icon: LucideIcon
  /** رنگ ربع‌دایره‌ی این بخش در هدر. */
  color: string
  entries: SupportEntry[]
}

/*
 * ⚠️ قاعده‌ی متنِ آن فایل: هر ادعا با کد سنجیده شده و قابلیتی که ساخته نشده
 * نمی‌آید (مرحلهٔ ۷ · فاز ۴). `**متن**` پررنگ می‌شود؛ `channel` یعنی دادهٔ
 * واقعیِ تماس از تنظیماتِ سایت زیرِ پاسخ نشان داده شود. توضیح در خودِ JSON
 * نیست چون تا مرورگر می‌رفت و بارگذاریِ اول را سنگین‌تر می‌کرد.
 *
 * ظاهرِ هر بخش اینجاست و متنش در `resources/content/support-topics.json` —
 * همان فایلی که Blade برای داده‌ی ساخت‌یافته‌ی گوگل (FAQPage) می‌خواند. پیش
 * از این دو متنِ جدا بودند و FAQِ گوگل پنج پرسش داشت که روی صفحه نبودند.
 */
const LOOK: Record<SupportTopicId, { icon: LucideIcon; color: string }> = {
  faq: { icon: HelpCircle, color: 'var(--color-brand-500)' },
  terms: { icon: FileText, color: 'var(--color-accent-500)' },
  privacy: { icon: ShieldCheck, color: 'var(--state-info)' },
  contact: { icon: MessageCircle, color: 'var(--color-brand-300)' },
}

const CHANNELS: readonly string[] = ['phone', 'social', 'feedback']

function isChannel(value: unknown): value is SupportChannel {
  return typeof value === 'string' && CHANNELS.includes(value)
}

/**
 * محتوای صفحه‌ی پشتیبانی، از همان فایلِ JSON.
 *
 * ⚠️ شناسه و کانالِ ناشناخته کنار گذاشته می‌شوند، نه با `as` باور: فایلِ
 * محتوا را ممکن است کسی بی‌دقت ویرایش کند.
 */
export const supportTopics: SupportTopic[] = content.topics.flatMap((topic) => {
  if (!(topic.id in LOOK)) return []
  const id = topic.id as SupportTopicId

  return [
    {
      id,
      title: topic.title,
      short: topic.short,
      ...LOOK[id],
      entries: topic.entries.map((entry) => {
        const channel = 'channel' in entry ? entry.channel : undefined
        return {
          question: entry.question,
          answer: entry.answer,
          ...(isChannel(channel) ? { channel } : {}),
        }
      }),
    },
  ]
})

/**
 * ⚠️ نگهبانِ شناسهٔ موضوع برای مقداری که از **آدرس** می‌آید.
 *
 * ─── 🔴 چه چیزی باز بود (مرحلهٔ ۵، فاز ۴ — بندهای ۱۴ و ۳۱) ────────────────
 * `SupportPage` می‌نوشت:
 *
 *     params.get('topic') as SupportTopicId | null
 *
 * یعنی هر رشته‌ای که در `?topic=` می‌آمد، بدونِ بررسی «شناسهٔ معتبر» جا
 * می‌زد. آدرس را کاربر می‌سازد و لینکش را هم می‌شود جای دیگری فرستاد.
 *
 * ⚠️ بندِ ۳۱ صریح است: پارامترهای پرس‌وجو باید Type-safe و **Validated**
 * باشند. ادعای نوع هیچ‌کدام را فراهم نمی‌کند.
 */
export function isSupportTopicId(value: unknown): value is SupportTopicId {
  return typeof value === 'string' && supportTopics.some((topic) => topic.id === value)
}
