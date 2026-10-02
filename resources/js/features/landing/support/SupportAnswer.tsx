import { Fragment, type CSSProperties, type ReactNode } from 'react'
import { MessageSquareHeart, Phone } from 'lucide-react'
import {
  BaleIcon,
  InstagramIcon,
  RubikaIcon,
  TelegramIcon,
  WhatsappIcon,
} from '@/shared/common/SocialIcons'
import { socialHover } from '@/shared/config/brand'
import { toAsciiDigits } from '@/shared/lib/inputFilters'
import type { SiteContent } from '@/shared/hooks'
import type { SupportEntry } from './supportContent'

const socialIcons: Record<string, (props: { size?: number }) => ReactNode> = {
  instagram: InstagramIcon,
  telegram: TelegramIcon,
  whatsapp: WhatsappIcon,
  rubika: RubikaIcon,
  bale: BaleIcon,
}

/**
 * `**این**` → پررنگ (مرحلهٔ ۷ · فاز ۴).
 *
 * 🔴 پیش از این پاسخ‌ها خام رندر می‌شدند و کاربر خودِ ستاره‌ها را می‌دید
 * («**بستنِ نهایی با خودِ ساکن است**»). فقط همین یک نشانه پذیرفته می‌شود و
 * خروجی همیشه گره‌ی متنیِ React است — هیچ HTMLی از محتوا ساخته نمی‌شود.
 */
export function emphasize(text: string): ReactNode[] {
  return text.split(/\*\*(.+?)\*\*/g).map((part, index) =>
    index % 2 === 1 ? (
      <strong key={index} style={{ color: 'var(--text-primary)' }}>
        {part}
      </strong>
    ) : (
      <Fragment key={index}>{part}</Fragment>
    ),
  )
}

function telHref(phone: string): string {
  return 'tel:' + toAsciiDigits(phone).replace(/[^\d+]/g, '')
}

/**
 * پاسخِ یک پرسش، به‌اضافه‌ی راهِ ارتباطیِ **واقعی** اگر پرسش به یکی اشاره کند.
 *
 * شماره و شبکه‌ها از `/site-settings` می‌آیند — همان که فوتر نشان می‌دهد و
 * ادمینِ کل ویرایش می‌کند. راهی که خاموش است اصلاً نشان داده نمی‌شود.
 */
export function SupportAnswer({
  entry,
  site,
  onFeedback,
}: {
  entry: SupportEntry
  site: SiteContent
  onFeedback: () => void
}) {
  return (
    <div className="px-3 pb-5">
      <p className="text-body leading-8" style={{ color: 'var(--text-secondary)' }}>
        {emphasize(entry.answer)}
      </p>

      {entry.channel === 'phone' && site.contact.phone && (
        <a
          href={telHref(site.contact.phone)}
          className="mt-3 inline-flex items-center gap-2 rounded-xl border px-4 py-2.5 text-body font-bold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--color-brand-600)' }}
        >
          <Phone size={16} />
          <span dir="ltr">{site.contact.phone}</span>
        </a>
      )}

      {entry.channel === 'social' && site.social.length > 0 && (
        <ul className="mt-3 flex flex-wrap gap-2">
          {site.social.map((item) => {
            const Icon = socialIcons[item.id]
            return (
              <li key={item.id}>
                <a
                  href={item.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="social-icon-link inline-flex items-center gap-2 rounded-xl border px-3.5 py-2 text-small font-semibold transition-all hover:-translate-y-0.5 hover:border-transparent hover:text-white"
                  style={
                    {
                      borderColor: 'var(--border-default)',
                      color: 'var(--text-secondary)',
                      '--social-hover-bg': socialHover[item.id] ?? 'var(--color-brand-500)',
                    } as CSSProperties
                  }
                >
                  {Icon && <Icon size={16} />}
                  {item.label}
                </a>
              </li>
            )
          })}
        </ul>
      )}

      {entry.channel === 'feedback' && (
        <button
          type="button"
          onClick={onFeedback}
          className="mt-3 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-body font-bold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <MessageSquareHeart size={16} />
          رفتن به فرمِ بازخورد
        </button>
      )}
    </div>
  )
}
