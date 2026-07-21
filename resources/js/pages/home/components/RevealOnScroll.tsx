import { motion } from 'framer-motion'
import type { ReactNode } from 'react'

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
  const offset = directionOffset[direction]

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
