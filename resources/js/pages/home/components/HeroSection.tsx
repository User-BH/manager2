import { useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, useScroll, useTransform } from 'framer-motion'
import { ArrowLeft, PlayCircle } from 'lucide-react'
import { heroHighlights } from '@/data/landingContent'
import { heroImages as imageUrls } from '@/data/images'

export function HeroSection() {
  const navigate = useNavigate()
  const sectionRef = useRef<HTMLDivElement>(null)

  const { scrollYProgress } = useScroll({
    target: sectionRef,
    offset: ['start start', 'end start'],
  })

  // افکت پارالاکس: تصویر کمی کندتر از محتوای متنی اسکرول می‌شود
  const imageY = useTransform(scrollYProgress, [0, 1], ['0%', '18%'])
  const contentY = useTransform(scrollYProgress, [0, 1], ['0%', '-8%'])
  const contentOpacity = useTransform(scrollYProgress, [0, 0.8], [1, 0])

  return (
    <section ref={sectionRef} className="relative overflow-hidden pt-28 sm:pt-32">
      {/* پس‌زمینه گرادینت دکوراتیو */}
      <div
        className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[600px]"
        style={{
          background:
            'radial-gradient(60% 50% at 50% 0%, var(--color-brand-100), transparent 70%)',
        }}
      />

      <div className="mx-auto grid max-w-6xl gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:items-center" dir="rtl">
        <motion.div style={{ y: contentY, opacity: contentOpacity }} className="relative z-10">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.6 }}
            className="mb-5 inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-medium"
            style={{ borderColor: 'var(--border-subtle)', color: 'var(--color-brand-600)' }}
          >
            <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: 'var(--color-brand-500)' }} />
            نسل جدید مدیریت مجتمع‌های مسکونی
          </motion.div>

          <motion.h1
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.65, delay: 0.1 }}
            className="text-3xl font-extrabold leading-[1.3] sm:text-4xl lg:text-[2.6rem]"
            style={{ color: 'var(--text-primary)' }}
          >
            مدیریت مجتمع مسکونی،
            <br />
            <span style={{ color: 'var(--color-brand-500)' }}>ساده، شفاف و یکپارچه</span>
          </motion.h1>

          <motion.p
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.65, delay: 0.2 }}
            className="mt-5 max-w-md text-[15px] leading-7"
            style={{ color: 'var(--text-secondary)' }}
          >
            از شارژ و قبوض تا ارتباط با ساکنین؛ همه‌چیز در یک پنل مدیریتی هوشمند، طراحی‌شده برای
            مدیران مجتمع‌های مسکونی امروز.
          </motion.p>

          <motion.div
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.65, delay: 0.3 }}
            className="mt-8 flex flex-wrap items-center gap-3"
          >
            <button
              onClick={() => navigate('/auth?tab=register')}
              className="group flex items-center gap-2 rounded-2xl px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-emerald-900/10 transition-transform duration-200 hover:scale-105"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              شروع رایگان
              <ArrowLeft size={16} className="transition-transform group-hover:-translate-x-1" />
            </button>
            <button
              onClick={() => navigate('/demo')}
              className="flex items-center gap-2 rounded-2xl border px-6 py-3.5 text-sm font-semibold transition-colors hover:bg-(--surface-sunken)"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
            >
              <PlayCircle size={18} />
              مشاهده دمو
            </button>
          </motion.div>

          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.6, delay: 0.45 }}
            className="mt-10 flex flex-wrap gap-6"
          >
            {heroHighlights.map((item) => {
              const Icon = item.icon
              return (
                <div key={item.label} className="flex items-center gap-2">
                  <Icon size={16} style={{ color: 'var(--color-brand-500)' }} />
                  <span className="text-xs font-medium" style={{ color: 'var(--text-tertiary)' }}>
                    {item.label}
                  </span>
                </div>
              )
            })}
          </motion.div>
        </motion.div>

        <motion.div
          style={{ y: imageY }}
          initial={{ opacity: 0, scale: 0.92 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.8, delay: 0.15, ease: [0.22, 1, 0.36, 1] }}
          className="relative"
        >
          <div
            className="absolute -inset-4 -z-10 rounded-[2rem] opacity-60 blur-2xl"
            style={{ backgroundColor: 'var(--color-brand-200)' }}
          />
          <div className="group overflow-hidden rounded-[1.75rem] border shadow-2xl" style={{ borderColor: 'var(--border-subtle)' }}>
            {/* تصویر بالای صفحه: زودتر از همه لازم است، پس نه lazy بلکه
                با اولویت بالا بارگذاری می‌شود. width/height واقعی هم
                گذاشته شده تا چیدمان هنگام لود نپرد. */}
            <img
              src={imageUrls.buildingMain}
              alt="نمای ساختمان مجتمع مسکونی مدرن"
              width={1100}
              height={1466}
              fetchPriority="high"
              decoding="async"
              className="h-[340px] w-full object-cover transition-transform duration-700 group-hover:scale-105 sm:h-[420px]"
            />
          </div>
        </motion.div>
      </div>
    </section>
  )
}
