import { useEffect, useState, type CSSProperties } from 'react'
import { Link } from '@/shared/lib/mpaNav'
import { Mail, Phone, MapPin } from 'lucide-react'
import { scrollToSection } from '@/shared/lib/scroll'
import { api } from '@/shared/lib/api'
import { toAsciiDigits } from '@/shared/lib/inputFilters'
import { Logo } from '@/shared/common/Logo'
import {
  InstagramIcon,
  TelegramIcon,
  WhatsappIcon,
  RubikaIcon,
  BaleIcon,
} from '@/shared/common/SocialIcons'
import { BRAND_NAME, contactInfo, socialHover, socialLinks } from '@/shared/config/brand'
import { isViewerAuthenticated } from '@/shared/lib/viewer'

const socialIconMap = {
  instagram: InstagramIcon,
  telegram: TelegramIcon,
  whatsapp: WhatsappIcon,
  rubika: RubikaIcon,
  bale: BaleIcon,
} as const

interface FooterSocial {
  id: string
  label: string
  href: string
}

interface FooterContact {
  title: string
  address: string | null
  phone: string | null
  email: string | null
  mapEmbedUrl: string | null
}

// پیش‌فرض‌ها از config می‌آیند تا فوتر پیش از رسیدنِ داده (یا هنگام خطای شبکه)
// هرگز خالی نماند.
const DEFAULT_CONTACT: FooterContact = {
  title: 'ارتباط با ما',
  address: contactInfo.address,
  phone: contactInfo.phone,
  email: contactInfo.email,
  mapEmbedUrl: contactInfo.mapEmbedUrl,
}
const DEFAULT_SOCIAL: FooterSocial[] = socialLinks.map((s) => ({
  id: s.id,
  label: s.label,
  href: s.href,
}))

/** لینکِ تماسِ تلفنی از رقم‌های لاتین ساخته می‌شود (شماره‌ی فارسی در tel: کار نمی‌کند). */
function telHref(phone: string): string {
  return 'tel:' + toAsciiDigits(phone).replace(/[^\d+]/g, '')
}

export function HomeFooter() {
  const authenticated = isViewerAuthenticated()

  // تماس و شبکه‌ها از پنلِ مدیرِ کل می‌آیند؛ تا رسیدنشان، پیش‌فرض نشان داده می‌شود.
  const [contact, setContact] = useState<FooterContact>(DEFAULT_CONTACT)
  const [social, setSocial] = useState<FooterSocial[]>(DEFAULT_SOCIAL)

  useEffect(() => {
    const controller = new AbortController()

    api<{ contact: FooterContact; social: FooterSocial[] }>('/site-settings', {
      signal: controller.signal,
    })
      .then((data) => {
        setContact(data.contact)
        setSocial(data.social)
      })
      .catch(() => {
        // خطای شبکه: همان پیش‌فرض‌ها می‌مانند
      })

    return () => controller.abort()
  }, [])

  return (
    <footer
      className="border-t"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      <div className="mx-auto max-w-6xl px-4 py-14 sm:px-6" dir="rtl">
        <div className="grid gap-10 lg:grid-cols-[1.1fr_1fr_1fr_1.2fr]">
          {/* ستون برند و توضیح */}
          <div>
            <Logo size={32} />
            <p
              className="mt-4 max-w-xs text-[13px] leading-7"
              style={{ color: 'var(--text-secondary)' }}
            >
              {BRAND_NAME} یک پلتفرم یکپارچه برای مدیریت مالی، امنیتی و ارتباطی مجتمع‌های مسکونی
              است؛ همراه مدیران ساختمان در سراسر کشور.
            </p>

            {social.length > 0 && (
              <div className="mt-5 flex items-center gap-2.5">
                {social.map((item) => {
                  const Icon = socialIconMap[item.id as keyof typeof socialIconMap]
                  if (!Icon) return null
                  return (
                    <a
                      key={item.id}
                      href={item.href}
                      target="_blank"
                      rel="noopener noreferrer"
                      aria-label={item.label}
                      className="social-icon-link flex h-9 w-9 items-center justify-center rounded-full border transition-all duration-200 hover:-translate-y-0.5 hover:border-transparent hover:text-white"
                      style={
                        {
                          borderColor: 'var(--border-subtle)',
                          color: 'var(--text-secondary)',
                          '--social-hover-bg': socialHover[item.id] ?? 'var(--color-brand-500)',
                        } as CSSProperties
                      }
                    >
                      <Icon size={16} />
                    </a>
                  )
                })}
              </div>
            )}
          </div>

          {/* ستون لینک‌های سریع — بخش‌های همین صفحه با اسکرول نرم، بدون تغییر آدرس */}
          <FooterLinkGroup
            title="دسترسی سریع"
            links={[
              { label: 'ویژگی‌ها', section: 'features' },
              { label: 'گالری', section: 'gallery' },
              { label: 'نظرات کاربران', section: 'testimonials' },
              { label: 'مشاهده دمو', to: '/demo' },
              authenticated
                ? { label: 'ورود به داشبورد', to: '/dashboard' }
                : { label: 'ورود به پنل', to: '/auth' },
            ]}
          />

          {/* ستون پشتیبانی — همه به صفحه‌ی پشتیبانی و آکاردیونِ مربوطه می‌روند */}
          <FooterLinkGroup
            title="پشتیبانی"
            links={[
              { label: 'سوالات متداول', to: '/support?topic=faq' },
              { label: 'قوانین و مقررات', to: '/support?topic=terms' },
              { label: 'حریم خصوصی', to: '/support?topic=privacy' },
              { label: 'تماس با ما', to: '/support?topic=contact' },
            ]}
          />

          {/* ستون تماس و نقشه — عنوان و هر ردیف از پنلِ مدیر می‌آید و می‌تواند خاموش باشد */}
          <div>
            <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
              {contact.title}
            </p>

            <ul
              className="mt-4 flex flex-col gap-2.5 text-[13px]"
              style={{ color: 'var(--text-secondary)' }}
            >
              {contact.address && (
                <li className="flex items-start gap-2">
                  <MapPin
                    size={15}
                    className="mt-0.5 shrink-0"
                    style={{ color: 'var(--color-brand-500)' }}
                  />
                  <span>{contact.address}</span>
                </li>
              )}
              {contact.phone && (
                <li className="flex items-center gap-2">
                  <Phone
                    size={15}
                    className="shrink-0"
                    style={{ color: 'var(--color-brand-500)' }}
                  />
                  <a href={telHref(contact.phone)} dir="ltr">
                    {contact.phone}
                  </a>
                </li>
              )}
              {contact.email && (
                <li className="flex items-center gap-2">
                  <Mail
                    size={15}
                    className="shrink-0"
                    style={{ color: 'var(--color-brand-500)' }}
                  />
                  <a href={`mailto:${contact.email}`} dir="ltr">
                    {contact.email}
                  </a>
                </li>
              )}
            </ul>

            {contact.mapEmbedUrl && (
              <div
                className="mt-4 overflow-hidden rounded-2xl border"
                style={{ borderColor: 'var(--border-subtle)' }}
              >
                <iframe
                  title="موقعیت ما روی نقشه"
                  src={contact.mapEmbedUrl}
                  width="100%"
                  height="140"
                  style={{ border: 0, filter: 'saturate(0.85)' }}
                  loading="lazy"
                  referrerPolicy="no-referrer-when-downgrade"
                />
              </div>
            )}
          </div>
        </div>

        <div
          className="mt-10 flex flex-col items-center justify-between gap-3 border-t pt-6 text-xs sm:flex-row"
          style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-tertiary)' }}
        >
          <p>
            © {new Date().getFullYear()} {BRAND_NAME}. تمامی حقوق محفوظ است.
          </p>
          <p>ساخته‌شده با ❤️ برای مدیران مجتمع‌های مسکونی</p>
        </div>
      </div>
    </footer>
  )
}

/** یا به بخشی از همین صفحه اسکرول می‌کند (`section`) یا به مسیری می‌رود (`to`). */
interface FooterLink {
  label: string
  section?: string
  to?: string
}

function FooterLinkGroup({ title, links }: { title: string; links: FooterLink[] }) {
  return (
    <div>
      <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
        {title}
      </p>
      <ul className="mt-4 flex flex-col gap-2.5">
        {links.map((link) => (
          <li key={link.label}>
            {link.to ? (
              <Link
                to={link.to}
                className="inline-block text-[13px] transition-all duration-200 hover:-translate-x-1 hover:opacity-80"
                style={{ color: 'var(--text-secondary)' }}
              >
                {link.label}
              </Link>
            ) : (
              <button
                onClick={() => link.section && scrollToSection(link.section)}
                className="inline-block text-[13px] transition-all duration-200 hover:-translate-x-1 hover:opacity-80"
                style={{ color: 'var(--text-secondary)' }}
              >
                {link.label}
              </button>
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}
