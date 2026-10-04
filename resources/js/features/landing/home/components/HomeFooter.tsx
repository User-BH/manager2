import { type CSSProperties } from 'react'
import { Link } from '@/shared/lib/mpaNav'
import { Mail, Phone, MapPin } from 'lucide-react'
import { scrollToSection } from '@/shared/lib/scroll'
import { toAsciiDigits } from '@/shared/lib/inputFilters'
import { Logo } from '@/shared/common/Logo'
import {
  InstagramIcon,
  TelegramIcon,
  WhatsappIcon,
  RubikaIcon,
  BaleIcon,
} from '@/shared/common/SocialIcons'
import { BRAND_NAME, socialHover, socialLinks } from '@/shared/config/brand'
import { PANEL_TOUR } from '@/features/landing/content/sections'
import { useSiteContent } from '@/shared/hooks'
import { isBlogEnabled, isViewerAuthenticated } from '@/shared/lib/viewer'
import { TrustSeal } from './TrustSeal'

const socialIconMap = {
  instagram: InstagramIcon,
  telegram: TelegramIcon,
  whatsapp: WhatsappIcon,
  rubika: RubikaIcon,
  bale: BaleIcon,
} as const

/**
 * نامِ دقیقِ هر شبکه برای تولتیپ — از شناسه، نه از برچسبِ پنلِ مدیر.
 *
 * برچسب را ادمین می‌تواند هر چیزی بنویسد («پیجِ ما»)؛ تولتیپ باید بگوید این
 * آیکون **کدام** شبکه است (مرحلهٔ ۷ · فاز ۵).
 */
const platformName: Record<string, string> = Object.fromEntries(
  socialLinks.map((s) => [s.id, s.label]),
)

/** لینکِ تماسِ تلفنی از رقم‌های لاتین ساخته می‌شود (شماره‌ی فارسی در tel: کار نمی‌کند). */
function telHref(phone: string): string {
  return 'tel:' + toAsciiDigits(phone).replace(/[^\d+]/g, '')
}

/** فوترِ صفحه‌ی اصلی — فقط همان‌جا (بلاگ از مرحلهٔ ۸ · فاز ۱ فوتر ندارد). */
export function HomeFooter() {
  const authenticated = isViewerAuthenticated()

  // تماس و شبکه‌ها از پنلِ مدیرِ کل می‌آیند؛ تا رسیدنشان، پیش‌فرض (مرحلهٔ ۷ · فاز ۴: مشترک با پشتیبانی)
  const { contact, social } = useSiteContent()

  return (
    <footer
      className="border-t"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      {/*
        فاصله‌ی پایین (مرحلهٔ ۸ · فاز ۱): ‎۹۶px ِ خالیِ زیرِ ردیفِ آخر (فاز ۵) برای
        این بود که دکمه‌های ثابتِ پایین‌راست (دستیار و «بالا») روی متن نیفتند.
        حالا خودِ ردیفِ آخر از ستونِ آن دکمه‌ها کنار می‌رود (پایین‌تر)، پس
        فاصله‌ی پایین در همه‌ی عرض‌ها کوتاه است.
      */}
      <div className="mx-auto max-w-6xl px-4 pt-14 pb-7 sm:px-6" dir="rtl">
        {/*
          ─── چیدمانِ ریسپانسیو (مرحلهٔ ۷ · فاز ۵) ─────────────────────────
          پیش از این زیرِ ‎۱۰۲۴ همه‌چیز تک‌ستونی می‌شد: ‎۱۰۷۰px ارتفاع در
          تبلت، با فهرست‌هایی که نصفِ عرض را خالی می‌گذاشتند.

            ‎≥۱۰۲۴  چهار ستون کنارِ هم
            ‎۷۶۸+   برند در یک ردیف؛ زیرش سه ستون (دسترسی، پشتیبانی، تماس)
            زیرِ آن برند و تماس هر کدام یک ردیف؛ دو فهرستِ پیوند کنارِ هم

          دو فهرستِ پیوند حتی روی ‎۳۲۰px هم کنارِ هم جا می‌شوند (بلندترین
          برچسب «قوانین و مقررات» است)، پس روی موبایل هم ستون‌به‌ستون نمی‌روند.
        */}
        <div className="grid grid-cols-2 gap-x-6 gap-y-10 md:grid-cols-3 lg:grid-cols-[1.1fr_1fr_1fr_1.2fr]">
          {/* ستون برند و توضیح */}
          <div className="col-span-2 md:col-span-3 lg:col-span-1">
            <Logo size={32} />
            <p
              className="mt-4 max-w-xs text-[13px] leading-7"
              style={{ color: 'var(--text-secondary)' }}
            >
              {BRAND_NAME} یک پلتفرم یکپارچه برای مدیریت مالی، امنیتی و ارتباطی مجتمع‌های مسکونی و
              تجاری است؛ همراه مدیران ساختمان در سراسر کشور.
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
                      className="social-icon-link group relative flex h-9 w-9 items-center justify-center rounded-full border transition-all duration-200 hover:-translate-y-0.5 hover:border-transparent hover:text-white focus-visible:border-transparent focus-visible:text-white"
                      style={
                        {
                          borderColor: 'var(--border-subtle)',
                          color: 'var(--text-secondary)',
                          '--social-hover-bg': socialHover[item.id] ?? 'var(--color-brand-500)',
                        } as CSSProperties
                      }
                    >
                      <Icon size={16} />
                      {/*
                        تولتیپ (فاز ۵). لبه‌ی **راستش** به لبه‌ی راستِ آیکون
                        بسته است و به داخلِ صفحه باز می‌شود: آیکون‌ها همیشه
                        در ابتدای (راستِ) ردیف‌اند، پس وسط‌چین روی موبایل
                        اولین تولتیپ را از لبه‌ی صفحه بیرون می‌زد.
                        `aria-hidden` چون همان نام در `aria-label` هست.
                      */}
                      <span
                        aria-hidden
                        className="social-tooltip pointer-events-none absolute bottom-full right-0 mb-2 translate-y-1 whitespace-nowrap rounded-lg px-2.5 py-1 text-xs font-medium opacity-0 shadow-lg transition-all duration-200 group-hover:translate-y-0 group-hover:opacity-100 group-focus-visible:translate-y-0 group-focus-visible:opacity-100"
                        style={{
                          backgroundColor: 'var(--text-primary)',
                          color: 'var(--surface-base)',
                        }}
                      >
                        {platformName[item.id] ?? item.label}
                      </span>
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
              { label: PANEL_TOUR.name, section: PANEL_TOUR.id },
              { label: 'نظرات کاربران', section: 'testimonials' },
              { label: 'مشاهده دمو', to: '/demo' },
              ...(isBlogEnabled() ? [{ label: 'بلاگ', to: '/blog' }] : []),
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
              // فرمِ بازخورد، پیشنهاد و گزارشِ مشکل (مرحلهٔ ۷ · فاز ۴)
              { label: 'ارسال بازخورد', to: '/support#feedback' },
            ]}
          />

          {/*
            ستون تماس و نقشه — عنوان و هر ردیف از پنلِ مدیر می‌آید و می‌تواند خاموش باشد.

            نشانِ اینماد هم اینجاست (فاز ۵)، در همان فضای خالیِ زیرِ تلفن؛ پیش
            از این در ردیفِ کپی‌رایت بود و این ستون تا خط نیمه‌خالی می‌ماند.
            ⚠️ فقط **جای** گره عوض شد — خودِ کدِ اینماد در Blade دست نخورده.
            وقتی ستون تمام‌عرض است، نشان کنارِ تلفن می‌نشیند؛ در ستونِ باریک زیرش.
          */}
          <div className="col-span-2 md:col-span-1">
            <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
              {contact.title}
            </p>

            <div className="mt-4 flex items-start justify-between gap-5 md:flex-col md:justify-start">
              <div className="min-w-0 flex-1 md:w-full">
                <ul
                  className="flex flex-col gap-2.5 text-[13px]"
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

              <TrustSeal />
            </div>
          </div>
        </div>

        {/*
          ردیفِ پایین (فاز ۵): دو جمله کنارِ هم — کپی‌رایت در راست، دیگری در چپ.
          روی موبایل زیرِ هم و وسط‌چین.

          حاشیه‌ی دوطرفه (مرحلهٔ ۸ · فاز ۱): دکمه‌ی دستیار از لبه‌ی راست تا
          ‎۷۶px را می‌گیرد و «بالا» تا ‎۶۴px. موبایل: ‎۱۶+۶۴=‎۸۰px. ‎۶۴۰ تا ‎۱۲۸۰:
          ‎۲۴+۵۶=‎۸۰px. از ‎۱۲۸۰ لبه‌ی محتوا خودش ‎۸۸px دور است. دوطرفه تا متنِ
          وسط‌چین وسط بماند. سنجش: E2E ِ `responsive.spec.ts` در همه‌ی عرض‌ها.
        */}
        <div
          className="mt-10 flex flex-col items-center gap-1.5 border-t px-16 pt-5 text-center text-xs leading-5 sm:flex-row sm:justify-between sm:px-14 sm:text-start xl:px-0"
          data-footer-bottom
          style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-tertiary)' }}
        >
          <p>
            © {new Date().getFullYear()} {BRAND_NAME}. تمامی حقوق محفوظ است.
          </p>
          <p>ساخته‌شده با ❤️ برای مدیران مجتمع‌های مسکونی و تجاری</p>
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
