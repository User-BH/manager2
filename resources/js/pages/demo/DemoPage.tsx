import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  ArrowRight,
  BadgePercent,
  Building2,
  LayoutDashboard,
  Megaphone,
  MessageSquare,
  Receipt,
  ShieldCheck,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import { useDocumentTitle } from '@/hooks'
import { heroImages } from '@/data/images'
import { HomeNavbar } from '../home/components/HomeNavbar'
import { HomeFooter } from '../home/components/HomeFooter'
import { VideoPlayer, type VideoChapter } from './VideoPlayer'

/** فصل‌های ویدیو — روی نوار پیشرفت هم نشانه می‌خورند. */
const chapters: VideoChapter[] = [
  { at: 0, label: 'داشبورد' },
  { at: 12, label: 'واحدها و ساکنین' },
  { at: 28, label: 'صدور قبض' },
  { at: 45, label: 'پرداخت آنلاین' },
  { at: 60, label: 'اطلاعیه و پیام‌رسان' },
  { at: 75, label: 'گزارش‌ها' },
]

interface DemoFeature {
  icon: LucideIcon
  title: string
  description: string
}

const features: DemoFeature[] = [
  {
    icon: LayoutDashboard,
    title: 'داشبورد یکپارچه',
    description:
      'درآمد، هزینه، مانده صندوق و بدهی کل ساکنین در یک نگاه؛ به‌همراه نمودار روند شش ماه اخیر و فهرست بدهکاران.',
  },
  {
    icon: Building2,
    title: 'واحدها و ساکنین',
    description:
      'ثبت متراژ، طبقه، تعداد نفرات و پارکینگ هر واحد، با امکان چند مالک برای یک واحد و نگه‌داشتن سابقه‌ی جابه‌جایی‌ها.',
  },
  {
    icon: Receipt,
    title: 'صدور قبض و شارژ',
    description:
      'موتور شارژ با هشت روش محاسبه (ثابت، متراژ، نفرات، ضریب و…)؛ قبض هر دوره با یک کلیک برای همه‌ی واحدها صادر می‌شود.',
  },
  {
    icon: Wallet,
    title: 'پرداخت آنلاین و رسید',
    description:
      'اتصال به درگاه بانکی برای پرداخت مستقیم، یا آپلود رسید توسط ساکن و تایید آن توسط مدیر — هر دو مسیر ثبت و پیگیری می‌شود.',
  },
  {
    icon: BadgePercent,
    title: 'جریمه، تخفیف و بخشودگی',
    description:
      'جریمه‌ی دیرکرد به‌صورت خودکار روی قبض معوق اعمال می‌شود و مدیر می‌تواند برای هر واحد تخفیف یا بخشودگی ثبت کند.',
  },
  {
    icon: Megaphone,
    title: 'اطلاعیه‌ها',
    description:
      'اطلاعیه را برای همه، فقط مالکین یا فقط مستاجرها منتشر کنید؛ خوانده‌شدن هر اطلاعیه برای هر کاربر جداگانه دنبال می‌شود.',
  },
  {
    icon: MessageSquare,
    title: 'پیام‌رسان داخلی',
    description:
      'ساکنین می‌توانند پیام بگذارند و مدیر پیام نامناسب را پنهان کند؛ همه‌چیز داخل همان پنل، بدون نیاز به گروه پیام‌رسان بیرونی.',
  },
  {
    icon: ShieldCheck,
    title: 'دسترسی نقش‌محور و بکاپ',
    description:
      'ادمین کل، مدیر مجتمع، مالک و مستاجر هرکدام فقط چیزی را می‌بینند که به آن‌ها مربوط است؛ به‌همراه بکاپ‌گیری و بازیابی.',
  },
]

/**
 * صفحه‌ی دمو — مقصد دکمه‌ی «مشاهده دمو» در صفحه‌ی اصلی.
 *
 * ساختار همان صفحه‌ی فرود است (نوار بالا + فوتر) تا کاربر حس نکند از سایت
 * بیرون رفته؛ فقط محتوای میانی عوض می‌شود.
 */
export function DemoPage() {
  useDocumentTitle('دموی پنل مدیریت')

  return (
    <div style={{ backgroundColor: 'var(--surface-canvas)' }}>
      <HomeNavbar />

      <main className="mx-auto max-w-5xl px-4 pb-20 pt-28 sm:px-6" dir="rtl">
        {/* ---------- سرتیتر ---------- */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
          className="text-center"
        >
          <span
            className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11.5px] font-bold"
            style={{
              backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)',
              color: 'var(--color-brand-600)',
            }}
          >
            دمو
          </span>

          <h1 className="mt-4 text-3xl font-extrabold sm:text-4xl" style={{ color: 'var(--text-primary)' }}>
            یک دور کامل داخل پنل
          </h1>
          <p
            className="mx-auto mt-4 max-w-xl text-[15px] leading-8"
            style={{ color: 'var(--text-secondary)' }}
          >
            در این ویدیوی کوتاه، از داشبورد تا صدور قبض و پرداخت و اطلاعیه‌ها را می‌بینید؛
            همان مسیری که یک مدیر ساختمان هر ماه طی می‌کند.
          </p>
        </motion.div>

        {/* ---------- پخش‌کننده ---------- */}
        <motion.div
          initial={{ opacity: 0, y: 30 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.7, delay: 0.12, ease: [0.22, 1, 0.36, 1] }}
          className="mt-10"
        >
          <VideoPlayer
            src="/videos/demo.mp4"
            poster={heroImages.buildingNight}
            chapters={chapters}
          />
        </motion.div>

        {/* ---------- فصل‌ها ---------- */}
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ duration: 0.5, delay: 0.3 }}
          className="mt-5 flex flex-wrap justify-center gap-2"
        >
          {chapters.map((chapter) => (
            <span
              key={chapter.at}
              className="rounded-full border px-3 py-1 text-[11.5px] font-medium"
              style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-tertiary)' }}
            >
              {chapter.label}
            </span>
          ))}
        </motion.div>

        {/* ---------- توضیح امکانات ---------- */}
        <div className="mt-16">
          <h2 className="text-center text-2xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
            چه چیزهایی در ویدیو می‌بینید
          </h2>

          <div className="mt-8 grid gap-4 sm:grid-cols-2">
            {features.map((feature, index) => {
              const Icon = feature.icon
              return (
                <motion.article
                  key={feature.title}
                  initial={{ opacity: 0, y: 22 }}
                  whileInView={{ opacity: 1, y: 0 }}
                  viewport={{ once: true, margin: '-60px' }}
                  transition={{ duration: 0.45, delay: Math.min(index * 0.06, 0.35) }}
                  whileHover={{ y: -4 }}
                  className="rounded-2xl border p-5"
                  style={{
                    borderColor: 'var(--border-subtle)',
                    backgroundColor: 'var(--surface-base)',
                  }}
                >
                  <span
                    className="flex h-10 w-10 items-center justify-center rounded-xl"
                    style={{
                      backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 13%, transparent)',
                      color: 'var(--color-brand-600)',
                    }}
                  >
                    <Icon size={19} />
                  </span>

                  <h3 className="mt-3.5 text-[15px] font-bold" style={{ color: 'var(--text-primary)' }}>
                    {feature.title}
                  </h3>
                  <p className="mt-2 text-[13px] leading-7" style={{ color: 'var(--text-secondary)' }}>
                    {feature.description}
                  </p>
                </motion.article>
              )
            })}
          </div>
        </div>

        {/* ---------- فراخوان ---------- */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
          transition={{ duration: 0.5 }}
          className="mt-16 overflow-hidden rounded-3xl border p-8 text-center sm:p-10"
          style={{
            borderColor: 'var(--border-subtle)',
            background:
              'linear-gradient(130deg, color-mix(in srgb, var(--color-brand-500) 12%, transparent), transparent 65%)',
            backgroundColor: 'var(--surface-base)',
          }}
        >
          <h2 className="text-xl font-extrabold sm:text-2xl" style={{ color: 'var(--text-primary)' }}>
            آماده‌اید خودتان امتحان کنید؟
          </h2>
          <p className="mx-auto mt-3 max-w-md text-[13.5px] leading-7" style={{ color: 'var(--text-secondary)' }}>
            ساخت حساب رایگان است و برای شروع فقط یک شماره موبایل لازم دارید.
          </p>

          <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
            <Link
              to="/auth?tab=register"
              className="group flex items-center gap-2 rounded-2xl px-6 py-3.5 text-sm font-bold text-white shadow-lg transition-transform duration-200 hover:scale-105"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              شروع رایگان
              <ArrowRight size={16} className="rotate-180 transition-transform group-hover:-translate-x-1" />
            </Link>
            <Link
              to="/"
              className="rounded-2xl border px-6 py-3.5 text-sm font-semibold transition-colors hover:bg-(--surface-sunken)"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
            >
              بازگشت به صفحه اصلی
            </Link>
          </div>
        </motion.div>
      </main>

      <HomeFooter />
    </div>
  )
}
