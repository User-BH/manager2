import { act, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ConnectionBanner } from '@/shared/pwa/ConnectionBanner'
import { markOffline, markOnline, resetConnectionState } from '@/shared/pwa/connection'

/**
 * نشانگرِ وضعیتِ اتصال (R62).
 *
 * ⚠️ بندِ ۱۳۵ صریح بود: «هیچ نشانگرِ وضعیتی وجود ندارد». این کلاس می‌سنجد
 * که حالا هست — و مهم‌تر، که **دروغ نمی‌گوید**.
 */

describe('نشانگرِ اتصال', () => {
  beforeEach(() => {
    resetConnectionState()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  /**
   * ⚠️ در حالتِ عادی هیچ‌چیز نشان داده نمی‌شود.
   *
   * نشانگرِ همیشه‌روشن، نوفه است: کاربر یاد می‌گیرد نگاهش نکند و روزی که
   * واقعاً مهم است هم نمی‌بیندش.
   */
  it('وقتی همه‌چیز عادی است چیزی نشان نمی‌دهد', () => {
    const { container } = render(<ConnectionBanner />)

    expect(container).toBeEmptyDOMElement()
  })

  it('قطعی را با نقشِ status اعلام می‌کند', () => {
    render(<ConnectionBanner />)

    act(() => markOffline())

    const banner = screen.getByRole('status')

    expect(banner).toHaveTextContent('اتصال اینترنت برقرار نیست')
    expect(banner).toHaveAttribute('aria-live', 'polite')
  })

  /**
   * 🔴 نوشتنِ ازدست‌رفته باید **جدا** گفته شود.
   *
   * ⚠️ «آفلاینی» به کاربر نمی‌گوید که کاری که خواسته انجام نشده. بندِ
   * ۱۳۶ می‌گوید به کاربر دروغ نگو که ذخیره شد؛ سکوت هم نوعی از همان
   * دروغ است.
   */
  it('نوشتنِ ازدست‌رفته را جدا اعلام می‌کند', () => {
    render(<ConnectionBanner />)

    act(() => markOffline(true))

    expect(screen.getByRole('status')).toHaveTextContent('آخرین تغییر ذخیره نشد')
  })

  it('پس از برگشتنِ اتصال خبر می‌دهد و بعد خودش می‌رود', () => {
    render(<ConnectionBanner />)

    act(() => markOffline())
    act(() => markOnline())

    expect(screen.getByRole('status')).toHaveTextContent('اتصال برقرار شد')

    // خبرِ خوب کوتاه است
    act(() => {
      // ⚠️ بدنه‌ی بلوکی و نه بازگشتِ مستقیم: `advanceTimersByTime` مقدار
      // برمی‌گرداند و آن‌وقت `act` یک thenable می‌دهد که رها می‌ماند.
      vi.advanceTimersByTime(4100)
    })

    expect(screen.queryByRole('status')).toBeNull()
  })

  /**
   * ⚠️ هرگز حالتی به نامِ «در حال همگام‌سازی» ساخته نمی‌شود.
   *
   * فهرستِ ممیزی آن را می‌خواست، ولی این سامانه صفِ آفلاین ندارد
   * (`docs/OFFLINE.md`). نشانه‌ای که چیزی را ادعا کند که وجود ندارد،
   * بدتر از نبودنِ نشانه است.
   */
  it('ادعای همگام‌سازی نمی‌کند، چون صفی وجود ندارد', () => {
    render(<ConnectionBanner />)

    act(() => markOffline(true))

    const text = screen.getByRole('status').textContent ?? ''

    expect(text).not.toContain('همگام')
    expect(text).not.toContain('ارسال شد')
    expect(text).not.toContain('ذخیره شد')
  })
})
