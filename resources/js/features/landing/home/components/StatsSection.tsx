import { useEffect, useRef, useState } from 'react'
import { motion, useInView } from 'framer-motion'
import { stats } from '@/features/landing/content/landingContent'

function AnimatedNumber({ value }: { value: string }) {
  const ref = useRef<HTMLSpanElement>(null)
  const isInView = useInView(ref, { once: true, margin: '-40px' })
  const [display, setDisplay] = useState('۰')

  // اگر مقدار عددی واقعی داشته باشد (بدون علامت‌های متنی) شمارش انیمیت می‌شود
  const numericPart = value.match(/[\d۰-۹]+/)?.[0]
  const prefix = numericPart ? value.slice(0, value.indexOf(numericPart)) : ''
  const suffix = numericPart ? value.slice(value.indexOf(numericPart) + numericPart.length) : ''
  const target = numericPart
    ? Number(numericPart.replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))))
    : null

  useEffect(() => {
    if (!isInView || target === null) {
      if (target === null) setDisplay(value)
      return
    }

    const duration = 1200
    const startTime = performance.now()
    const targetValue = target

    /*
     * ⚠️ شناسه‌ی هر فریم نگه داشته می‌شود تا در پاک‌سازی لغو شود.
     *
     * پیش از این حلقه **خودتکرار و بدونِ لغو** بود و دو مشکل داشت:
     * ① با unmount شدنِ کامپوننت وسطِ انیمیشن، حلقه ادامه می‌داد و روی
     *    کامپوننتِ رفته `setState` می‌زد.
     * ② وابستگی‌ها `[isInView, …]` است، پس هر بار که کاربر از این بخش
     *    بیرون و دوباره داخلِ دید می‌آمد، حلقه‌ی **تازه‌ای** شروع می‌شد در
     *    حالی که قبلی هنوز می‌دوید — یعنی چند حلقه هم‌زمان سرِ یک مقدار
     *    دعوا می‌کردند و عدد می‌لرزید.
     */
    let frame = 0

    function tick(now: number) {
      const progress = Math.min((now - startTime) / duration, 1)
      const current = Math.round(progress * targetValue)
      setDisplay(current.toLocaleString('fa-IR'))
      if (progress < 1) frame = requestAnimationFrame(tick)
    }

    frame = requestAnimationFrame(tick)

    return () => cancelAnimationFrame(frame)
  }, [isInView, target, value])

  return (
    <span ref={ref}>
      {prefix}
      {display}
      {suffix}
    </span>
  )
}

export function StatsSection() {
  return (
    <section className="border-y py-12" style={{ borderColor: 'var(--border-subtle)' }}>
      <div
        className="mx-auto grid max-w-6xl grid-cols-2 gap-8 px-4 sm:px-6 md:grid-cols-4"
        dir="rtl"
      >
        {stats.map((stat, index) => (
          <motion.div
            key={stat.label}
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.5, delay: index * 0.08 }}
            className="text-center"
          >
            <p
              className="text-2xl font-extrabold sm:text-3xl"
              style={{ color: 'var(--color-brand-600)' }}
            >
              <AnimatedNumber value={stat.value} />
            </p>
            <p className="mt-1.5 text-xs sm:text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
              {stat.label}
            </p>
          </motion.div>
        ))}
      </div>
    </section>
  )
}
