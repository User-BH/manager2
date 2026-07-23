import { motion, useReducedMotion } from 'framer-motion'

/**
 * شخصیت کارتونی که با هر دو دستِ بالا‌برده دکمه‌ی «شروع رایگان» را نگه داشته.
 *
 * کاملاً SVG است، نه تصویر: هیچ فایلی دانلود نمی‌شود، در هر اندازه تیز می‌ماند
 * و رنگش از توکن‌های برند می‌آید، پس با دارک‌مود و تغییر برند هماهنگ است.
 *
 * ترکیب عمدی است: شخصیت درست زیر دکمه می‌نشیند و دست‌هایش تا لبه‌ی پایینِ
 * دکمه بالا می‌آید، انگار آن را بالای سرش نگه داشته. این چیدمان در هر عرضی
 * پایدار است چون به موقعیت افقیِ دقیق وابسته نیست.
 *
 * حرکت‌ها ظریف‌اند (تابِ سبکِ نگه‌داشتن، پلک‌زدن) تا زنده به‌نظر برسد بی‌آنکه
 * حواس‌پرت‌کن شود. کاربری که «کاهش حرکت» را روشن کرده، نسخه‌ی ثابت می‌بیند.
 */
export function CtaMascot() {
  const reduce = useReducedMotion()

  return (
    <motion.svg
      viewBox="0 0 200 210"
      className="h-auto w-[10rem] shrink-0 sm:w-[12rem]"
      role="img"
      aria-label="شخصیت راهنما که دکمه‌ی شروع را بالای سر نگه داشته"
      initial={reduce ? false : { opacity: 0, y: 20 }}
      whileInView={reduce ? undefined : { opacity: 1, y: 0 }}
      viewport={{ once: true, amount: 0.4 }}
      transition={{ type: 'spring', stiffness: 130, damping: 15 }}
    >
      <defs>
        <linearGradient id="mascot-body" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="var(--color-brand-400)" />
          <stop offset="100%" stopColor="var(--color-brand-600)" />
        </linearGradient>
        <linearGradient id="mascot-skin" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="#ffd9b3" />
          <stop offset="100%" stopColor="#f4b98a" />
        </linearGradient>
      </defs>

      {/*
        کلِ شخصیت با یک تابِ بسیار سبک این‌ور و آن‌ور می‌رود، انگار وزن دکمه را
        روی دست تنظیم می‌کند. محور چرخش پایینِ بدن است تا دست‌ها زیاد جابه‌جا
        نشوند و «چسبیده به دکمه» بمانند.
      */}
      <motion.g
        animate={reduce ? {} : { rotate: [-1.5, 1.5, -1.5] }}
        transition={{ duration: 3.4, repeat: Infinity, ease: 'easeInOut' }}
        style={{ transformOrigin: '100px 200px' }}
      >
        {/* سایه‌ی زیر پا */}
        <motion.ellipse
          cx="100"
          cy="202"
          rx="42"
          ry="7"
          fill="rgba(0,0,0,0.13)"
          animate={reduce ? {} : { rx: [42, 37, 42], opacity: [0.13, 0.09, 0.13] }}
          transition={{ duration: 3.4, repeat: Infinity, ease: 'easeInOut' }}
        />

        {/* پاها */}
        <rect x="84" y="158" width="13" height="38" rx="6.5" fill="url(#mascot-body)" />
        <rect x="103" y="158" width="13" height="38" rx="6.5" fill="url(#mascot-body)" />
        <ellipse cx="90" cy="197" rx="11" ry="6" fill="#1e3a5f" />
        <ellipse cx="110" cy="197" rx="11" ry="6" fill="#1e3a5f" />

        {/* تنه */}
        <path d="M72 118 Q72 100 100 100 Q128 100 128 118 L126 162 Q100 170 74 162 Z" fill="url(#mascot-body)" />

        {/* سر */}
        <g>
          <circle cx="100" cy="74" r="30" fill="url(#mascot-skin)" />
          {/* مو */}
          <path d="M70 72 Q70 40 100 40 Q130 40 130 72 Q118 56 100 58 Q82 56 70 72 Z" fill="var(--color-brand-600)" />

          {/* چشم‌ها با پلک‌زدن */}
          <motion.g
            animate={reduce ? {} : { scaleY: [1, 1, 0.1, 1, 1] }}
            transition={{ duration: 4.5, repeat: Infinity, times: [0, 0.93, 0.96, 0.99, 1] }}
            style={{ transformOrigin: '100px 74px' }}
          >
            <circle cx="89" cy="75" r="4" fill="#2b2b40" />
            <circle cx="111" cy="75" r="4" fill="#2b2b40" />
          </motion.g>

          {/* لبخند رو‌به‌بالا، انگار به دکمه افتخار می‌کند */}
          <path d="M87 86 Q100 98 113 86" stroke="#2b2b40" strokeWidth="3.2" strokeLinecap="round" fill="none" />
          <circle cx="80" cy="85" r="4.5" fill="#ffb3b3" opacity="0.6" />
          <circle cx="120" cy="85" r="4.5" fill="#ffb3b3" opacity="0.6" />
        </g>

        {/*
          دو بازو که تا بالای سر می‌روند و کف دست‌ها کنار هم، جایی که لبه‌ی
          پایینِ دکمه می‌نشیند. دست‌ها با تابِ بسیار کمی «فشارِ نگه‌داشتن» را
          نشان می‌دهند.
        */}
        <motion.g
          animate={reduce ? {} : { y: [0, -1.5, 0] }}
          transition={{ duration: 1.8, repeat: Infinity, ease: 'easeInOut' }}
        >
          {/* بازوی راست */}
          <path d="M124 112 Q140 86 122 30" stroke="url(#mascot-body)" strokeWidth="13" strokeLinecap="round" fill="none" />
          {/* بازوی چپ */}
          <path d="M76 112 Q60 86 78 30" stroke="url(#mascot-body)" strokeWidth="13" strokeLinecap="round" fill="none" />
          {/* کف دست‌ها، درست زیر دکمه */}
          <circle cx="79" cy="26" r="10" fill="url(#mascot-skin)" />
          <circle cx="121" cy="26" r="10" fill="url(#mascot-skin)" />
        </motion.g>
      </motion.g>
    </motion.svg>
  )
}
