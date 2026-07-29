import { motion, useReducedMotion } from 'framer-motion'

/**
 * شخصیتِ راهنما که با هر دو دست دکمه‌ی «شروع رایگان» را بالای سر نگه داشته.
 *
 * کاملاً SVG است (بدون فایل، تیز در هر اندازه). عمداً از حالتِ «کارتونیِ
 * کله‌گنده» فاصله گرفته و به یک آدمِ متناسب‌ترِ حرفه‌ای نزدیک شده: سرِ کوچک‌تر
 * نسبت به تنه، گردن و شانه، کتِ سرمه‌ای با یقه و کراوات، و از همه مهم‌تر
 * دست‌هایی با انگشتانِ مشخص که روی لبه‌ی دکمه چنگ زده‌اند.
 *
 * پس‌زمینه‌ی این بخش سبزِ برند است؛ برای همین بدن سرمه‌ای و پوست گرم انتخاب
 * شده تا شخصیت در آن گم نشود و برجسته بماند.
 *
 * حرکت‌ها ظریف‌اند (تابِ سبکِ نگه‌داشتن، پلک‌زدن، فشارِ کمِ دست‌ها) تا زنده
 * باشد نه شلوغ. کاربرِ «کاهش حرکت» نسخه‌ی ثابت می‌بیند.
 */
export function CtaMascot() {
  const reduce = useReducedMotion()

  return (
    <motion.svg
      viewBox="0 0 200 210"
      className="h-auto w-[10.5rem] shrink-0 sm:w-[12.5rem]"
      role="img"
      aria-label="شخصیت راهنما که دکمه‌ی شروع را بالای سر نگه داشته"
      initial={reduce ? false : { opacity: 0, y: 20 }}
      whileInView={reduce ? undefined : { opacity: 1, y: 0 }}
      viewport={{ once: true, amount: 0.4 }}
      transition={{ type: 'spring', stiffness: 130, damping: 15 }}
    >
      <defs>
        <linearGradient id="cta-suit" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="#3d4d70" />
          <stop offset="100%" stopColor="#26334d" />
        </linearGradient>
        <linearGradient id="cta-skin" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="#f5cda4" />
          <stop offset="100%" stopColor="#e2a778" />
        </linearGradient>
      </defs>

      {/* تابِ سبکِ کلِ بدن، انگار وزنِ دکمه را روی دست تنظیم می‌کند */}
      <motion.g
        animate={reduce ? {} : { rotate: [-1.4, 1.4, -1.4] }}
        transition={{ duration: 3.6, repeat: Infinity, ease: 'easeInOut' }}
        style={{ transformOrigin: '100px 200px' }}
      >
        {/* سایه‌ی زیر پا */}
        <motion.ellipse
          cx="100"
          cy="201"
          rx="44"
          ry="7"
          fill="rgba(0,0,0,0.14)"
          animate={reduce ? {} : { rx: [44, 38, 44], opacity: [0.14, 0.1, 0.14] }}
          transition={{ duration: 3.6, repeat: Infinity, ease: 'easeInOut' }}
        />

        {/* پاها و کفش‌ها */}
        <rect x="85" y="150" width="12.5" height="46" rx="6" fill="url(#cta-suit)" />
        <rect x="102.5" y="150" width="12.5" height="46" rx="6" fill="url(#cta-suit)" />
        <ellipse cx="89" cy="197" rx="12.5" ry="5.5" fill="#161d2b" />
        <ellipse cx="111" cy="197" rx="12.5" ry="5.5" fill="#161d2b" />

        {/* تنه — کتِ سرمه‌ای */}
        <path
          d="M62 121 Q64 107 83 103 L117 103 Q136 107 138 121 L132 159 Q100 168 68 159 Z"
          fill="url(#cta-suit)"
        />
        {/* پیراهنِ سفید و کراوات */}
        <path d="M92 104 L100 132 L108 104 Z" fill="#f4f6fb" />
        <path d="M97 105 L100 110 L103 105 L102 128 L100 132 L98 128 Z" fill="var(--color-brand-500)" />
        {/* یقه‌ی کت */}
        <path d="M92 104 L100 113 L88 118 Z" fill="#31405e" />
        <path d="M108 104 L100 113 L112 118 Z" fill="#31405e" />

        {/* گردن */}
        <rect x="93.5" y="92" width="13" height="15" rx="5" fill="url(#cta-skin)" />
        <path d="M93.5 96 Q100 101 106.5 96 L106.5 100 Q100 104 93.5 100 Z" fill="rgba(0,0,0,0.08)" />

        {/* سر */}
        <g>
          <ellipse cx="76" cy="74" rx="4" ry="5.5" fill="url(#cta-skin)" />
          <ellipse cx="124" cy="74" rx="4" ry="5.5" fill="url(#cta-skin)" />
          <circle cx="100" cy="72" r="25" fill="url(#cta-skin)" />

          {/* مو */}
          <path
            d="M75 70 Q72 42 100 42 Q128 42 125 70 Q120 55 108 53 Q112 60 104 60 Q98 54 90 57 Q83 55 79 62 Q77 66 75 70 Z"
            fill="#2c2018"
          />

          {/* ابروها */}
          <path d="M85 66 Q90 63 95 66" stroke="#2c2018" strokeWidth="2.2" strokeLinecap="round" fill="none" />
          <path d="M105 66 Q110 63 115 66" stroke="#2c2018" strokeWidth="2.2" strokeLinecap="round" fill="none" />

          {/* چشم‌ها با پلک‌زدن */}
          <motion.g
            animate={reduce ? {} : { scaleY: [1, 1, 0.1, 1, 1] }}
            transition={{ duration: 4.8, repeat: Infinity, times: [0, 0.92, 0.95, 0.98, 1] }}
            style={{ transformOrigin: '100px 73px' }}
          >
            <circle cx="90" cy="73" r="3.4" fill="#2b2b40" />
            <circle cx="110" cy="73" r="3.4" fill="#2b2b40" />
            <circle cx="91.1" cy="71.9" r="1.1" fill="#fff" />
            <circle cx="111.1" cy="71.9" r="1.1" fill="#fff" />
          </motion.g>

          {/* بینی و لبخند */}
          <path d="M99 78 Q97 82 100 83" stroke="rgba(0,0,0,0.18)" strokeWidth="1.6" strokeLinecap="round" fill="none" />
          <path d="M90 86 Q100 94 110 86" stroke="#7a3b2e" strokeWidth="2.8" strokeLinecap="round" fill="none" />
        </g>

        {/*
          بازوها و دست‌ها. بازوها آستینِ کت‌اند (خط ضخیم) و بالای سر می‌روند؛
          سرِ آستین مچِ سفیدِ پیراهن دارد و بعد دستِ پوستی با انگشتانِ مشخص که
          روی لبه‌ی پایینِ دکمه چنگ زده. تابِ کمِ عمودی «فشارِ نگه‌داشتن» را
          نشان می‌دهد.
        */}
        <motion.g
          animate={reduce ? {} : { y: [0, -1.6, 0] }}
          transition={{ duration: 1.9, repeat: Infinity, ease: 'easeInOut' }}
        >
          {/* آستین‌ها */}
          <path d="M74 120 Q58 82 77 36" stroke="url(#cta-suit)" strokeWidth="15" strokeLinecap="round" fill="none" />
          <path d="M126 120 Q142 82 123 36" stroke="url(#cta-suit)" strokeWidth="15" strokeLinecap="round" fill="none" />
          {/* مچِ سفیدِ پیراهن */}
          <rect x="69.5" y="30" width="16" height="7" rx="3.5" fill="#f4f6fb" transform="rotate(-6 77 33)" />
          <rect x="114.5" y="30" width="16" height="7" rx="3.5" fill="#f4f6fb" transform="rotate(6 123 33)" />

          <Hand tx={78} />
          <Hand tx={122} flip />
        </motion.g>
      </motion.g>
    </motion.svg>
  )
}

/**
 * یک دست: کفِ دست + چهار انگشت + شست، با پوستِ گرم.
 *
 * در حالتِ پایه شست سمتِ راست (داخل، رو به مرکز) است؛ دستِ سمتِ دیگر با
 * `flip` آینه می‌شود تا شستش هم رو به مرکز بایستد و هر دو دکمه را از دو طرف
 * بگیرند.
 */
function Hand({ tx, flip = false }: { tx: number; flip?: boolean }) {
  return (
    <g transform={`translate(${tx} 22)${flip ? ' scale(-1 1)' : ''}`}>
      {/* انگشت‌ها؛ دو تای میانی بلندترند */}
      {[-7.5, -2.5, 2.5, 7.5].map((fx, i) => {
        const len = i === 1 || i === 2 ? 16 : 13
        return <rect key={fx} x={fx - 2.1} y={-len} width="4.2" height={len + 7} rx="2.1" fill="url(#cta-skin)" />
      })}
      {/* کفِ دست */}
      <rect x="-10.5" y="0" width="21" height="15" rx="6.5" fill="url(#cta-skin)" />
      {/* شست، سمتِ داخل */}
      <path d="M10 3 q9 -1 8.5 7 q-0.5 5.5 -8.5 3.5 z" fill="url(#cta-skin)" />
      {/* خطِ بندِ انگشتان، برای حسِ خم‌شدن روی لبه */}
      <path d="M-9 -1 Q0 -4 9 -1" stroke="rgba(0,0,0,0.1)" strokeWidth="1.8" strokeLinecap="round" fill="none" />
    </g>
  )
}
