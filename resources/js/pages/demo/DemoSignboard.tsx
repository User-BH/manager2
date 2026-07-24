import { motion, useReducedMotion } from 'framer-motion'
import { type ReactNode } from 'react'

/** تعداد لامپ روی لبه‌های افقی و عمودی. */
const H_BULBS = 11
const V_BULBS = 5

/** رنگ لامپ‌ها؛ به‌ترتیب تکرار می‌شوند تا حسِ ریسه‌ی چراغانی بدهند. */
const BULB_COLORS = ['#ffd76a', '#58c39f', '#ff8fa3', '#7cc7ff']

/**
 * تابلوی چراغانی‌شده‌ی بالای ویدیوی دمو.
 *
 * دور تابلو لامپ‌های ریز می‌چرخند و به‌نوبت روشن می‌شوند — مثل تابلوهای
 * نئونیِ سردرِ سینما. زیرِ تابلو یک شخصیتِ ساختمانی ایستاده که آن را با هر دو
 * دست بالای سر نگه داشته.
 *
 * چیدمان عمودی است (تابلو بالا، شخصیت پایین) تا در هر عرضی پایدار بماند و
 * دست‌ها همیشه به لبه‌ی تابلو برسند.
 */
export function DemoSignboard({ children }: { children: ReactNode }) {
  const reduce = useReducedMotion()

  // موقعیت لامپ‌ها روی محیط تابلو
  const bulbs: { left: string; top: string; i: number }[] = []
  let index = 0
  for (let i = 0; i < H_BULBS; i++) {
    const x = `${(i / (H_BULBS - 1)) * 100}%`
    bulbs.push({ left: x, top: '0%', i: index++ })
    bulbs.push({ left: x, top: '100%', i: index++ })
  }
  for (let i = 1; i < V_BULBS - 1; i++) {
    const y = `${(i / (V_BULBS - 1)) * 100}%`
    bulbs.push({ left: '0%', top: y, i: index++ })
    bulbs.push({ left: '100%', top: y, i: index++ })
  }

  return (
    <div className="flex flex-col items-center">
      {/* --- تابلو --- */}
      <motion.div
        initial={reduce ? false : { opacity: 0, y: -24, rotate: -1.5 }}
        animate={{ opacity: 1, y: 0, rotate: 0 }}
        transition={{ type: 'spring', stiffness: 120, damping: 15 }}
        className="relative w-full max-w-2xl"
      >
        {/* تابِ بسیار سبک، انگار تابلو روی دست تاب می‌خورد */}
        <motion.div
          animate={reduce ? {} : { rotate: [-0.7, 0.7, -0.7] }}
          transition={{ duration: 5, repeat: Infinity, ease: 'easeInOut' }}
          style={{ transformOrigin: '50% 100%' }}
        >
          <div
            className="relative rounded-[1.75rem] border-4 px-7 py-8 text-center shadow-2xl sm:px-12"
            style={{
              borderColor: 'var(--color-brand-500)',
              background:
                'linear-gradient(160deg, color-mix(in srgb, var(--surface-base) 96%, transparent), color-mix(in srgb, var(--color-brand-500) 12%, var(--surface-base)))',
            }}
          >
            {children}
          </div>

          {/* لامپ‌های دور تابلو */}
          {bulbs.map((bulb) => {
            const color = BULB_COLORS[bulb.i % BULB_COLORS.length]
            return (
              <motion.span
                key={`${bulb.left}-${bulb.top}`}
                className="pointer-events-none absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full"
                style={{ left: bulb.left, top: bulb.top, backgroundColor: color }}
                animate={
                  reduce
                    ? { opacity: 0.85 }
                    : {
                        opacity: [0.28, 1, 0.28],
                        boxShadow: [`0 0 0 0 ${color}00`, `0 0 10px 3px ${color}d9`, `0 0 0 0 ${color}00`],
                      }
                }
                transition={{
                  duration: 1.6,
                  repeat: Infinity,
                  // تاخیر پلکانی، تا نور دور تابلو «بدود»
                  delay: (bulb.i % 12) * 0.13,
                  ease: 'easeInOut',
                }}
              />
            )
          })}
        </motion.div>
      </motion.div>

      {/* --- شخصیتِ ساختمانی که تابلو را نگه داشته --- */}
      <BuildingMascot />
    </div>
  )
}

/**
 * ساختمانِ آدم‌نما با دو دستِ بالابرده.
 *
 * کاملاً SVG است: بدون فایل، تیز در هر اندازه، و رنگش از توکن‌های برند
 * می‌آید تا با دارک‌مود هماهنگ بماند.
 */
function BuildingMascot() {
  const reduce = useReducedMotion()

  return (
    <motion.svg
      viewBox="0 0 200 190"
      className="-mt-8 h-auto w-[10rem] shrink-0 sm:w-[13rem]"
      role="img"
      aria-label="ساختمانِ راهنما که تابلو را بالای سر نگه داشته"
      /*
        عمداً `whileInView` نیست: این شکل بالای صفحه است و همیشه در دید،
        و اگر IntersectionObserver کند شود (تبِ پس‌زمینه) با opacity صفر
        گیر می‌کند. ورودِ ساده امن‌تر است.
      */
      initial={reduce ? false : { opacity: 0, y: 18 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ type: 'spring', stiffness: 130, damping: 16, delay: 0.15 }}
    >
      <defs>
        <linearGradient id="demo-tower" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="var(--color-brand-400)" />
          <stop offset="100%" stopColor="var(--color-brand-600)" />
        </linearGradient>
      </defs>

      <motion.g
        animate={reduce ? {} : { rotate: [-1.2, 1.2, -1.2] }}
        transition={{ duration: 5, repeat: Infinity, ease: 'easeInOut' }}
        style={{ transformOrigin: '100px 180px' }}
      >
        {/* سایه */}
        <ellipse cx="100" cy="182" rx="46" ry="7" fill="rgba(0,0,0,0.14)" />

        {/* بدنه‌ی برج */}
        <rect x="62" y="52" width="76" height="126" rx="10" fill="url(#demo-tower)" />

        {/* پنجره‌ها — چند تا چشمک می‌زنند، مثل ساختمانِ شب */}
        {[0, 1, 2].map((row) =>
          [0, 1, 2].map((col) => {
            const lit = (row + col) % 2 === 0
            return (
              <motion.rect
                key={`${row}-${col}`}
                x={73 + col * 20}
                y={118 + row * 19}
                width="13"
                height="13"
                rx="2.5"
                fill={lit ? '#ffe9a8' : 'rgba(255,255,255,0.32)'}
                animate={reduce || !lit ? {} : { opacity: [1, 0.45, 1] }}
                transition={{ duration: 2.4, repeat: Infinity, delay: (row + col) * 0.4 }}
              />
            )
          }),
        )}

        {/* صورت روی طبقه‌ی بالا */}
        <motion.g
          animate={reduce ? {} : { scaleY: [1, 1, 0.1, 1, 1] }}
          transition={{ duration: 4.5, repeat: Infinity, times: [0, 0.93, 0.96, 0.99, 1] }}
          style={{ transformOrigin: '100px 78px' }}
        >
          <circle cx="88" cy="78" r="4.5" fill="#2b2b40" />
          <circle cx="112" cy="78" r="4.5" fill="#2b2b40" />
        </motion.g>
        <path d="M87 92 Q100 103 113 92" stroke="#2b2b40" strokeWidth="3.2" strokeLinecap="round" fill="none" />
        <circle cx="76" cy="90" r="4.5" fill="#ffb3b3" opacity="0.55" />
        <circle cx="124" cy="90" r="4.5" fill="#ffb3b3" opacity="0.55" />

        {/* دو دستِ بالابرده که به لبه‌ی پایینِ تابلو می‌رسند */}
        <motion.g
          animate={reduce ? {} : { y: [0, -1.5, 0] }}
          transition={{ duration: 1.9, repeat: Infinity, ease: 'easeInOut' }}
        >
          <path d="M64 76 Q44 56 58 20" stroke="url(#demo-tower)" strokeWidth="12" strokeLinecap="round" fill="none" />
          <path d="M136 76 Q156 56 142 20" stroke="url(#demo-tower)" strokeWidth="12" strokeLinecap="round" fill="none" />
          <circle cx="57" cy="16" r="9.5" fill="#ffd9b3" />
          <circle cx="143" cy="16" r="9.5" fill="#ffd9b3" />
        </motion.g>
      </motion.g>
    </motion.svg>
  )
}
