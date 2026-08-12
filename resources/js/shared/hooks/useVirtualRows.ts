import { useCallback, useEffect, useRef, useState } from 'react'

interface Options {
  /** تعداد کلِ ردیف‌ها. */
  count: number
  /** ارتفاعِ ثابتِ هر ردیف به پیکسل. */
  rowHeight: number
  /**
   * چند ردیف بیرون از قابِ دید هم رندر شود.
   *
   * بدونِ حاشیه، اسکرولِ سریع لحظه‌ای فضای خالی نشان می‌دهد چون رندرِ
   * ردیفِ تازه پس از رویدادِ اسکرول انجام می‌شود.
   */
  overscan?: number
}

/**
 * پنجره‌ی مجازیِ ردیف‌ها (R30).
 *
 * ─── چرا بدونِ کتابخانه ────────────────────────────────────────────────────
 * `react-window` کارِ درستی می‌کند، ولی قیدِ پروژه این است که پکیجِ غیرلازم
 * اضافه نشود — و آنچه ما لازم داریم زیرمجموعه‌ی کوچکی از آن است: ردیف‌های
 * **هم‌ارتفاع** در یک ظرفِ عمودی. کلِ منطقش همین چند خط است: از موقعیتِ
 * اسکرول، اولین و آخرین ردیفِ قابلِ دید حساب می‌شود و بقیه رندر نمی‌شوند.
 *
 * چیزی که کتابخانه دارد و اینجا نیست: ارتفاعِ متغیر، اسکرولِ افقی، و
 * شبکه‌ی دوبعدی. هیچ‌کدام در این پروژه لازم نشده‌اند؛ اگر روزی شدند، همان
 * لحظه‌ی درستِ آوردنِ کتابخانه است.
 *
 * ⚠️ ردیف‌ها باید **واقعاً** هم‌ارتفاع باشند. با ارتفاعِ متغیر، محاسبه
 * می‌لغزد و ردیف‌ها روی هم می‌افتند — و این خطا نمی‌دهد، فقط بد دیده
 * می‌شود.
 */
export function useVirtualRows({ count, rowHeight, overscan = 6 }: Options) {
  const containerRef = useRef<HTMLDivElement>(null)
  const [scrollTop, setScrollTop] = useState(0)
  const [viewport, setViewport] = useState(0)

  const measure = useCallback(() => {
    const element = containerRef.current
    if (!element) return

    setScrollTop(element.scrollTop)
    setViewport(element.clientHeight)
  }, [])

  useEffect(() => {
    const element = containerRef.current
    if (!element) return

    measure()

    element.addEventListener('scroll', measure, { passive: true })

    /*
     * تغییرِ اندازه‌ی ظرف هم باید پنجره را دوباره حساب کند — مثلاً وقتی
     * سایدبار جمع می‌شود یا کاربر پنجره را عوض می‌کند. بدونِ این، پس از
     * بزرگ‌شدنِ ظرف نیمه‌ی پایینش خالی می‌ماند تا اولین اسکرول.
     */
    const observer = new ResizeObserver(measure)
    observer.observe(element)

    return () => {
      element.removeEventListener('scroll', measure)
      observer.disconnect()
    }
  }, [measure])

  const visible = Math.ceil((viewport || 0) / rowHeight)
  const start = Math.max(0, Math.floor(scrollTop / rowHeight) - overscan)
  const end = Math.min(count, start + visible + overscan * 2)

  return {
    containerRef,
    /** بازه‌ای که باید رندر شود. */
    start,
    end,
    /** ارتفاعِ کلِ فهرست، تا نوارِ اسکرول اندازه‌ی واقعی را نشان بدهد. */
    totalHeight: count * rowHeight,
    /** فاصله‌ی بالای اولین ردیفِ رندرشده. */
    offsetTop: start * rowHeight,
    /** تا وقتی ظرف اندازه‌گیری نشده، همه‌چیز رندر می‌شود (SSR و تست). */
    isMeasured: viewport > 0,
  }
}
