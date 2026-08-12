import { render, screen } from '@testing-library/react'
import { beforeAll, describe, expect, it } from 'vitest'

import { useVirtualRows } from '@/shared/hooks'

/**
 * پنجره‌ی مجازیِ ردیف‌ها (R30).
 *
 * ─── چرا این تست لازم است ─────────────────────────────────────────────────
 * این قلاب جای یک کتابخانه را گرفته، پس باید همان تضمین‌ها را بدهد:
 * بازه‌ی درست، حاشیه‌ی اطراف، و ارتفاعِ کلِ درست تا نوارِ اسکرول دروغ
 * نگوید. خطای این محاسبه هیچ استثنایی نمی‌دهد — فقط ردیف‌ها روی هم
 * می‌افتند یا فضای خالی می‌ماند.
 */
const ROW_HEIGHT = 50

function Probe({ count, scrollTop = 0 }: { count: number; scrollTop?: number }) {
  const { containerRef, start, end, totalHeight, offsetTop, isMeasured } = useVirtualRows({
    count,
    rowHeight: ROW_HEIGHT,
    overscan: 2,
  })

  return (
    <div ref={containerRef} data-testid="container" data-scroll={scrollTop}>
      <span data-testid="range">{`${start}-${end}`}</span>
      <span data-testid="total">{totalHeight}</span>
      <span data-testid="offset">{offsetTop}</span>
      <span data-testid="measured">{String(isMeasured)}</span>
    </div>
  )
}

/**
 * jsdom همه‌ی اندازه‌ها را صفر گزارش می‌کند و `ResizeObserver` ندارد.
 * هر دو را می‌سازیم تا قلاب همان مسیرِ مرورگر را برود.
 */
beforeAll(() => {
  globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver

  Object.defineProperty(HTMLElement.prototype, 'clientHeight', {
    configurable: true,
    get(this: HTMLElement) {
      return this.dataset.testid === 'container' ? 300 : 0
    },
  })

  Object.defineProperty(HTMLElement.prototype, 'scrollTop', {
    configurable: true,
    get(this: HTMLElement) {
      return Number(this.dataset.scroll ?? 0)
    },
    set() {},
  })
})

describe('useVirtualRows', () => {
  it('ارتفاع کل را از تعداد ردیف‌ها می‌سازد تا نوار اسکرول درست باشد', () => {
    render(<Probe count={200} />)

    expect(screen.getByTestId('total').textContent).toBe(String(200 * ROW_HEIGHT))
  })

  it('در بالای فهرست فقط قابِ دید به‌علاوه‌ی حاشیه را رندر می‌کند', () => {
    render(<Probe count={200} />)

    // قاب ۳۰۰px = ۶ ردیف، به‌علاوه‌ی ۲×۲ حاشیه
    expect(screen.getByTestId('range').textContent).toBe('0-10')
    expect(screen.getByTestId('offset').textContent).toBe('0')
  })

  it('با اسکرول، بازه و فاصله‌ی بالا با هم جلو می‌روند', () => {
    render(<Probe count={200} scrollTop={1000} />)

    // ردیفِ ۲۰ بالای قاب است؛ با ۲ ردیف حاشیه از ۱۸ شروع می‌شود
    expect(screen.getByTestId('range').textContent).toBe('18-28')
    // فاصله باید دقیقاً به اندازه‌ی ردیف‌های رندرنشده باشد، وگرنه فهرست می‌پرد
    expect(screen.getByTestId('offset').textContent).toBe(String(18 * ROW_HEIGHT))
  })

  it('از انتهای فهرست فراتر نمی‌رود', () => {
    render(<Probe count={12} scrollTop={1000} />)

    const [, end] = screen.getByTestId('range').textContent.split('-')

    expect(Number(end)).toBeLessThanOrEqual(12)
  })

  it('فهرست خالی بازه‌ی خالی می‌دهد و نمی‌شکند', () => {
    render(<Probe count={0} />)

    expect(screen.getByTestId('range').textContent).toBe('0-0')
    expect(screen.getByTestId('total').textContent).toBe('0')
  })

  it('تا وقتی ظرف اندازه‌گیری نشده، اندازه‌گیری‌نشده گزارش می‌دهد', () => {
    // ظرفی که `data-testid` ندارد ارتفاعش صفر می‌ماند
    function Unmeasured() {
      const { containerRef, isMeasured } = useVirtualRows({ count: 50, rowHeight: ROW_HEIGHT })

      return (
        <div ref={containerRef}>
          <span data-testid="measured">{String(isMeasured)}</span>
        </div>
      )
    }

    render(<Unmeasured />)

    /*
     * مهم است: مصرف‌کننده در این حالت **همه‌ی** ردیف‌ها را رندر می‌کند،
     * نه هیچ‌کدام. اگر برعکس بود، صفحه در اولین رندر خالی دیده می‌شد.
     */
    expect(screen.getByTestId('measured').textContent).toBe('false')
  })
})
