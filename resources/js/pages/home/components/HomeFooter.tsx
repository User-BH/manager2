import type { CSSProperties } from 'react'
import { Link } from 'react-router-dom'
import { Mail, Phone, MapPin } from 'lucide-react'
import { scrollToSection } from '@/lib/scroll'
import { Logo } from '@/components/common/Logo'
import { InstagramIcon, TelegramIcon, WhatsappIcon, RubikaIcon } from '@/components/common/SocialIcons'
import { BRAND_NAME, contactInfo, socialLinks } from '@/config/brand'

const socialIconMap = {
  instagram: InstagramIcon,
  telegram: TelegramIcon,
  whatsapp: WhatsappIcon,
  rubika: RubikaIcon,
} as const

export function HomeFooter() {
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
            <p className="mt-4 max-w-xs text-[13px] leading-7" style={{ color: 'var(--text-secondary)' }}>
              {BRAND_NAME} یک پلتفرم یکپارچه برای مدیریت مالی، امنیتی و ارتباطی مجتمع‌های مسکونی
              است؛ همراه مدیران ساختمان در سراسر کشور.
            </p>

            <div className="mt-5 flex items-center gap-2.5">
              {socialLinks.map((social) => {
                const Icon = socialIconMap[social.id]
                if (!Icon) return null
                return (
                  <a
                    key={social.id}
                    href={social.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={social.label}
                    className="social-icon-link flex h-9 w-9 items-center justify-center rounded-full border transition-all duration-200 hover:-translate-y-0.5 hover:border-transparent hover:text-white"
                    style={
                      {
                        borderColor: 'var(--border-subtle)',
                        color: 'var(--text-secondary)',
                        '--social-hover-bg': social.hoverBackground,
                      } as CSSProperties
                    }
                  >
                    <Icon size={16} />
                  </a>
                )
              })}
            </div>
          </div>

          {/* ستون لینک‌های سریع — بخش‌های همین صفحه با اسکرول نرم، بدون تغییر آدرس */}
          <FooterLinkGroup
            title="دسترسی سریع"
            links={[
              { label: 'ویژگی‌ها', section: 'features' },
              { label: 'گالری', section: 'gallery' },
              { label: 'نظرات کاربران', section: 'testimonials' },
              { label: 'مشاهده دمو', to: '/demo' },
              { label: 'ورود به پنل', to: '/auth' },
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

          {/* ستون تماس و نقشه */}
          <div>
            <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
              ارتباط با دفتر مرکزی
            </p>

            <ul className="mt-4 flex flex-col gap-2.5 text-[13px]" style={{ color: 'var(--text-secondary)' }}>
              <li className="flex items-start gap-2">
                <MapPin size={15} className="mt-0.5 shrink-0" style={{ color: 'var(--color-brand-500)' }} />
                <span>{contactInfo.address}</span>
              </li>
              <li className="flex items-center gap-2">
                <Phone size={15} className="shrink-0" style={{ color: 'var(--color-brand-500)' }} />
                <a href="tel:+982188776655" dir="ltr">
                  {contactInfo.phone}
                </a>
              </li>
              <li className="flex items-center gap-2">
                <Mail size={15} className="shrink-0" style={{ color: 'var(--color-brand-500)' }} />
                <a href={`mailto:${contactInfo.email}`} dir="ltr">
                  {contactInfo.email}
                </a>
              </li>
            </ul>

            <div className="mt-4 overflow-hidden rounded-2xl border" style={{ borderColor: 'var(--border-subtle)' }}>
              <iframe
                title="موقعیت دفتر مرکزی روی نقشه"
                src={contactInfo.mapEmbedUrl}
                width="100%"
                height="140"
                style={{ border: 0, filter: 'saturate(0.85)' }}
                loading="lazy"
                referrerPolicy="no-referrer-when-downgrade"
              />
            </div>
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
