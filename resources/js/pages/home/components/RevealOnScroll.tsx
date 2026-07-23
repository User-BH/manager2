import { motion } from 'framer-motion'
import type { ReactNode } from 'react'
import { useMediaQuery } from '@/hooks'

interface RevealOnScrollProps {
  children: ReactNode
  delay?: number
  direction?: 'up' | 'down' | 'left' | 'right' | 'none'
  className?: string
}

const directionOffset = {
  up: { y: 40, x: 0 },
  down: { y: -40, x: 0 },
  left: { y: 0, x: 40 },
  right: { y: 0, x: -40 },
  none: { y: 0, x: 0 },
}

/** ظاهر شدن نرم و تدریجی محتوا هنگام رسیدن به ویوپورت در اسکرول */
export function RevealOnScroll({
  children,
  delay = 0,
  direction = 'up',
  className,
}: RevealOnScrollProps) {
  /*
   * روی صفحه‌ی باریک هیچ جابه‌جایی افقی انجام نمی‌شود.
   *
   * دلیل: انیمیشن فقط وقتی اجرا می‌شود که بخش وارد ویوپورت شود؛ تا آن لحظه
   * عنصر با `x: ±40` سرِ جای جابه‌جاشده می‌ماند. روی موبایل، کارتی که تقریباً
   * تمام‌عرض است با ۴۰ پیکسل جابه‌جایی از لبه بیرون می‌زند و کل صفحه اسکرول
   * افقی و قابلیت zoom out پیدا می‌کند. حرکت عمودی این مشکل را ندارد، چون
   * صفحه در همان راستا اسکرول می‌شود.
   */
  const isNarrow = useMediaQuery('(max-width: 767px)')
  const resolved = isNarrow && (direction === 'left' || direction === 'right') ? 'up' : direction

  const offset = directionOffset[resolved]

  return (
    <motion.div
      initial={{ opacity: 0, ...offset }}
      whileInView={{ opacity: 1, x: 0, y: 0 }}
      viewport={{ once: true, margin: '-80px' }}
      transition={{ duration: 0.6, delay, ease: [0.22, 1, 0.36, 1] }}
      className={className}
    >
      {children}
    </motion.div>
  )
}
