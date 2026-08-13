import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'

import { LazyVisible } from '@/shared/ui/LazyVisible'

/**
 * دروازه‌ی بارگذاریِ دیدنی (R36).
 *
 * ─── چرا این تست لازم شد ───────────────────────────────────────────────────
 * ⚠️ تلاش کردم این را در مرورگرِ واقعی بسنجم و نشد: پنلِ مرورگر در این
 * نشست پنهان است، `document.hidden === true` می‌شود و مرورگر برای صفحه‌ای
 * که رندر نمی‌شود اصلاً رویدادِ `IntersectionObserver` نمی‌فرستد. جای‌گیرها
 * در DOM بودند (یعنی دروازه وصل بود) ولی محرک هیچ‌وقت شلیک نکرد.
 *
 * پس مکانیزم اینجا با ناظرِ **کنترل‌شده** اثبات می‌شود، نه با اسکرولِ واقعی.
 */

/** ناظرِ قابلِ‌کنترل: می‌گذارد تصمیم بگیریم کِی «دیده شد». */
function installObserver() {
  const instances: Array<{ trigger: () => void; disconnect: () => void }> = []

  class Controlled {
    constructor(private callback: IntersectionObserverCallback) {
      instances.push({
        /*
         * آرگومانِ دومِ callback خودِ ناظر است و `LazyVisible` فقط
         * `disconnect()`ش را صدا می‌زند، پس شکلِ کاملِ `IntersectionObserver`
         * لازم نیست — همین بدل کافی است.
         */
        trigger: () =>
          this.callback(
            [{ isIntersecting: true } as IntersectionObserverEntry],
            this as unknown as IntersectionObserver,
          ),
        disconnect: () => undefined,
      })
    }

    observe() {}
    unobserve() {}
    disconnect() {}
    takeRecords() {
      return []
    }

    root = null
    rootMargin = ''
    thresholds: number[] = []
  }

  vi.stubGlobal('IntersectionObserver', Controlled)

  return instances
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

function Heavy() {
  return <p>محتوای سنگین</p>
}

describe('LazyVisible', () => {
  it('پیش از دیده‌شدن، ماژول را اصلاً نمی‌خواهد', () => {
    installObserver()
    const load = vi.fn().mockResolvedValue({ default: Heavy })

    render(<LazyVisible load={load} height={200} />)

    /*
     * ⚠️ این مهم‌ترین ادعای کلِ مرحله است: اگر `load` همان اول صدا زده شود،
     * چانکِ سنگین در بارگذاریِ اول می‌آید و کلِ کار بی‌اثر بوده.
     */
    expect(load).not.toHaveBeenCalled()
    expect(screen.queryByText('محتوای سنگین')).toBeNull()
  })

  it('جای‌گیر ارتفاعِ خواسته‌شده را رزرو می‌کند', () => {
    installObserver()
    const { container } = render(<LazyVisible load={vi.fn()} height={288} />)

    const placeholder = container.querySelector('[aria-hidden]')

    // بدونِ رزروِ فضا، لحظه‌ی جایگزینی کلِ صفحه می‌پرد (CLS)
    expect(placeholder).toHaveStyle({ height: '288px' })
  })

  it('با نزدیک‌شدن به دید، ماژول می‌آید و رندر می‌شود', async () => {
    const observers = installObserver()
    const load = vi.fn().mockResolvedValue({ default: Heavy })

    render(<LazyVisible load={load} height={200} />)

    expect(observers).toHaveLength(1)
    observers[0].trigger()

    await waitFor(() => expect(screen.getByText('محتوای سنگین')).toBeInTheDocument())
    expect(load).toHaveBeenCalledTimes(1)
  })

  it('propها به کامپوننتِ تنبل می‌رسند', async () => {
    const observers = installObserver()

    function Greeting({ name }: { name: string }) {
      return <p>سلام {name}</p>
    }

    render(
      <LazyVisible
        load={() => Promise.resolve({ default: Greeting })}
        height={100}
        name="مهندس رضایی"
      />,
    )

    observers[0].trigger()

    await waitFor(() => expect(screen.getByText('سلام مهندس رضایی')).toBeInTheDocument())
  })

  /**
   * ⚠️ خرابیِ یک بهینه‌سازی نباید خودِ قابلیت را ببرد.
   *
   * در محیطی که `IntersectionObserver` ندارد، محتوا باید **بیاید**، نه اینکه
   * برای همیشه اسکلت بماند. بدونِ این شاخه، کاربرِ مرورگرِ قدیمی هرگز
   * چارت‌های داشبورد و گالریِ صفحه‌ی فرود را نمی‌دید.
   */
  it('بدونِ IntersectionObserver محتوا بی‌درنگ می‌آید', async () => {
    vi.stubGlobal('IntersectionObserver', undefined)

    render(<LazyVisible load={() => Promise.resolve({ default: Heavy })} height={200} />)

    await waitFor(() => expect(screen.getByText('محتوای سنگین')).toBeInTheDocument())
  })

  it('جای‌گیرِ اختصاصی جای اسکلتِ پیش‌فرض را می‌گیرد', () => {
    installObserver()

    render(
      <LazyVisible
        load={vi.fn()}
        height={200}
        placeholder={<div data-testid="custom">در حال آماده‌سازی</div>}
      />,
    )

    expect(screen.getByTestId('custom')).toBeInTheDocument()
  })
})
