import { useState } from 'react'
import { motion } from 'framer-motion'
import type { SupportTopic, SupportTopicId } from './supportContent'

const SIZE = 320
const CENTER = SIZE / 2
const OUTER = 148
const INNER = 74

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
 * عنوان‌های دوکلمه‌ای در دو سطر می‌نشینند تا از پهنای ربع بیرون نزنند.
 * («قوانین و مقررات» سه واژه است؛ واژه‌ی ربطی به سطر اول می‌چسبد.)
 */
function splitTitle(title: string): string[] {
  const words = title.split(' ')
  if (words.length < 2) return [title]
  if (words.length === 2) return words

  return [words.slice(0, 2).join(' '), words.slice(2).join(' ')]
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

  const [hovered, setHovered] = useState<SupportTopicId | null>(null)
  const hoveredTopic = topics.find((topic) => topic.id === hovered) ?? null

  return (
    <div className="relative mx-auto" style={{ width: SIZE, height: SIZE }}>
      {/* هاله‌ی نرم پشت چرخ */}
      <motion.div
        animate={{ scale: [1, 1.08, 1], opacity: [0.35, 0.55, 0.35] }}
        transition={{ duration: 6, repeat: Infinity, ease: 'easeInOut' }}
        className="absolute inset-6 rounded-full blur-2xl"
        style={{ backgroundColor: 'var(--color-brand-300)' }}
      />

      {/*
        تولتیپِ سفارشی (R33).

        جای `<title>`ِ SVG را می‌گیرد و چیزی بیشتر می‌گوید: خودِ عنوان روی
        ربع نوشته شده، پس تکرارش بی‌فایده بود — اینجا **توضیحِ کوتاهِ** همان
        بخش می‌آید.
      */}
      <motion.div
        initial={false}
        animate={{ opacity: hoveredTopic ? 1 : 0, y: hoveredTopic ? 0 : 6 }}
        transition={{ duration: 0.18 }}
        className="pointer-events-none absolute inset-x-0 -bottom-2 z-10 text-center"
        aria-hidden
      >
        <span
          className="inline-block rounded-lg px-3 py-1.5 text-[11.5px] font-medium shadow-lg"
          style={{
            backgroundColor: 'var(--surface-base)',
            color: 'var(--text-secondary)',
            border: '1px solid var(--border-subtle)',
          }}
        >
          {/* متن نگه داشته می‌شود تا هنگامِ محوشدن نپرد */}
          {hoveredTopic?.short ?? '\u00a0'}
        </span>
      </motion.div>

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
          // برچسب روی میانه‌ی ضخامتِ حلقه می‌نشیند
          const label = point(mid, (OUTER + INNER) / 2)
          const lines = splitTitle(topic.title)

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
              /*
               * ⚠️ `transition` بالا برای **ورودِ** ربع‌هاست و پله‌پله تأخیر
               * دارد. framer-motion همان transition را به `whileHover` هم
               * می‌داد، پس هاورِ ربعِ چهارم `0.15 + 3×0.12 ≈ نیم ثانیه`
               * تأخیر می‌گرفت و کاربر فکر می‌کرد صفحه گیر کرده.
               *
               * transitionِ اختصاصی داخلِ خودِ `whileHover` آن را بی‌اثر
               * می‌کند: هاور آنی است، ورود همچنان پله‌پله.
               */
              whileHover={{ scale: 1.05, transition: { duration: 0.18, delay: 0 } }}
              onClick={() => onSelect(topic.id)}
              onHoverStart={() => setHovered(topic.id)}
              onHoverEnd={() => setHovered(null)}
              onFocus={() => setHovered(topic.id)}
              onBlur={() => setHovered(null)}
              tabIndex={0}
              role="button"
              /*
               * `<title>` حذف شد: مرورگر از رویش تولتیپِ بومیِ زردِ خودش را
               * می‌ساخت که با ظاهرِ صفحه نمی‌خواند و با تأخیرِ سیستمی ظاهر
               * می‌شد. `aria-label` جایش را برای صفحه‌خوان می‌گیرد و تولتیپِ
               * سفارشی زیرِ چرخ نشان داده می‌شود.
               */
              aria-label={`${topic.title} — ${topic.short}`}
            >
              <path
                d={quarterPath(start, end)}
                fill={topic.color}
                opacity={isActive ? 1 : 0.82}
                stroke="var(--surface-canvas)"
                strokeWidth={2}
              />

              {/*
                نامِ بخش روی خودِ ربع.
                بدون این، کاربر نمی‌دانست هر ربع به کجا می‌برد و باید حدس
                می‌زد. متن افقی است نه روی قوس، چون فارسیِ چرخیده روی قوس
                خواندنش سخت می‌شود.

                رنگ ربع‌ها از داده می‌آید و بعضی روشن‌اند؛ سفیدِ تنها روی آن‌ها
                گم می‌شود. پس متن یک هاله‌ی تیره‌ی پررنگ دارد تا روی هر رنگی
                خوانا بماند.
              */}
              <text
                x={label.x}
                y={label.y}
                textAnchor="middle"
                dominantBaseline="middle"
                className="pointer-events-none select-none"
                style={{
                  fill: '#fff',
                  fontSize: 12,
                  fontWeight: 800,
                  paintOrder: 'stroke',
                  stroke: 'rgba(0,0,0,0.55)',
                  strokeWidth: 3,
                  strokeLinejoin: 'round',
                }}
              >
                {lines.map((line, i) => (
                  <tspan key={line} x={label.x} dy={i === 0 ? -(lines.length - 1) * 7 : 14}>
                    {line}
                  </tspan>
                ))}
              </text>
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
