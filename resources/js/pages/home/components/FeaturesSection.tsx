import { motion } from 'framer-motion'
import { features } from '@/data/landingContent'
import { RevealOnScroll } from './RevealOnScroll'

export function FeaturesSection() {
  return (
    <section id="features" className="mx-auto max-w-6xl px-4 py-20 sm:px-6" dir="rtl">
      <RevealOnScroll className="mx-auto max-w-xl text-center">
        <h2 className="text-2xl font-extrabold sm:text-3xl" style={{ color: 'var(--text-primary)' }}>
          همه‌چیز که برای مدیریت مجتمع لازم دارید
        </h2>
        <p className="mt-3 text-[14.5px] leading-7" style={{ color: 'var(--text-secondary)' }}>
          از مالی و امنیت تا ارتباط با ساکنین، یک پنل یکپارچه برای تمام نیازهای روزمره‌ی مدیریت ساختمان
        </p>
      </RevealOnScroll>

      <div className="mt-14 grid gap-6 sm:grid-cols-2">
        {features.map((feature, index) => {
          const Icon = feature.icon
          return (
            <RevealOnScroll key={feature.title} delay={index * 0.08} direction={index % 2 === 0 ? 'right' : 'left'}>
              <motion.div
                whileHover="hover"
                initial="rest"
                animate="rest"
                transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
                variants={{
                  rest: { y: 0, boxShadow: '0 1px 2px color-mix(in srgb, var(--text-primary) 4%, transparent)' },
                  hover: {
                    y: -8,
                    boxShadow: '0 24px 40px -12px color-mix(in srgb, var(--color-brand-600) 25%, transparent)',
                  },
                }}
                className="group relative overflow-hidden rounded-3xl border"
                style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
              >
                <div className="relative h-44 overflow-hidden">
                  <motion.img
                    src={feature.image}
                    alt={feature.title}
                    variants={{ rest: { scale: 1 }, hover: { scale: 1.08 } }}
                    transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
                    className="h-full w-full object-cover"
                  />
                  <div
                    className="absolute inset-0"
                    style={{
                      background: 'linear-gradient(180deg, transparent 40%, color-mix(in srgb, var(--surface-base) 85%, transparent) 100%)',
                    }}
                  />
                  <motion.div
                    variants={{ rest: { scale: 1, rotate: 0 }, hover: { scale: 1.08, rotate: -4 } }}
                    transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
                    className="absolute bottom-3 right-3 flex h-11 w-11 items-center justify-center rounded-2xl text-white shadow-lg"
                    style={{ backgroundColor: 'var(--color-brand-500)' }}
                  >
                    <Icon size={20} />
                  </motion.div>
                </div>

                <div className="p-5">
                  <h3 className="text-[15px] font-bold" style={{ color: 'var(--text-primary)' }}>
                    {feature.title}
                  </h3>
                  <p className="mt-2 text-[13.5px] leading-6" style={{ color: 'var(--text-secondary)' }}>
                    {feature.description}
                  </p>
                </div>
              </motion.div>
            </RevealOnScroll>
          )
        })}
      </div>
    </section>
  )
}
