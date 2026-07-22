import { motion } from 'framer-motion'
import type { SupportTopic, SupportTopicId } from './supportContent'

const SIZE = 260
const CENTER = SIZE / 2
const OUTER = 118
const INNER = 62

/**
 * تبدیل زاویه‌ی قطبی به مختصات SVG. زاویه از بالا (۱۲) شروع و ساعتگرد است.
 */
function point(angleDeg: number, radius: number) {
  const rad = ((angleDeg - 90) * Math.PI) / 180
  return { x: CENTER + radius * Math.cos(rad), y: CENTER + radius * Math.sin(rad) }
}

/** مسیر یک ربع‌دایره‌ی توخالی (حلقه‌ای) بین دو زاویه. */
function quarterPath(startDeg: number, endDeg: number): string {
  const o1 = point(startDeg, OUTER)
  const o2 = point(endDeg, OUTER)
  const i2 = point(endDeg, INNER)
  const i1 = point(startDeg, INNER)

  return [
    `M ${o1.x} ${o1.y}`,
    `A ${OUTER} ${OUTER} 0 0 1 ${o2.x} ${o2.y}`,
    `L ${i2.x} ${i2.y}`,
    `A ${INNER} ${INNER} 0 0 0 ${i1.x} ${i1.y}`,
    'Z',
  ].join(' ')
}

/**
 * چرخِ چهارقسمتی هدر صفحه‌ی پشتیبانی.
 *
 * هر بخش یک ربعِ دایره است؛ با کلیک، آکاردیونِ همان موضوع باز و به آن اسکرول
 * می‌شود. ربع‌ها با تاخیرِ پلکانی «کشیده» می‌شوند و با هاور کمی از مرکز فاصله
 * می‌گیرند تا انتخاب‌شدنی به نظر برسند.
 */
export function SupportWheel({
  topics,
  activeId,
  onSelect,
}: {
  topics: SupportTopic[]
  activeId: SupportTopicId | null
  onSelect: (id: SupportTopicId) => void
}) {
  // فاصله‌ی کوچک بین ربع‌ها تا مرزشان دیده شود
  const gap = 3

  return (
    <div className="relative mx-auto" style={{ width: SIZE, height: SIZE }}>
      {/* هاله‌ی نرم پشت چرخ */}
      <motion.div
        animate={{ scale: [1, 1.08, 1], opacity: [0.35, 0.55, 0.35] }}
        transition={{ duration: 6, repeat: Infinity, ease: 'easeInOut' }}
        className="absolute inset-6 rounded-full blur-2xl"
        style={{ backgroundColor: 'var(--color-brand-300)' }}
      />

      <motion.svg
        width={SIZE}
        height={SIZE}
        viewBox={`0 0 ${SIZE} ${SIZE}`}
        className="relative"
        initial={{ rotate: -18, opacity: 0 }}
        animate={{ rotate: 0, opacity: 1 }}
        transition={{ duration: 0.9, ease: [0.22, 1, 0.36, 1] }}
      >
        {topics.map((topic, index) => {
          const start = index * 90 + gap
          const end = (index + 1) * 90 - gap
          const isActive = activeId === topic.id
          // بردار بیرون‌رفتن از مرکز، برای جابه‌جایی هنگام هاور/فعال بودن
          const mid = index * 90 + 45
          const offset = point(mid, 7)

          return (
            <motion.g
              key={topic.id}
              initial={{ opacity: 0, scale: 0.7 }}
              animate={{
                opacity: 1,
                scale: 1,
                x: isActive ? offset.x - CENTER : 0,
                y: isActive ? offset.y - CENTER : 0,
              }}
              transition={{
                duration: 0.55,
                delay: 0.15 + index * 0.12,
                ease: [0.22, 1, 0.36, 1],
              }}
              whileHover={{ scale: 1.05 }}
              onClick={() => onSelect(topic.id)}
              style={{ cursor: 'pointer', transformOrigin: `${CENTER}px ${CENTER}px` }}
            >
              <title>{topic.title}</title>
              <path
                d={quarterPath(start, end)}
                fill={topic.color}
                opacity={isActive ? 1 : 0.82}
                stroke="var(--surface-canvas)"
                strokeWidth={2}
              />
            </motion.g>
          )
        })}

        {/* دایره‌ی مرکزی */}
        <motion.circle
          cx={CENTER}
          cy={CENTER}
          r={INNER - 10}
          fill="var(--surface-base)"
          stroke="var(--border-subtle)"
          strokeWidth={1.5}
          initial={{ scale: 0 }}
          animate={{ scale: 1 }}
          transition={{ duration: 0.5, delay: 0.1, ease: [0.34, 1.56, 0.64, 1] }}
          style={{ transformOrigin: `${CENTER}px ${CENTER}px` }}
        />

        {/* حلقه‌ی چرخانِ نقطه‌چین دور مرکز */}
        <motion.circle
          cx={CENTER}
          cy={CENTER}
          r={INNER - 2}
          fill="none"
          stroke="var(--color-brand-400)"
          strokeWidth={1.2}
          strokeDasharray="4 7"
          opacity={0.55}
          animate={{ rotate: 360 }}
          transition={{ duration: 26, repeat: Infinity, ease: 'linear' }}
          style={{ transformOrigin: `${CENTER}px ${CENTER}px` }}
        />
      </motion.svg>

      {/* آیکون بخش فعال، وسط چرخ */}
      <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
        {topics.map((topic) => {
          const Icon = topic.icon
          const isActive = activeId === topic.id
          return (
            <motion.span
              key={topic.id}
              initial={false}
              animate={{
                opacity: isActive ? 1 : 0,
                scale: isActive ? 1 : 0.6,
              }}
              transition={{ duration: 0.25 }}
              className="absolute flex flex-col items-center gap-1"
              style={{ color: topic.color }}
            >
              <Icon size={26} />
              <span className="text-[11px] font-extrabold" style={{ color: 'var(--text-primary)' }}>
                {topic.title}
              </span>
            </motion.span>
          )
        })}

        {/* وقتی هیچ بخشی انتخاب نشده */}
        <motion.span
          initial={false}
          animate={{ opacity: activeId === null ? 1 : 0, scale: activeId === null ? 1 : 0.6 }}
          transition={{ duration: 0.25 }}
          className="absolute text-center text-[11.5px] font-bold leading-6"
          style={{ color: 'var(--text-tertiary)' }}
        >
          یک بخش را
          <br />
          انتخاب کنید
        </motion.span>
      </div>
    </div>
  )
}
