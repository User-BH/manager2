import { Suspense, lazy, useEffect, useRef, useState } from 'react'
import type { ComponentType, ReactNode } from 'react'

/**
 * بارگذاریِ ماژول در لحظه‌ی **نزدیک‌شدن به دید** (R36).
 *
 * ─── چرا `React.lazy` به‌تنهایی کافی نبود ─────────────────────────────────
 * `lazy` بارگذاری را به لحظه‌ی **رندر** موکول می‌کند. ولی چیزهایی که اینجا
 * هدف‌اند — دو چارتِ داشبورد و دو اسلایدرِ صفحه‌ی فرود — همگی در همان رندرِ
 * اول ساخته می‌شوند. یعنی `lazy` تنها، فقط یک درخواستِ شبکه‌ی اضافه می‌ساخت
 * بی‌آنکه چیزی را عقب بیندازد. شرطِ درست **دیده‌شدن** است، نه رندر.
 *
 * ─── چه چیزی با این جابه‌جا شد ────────────────────────────────────────────
 * • Recharts از چانکِ داشبورد (‎۴۰۶KB خام / ‎۱۱۶KB فشرده) بیرون رفت.
 * • Swiper از بارگذاریِ اولِ صفحه‌ی فرود (‎۲۷٫۵KB فشرده) بیرون رفت — همان
 *   صفحه‌ای که سرعتش برای SEO مهم است.
 */

/** فاصله‌ای که زودتر از ورود به دید، بارگذاری را شروع می‌کند. */
const PRELOAD_MARGIN = '400px'

/**
 * آیا این عنصر به دید نزدیک شده؟
 *
 * ⚠️ در محیطی که `IntersectionObserver` ندارد (jsdom، مرورگرِ قدیمی) از
 * همان اول `true` می‌دهد. خرابیِ یک بهینه‌سازی نباید خودِ قابلیت را ببرد —
 * بدونِ این، محتوا برای همیشه اسکلت می‌ماند.
 */
export function useNearViewport<T extends HTMLElement>() {
  const ref = useRef<T>(null)

  /*
   * نبودِ `IntersectionObserver` **همان ابتدا** تصمیم گرفته می‌شود، نه داخلِ
   * effect. اول داخلِ effect بود و `setNear(true)`ِ هم‌زمان یک رندرِ اضافه
   * می‌ساخت — چیزی که ESLint هم درست به آن گیر داد. نتیجه یکی است، ولی
   * این‌طور محتوا از همان رندرِ اول می‌آید نه رندرِ دوم.
   */
  const [near, setNear] = useState(() => typeof IntersectionObserver === 'undefined')

  useEffect(() => {
    const node = ref.current

    if (!node || typeof IntersectionObserver === 'undefined') return

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setNear(true)
          observer.disconnect()
        }
      },
      { rootMargin: PRELOAD_MARGIN },
    )

    observer.observe(node)

    return () => observer.disconnect()
  }, [])

  return { ref, near }
}

/**
 * جای‌گیرِ ساده با ارتفاعِ رزروشده.
 *
 * ⚠️ ارتفاع اجباری است و باید با ارتفاعِ محتوای واقعی یکی باشد. جای‌گیرِ
 * کوتاه‌تر یعنی لحظه‌ی جایگزینی کلِ صفحه می‌پرد (CLS) — بهینه‌سازیِ حجم را
 * با یک مشکلِ بصری عوض کرده‌ایم.
 */
export function SizedPlaceholder({
  height,
  heightClass,
}: {
  height: number
  heightClass?: string
}) {
  return (
    <div
      style={heightClass ? undefined : { height }}
      className={`w-full animate-pulse rounded-xl ${heightClass ?? ''}`}
      aria-hidden
    >
      <div
        className="h-full w-full rounded-xl"
        style={{ backgroundColor: 'var(--surface-sunken)' }}
      />
    </div>
  )
}

export function LazyVisible<P extends object>({
  load,
  height,
  placeholder,
  heightClass,
  id,
  ...props
}: {
  load: () => Promise<{ default: ComponentType<P> }>
  /** ارتفاعِ رزروشده تا صفحه هنگامِ آمدنِ محتوا نپرد. */
  height: number
  /** جای‌گیرِ اختصاصی؛ اگر ندهید یک اسکلت با همان ارتفاع می‌آید. */
  placeholder?: ReactNode
  /**
   * ارتفاعِ ریسپانسیو با کلاس (مثلاً `h-[200px] sm:h-[248px]`) — مرحلهٔ ۷ · فاز ۵.
   *
   * 🔴 یک عددِ ثابت برای همه‌ی عرض‌ها درست نیست: سه بخشِ صفحه‌ی فرود با
   * سنجش در Chromium و WebKit ‎۲۴ تا ‎۱۸۰ پیکسل با جای‌گیرشان فرق داشتند. هم
   * صفحه هنگامِ رسیدنِ محتوا می‌پرید (CLS) و هم پرش از هدر به «تور پنل» در
   * WebKit به‌اندازه‌ی همان اختلاف از عنوان دور می‌ایستاد.
   */
  heightClass?: string
  /**
   * لنگرِ بخش (مرحلهٔ ۷ · فاز ۵).
   *
   * 🔴 شناسه روی خودِ بخش بود؛ پس تا بخش بار نشده بود، `getElementById`
   * چیزی پیدا نمی‌کرد و «گالری» و «نظرات» در هدر و فوتر **هیچ کاری** نمی‌کردند
   * — مگر کاربر قبلاً خودش تا آنجا اسکرول کرده بود. روی این قاب از رندرِ اول
   * هست، پس پیوند همیشه جایی برای رفتن دارد.
   */
  id?: string
} & P) {
  const { ref, near } = useNearViewport<HTMLDivElement>()
  const fallback = placeholder ?? <SizedPlaceholder height={height} heightClass={heightClass} />

  return (
    <div ref={ref} id={id}>
      {near ? <LazyBody load={load} fallback={fallback} {...(props as P)} /> : fallback}
    </div>
  )
}

/**
 * ⚠️ `lazy()` باید **بیرونِ** رندر ساخته شود.
 *
 * اگر داخلِ بدنه‌ی کامپوننت صدا زده شود، هر رندر یک کامپوننتِ تازه می‌سازد و
 * React درخت را از نو mount می‌کند — یعنی چارت با هر تغییرِ کوچکِ صفحه پرش
 * می‌زند و اسلایدر به اسلایدِ اول برمی‌گردد. `useState` با مقداردهیِ تنبل،
 * آن را یک بار می‌سازد.
 */
function LazyBody<P extends object>({
  load,
  fallback,
  ...props
}: {
  load: () => Promise<{ default: ComponentType<P> }>
  fallback: ReactNode
} & P) {
  const [Component] = useState(() => lazy(load))

  return (
    <Suspense fallback={fallback}>
      <Component {...(props as P)} />
    </Suspense>
  )
}
