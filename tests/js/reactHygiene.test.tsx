import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { StatsSection } from '@/features/landing/home/components/StatsSection'
import { PollComposer, emptyPoll } from '@/features/messaging/messenger/PollComposer'

/*
 * ⚠️ `useInView` در jsdom هرگز `true` نمی‌شود.
 *
 * بدلِ `IntersectionObserver` در `tests/js/setup.ts` عمداً چیزی را «در دید»
 * گزارش نمی‌کند، پس افکتِ شمارنده زودهنگام برمی‌گشت و **هیچ حلقه‌ای شروع
 * نمی‌شد** — یعنی تستِ لغوِ فریم چیزی برای لغوکردن نداشت و در اولین اجرا
 * افتاد. اینجا فقط همین یک قلاب بدل گرفته می‌شود تا مسیرِ انیمیشن واقعاً
 * پیموده شود؛ بقیه‌ی framer-motion دست‌نخورده می‌ماند.
 */
vi.mock('framer-motion', async (importOriginal) => ({
  ...(await importOriginal<typeof import('framer-motion')>()),
  useInView: () => true,
}))

/**
 * بهداشتِ React: پاک‌سازی، نشتی، و کلیدِ پایدار (R39).
 *
 * ─── چرا این‌ها تست دارند ───────────────────────────────────────────────────
 * هیچ‌کدام خطا نمی‌دهند. حلقه‌ی انیمیشنی که لغو نشود فقط CPU می‌خورد و
 * صفحه را کند می‌کند، و کلیدِ اندیسی فقط وقتی خودش را نشان می‌دهد که کاربر
 * دقیقاً وسطِ تایپ چیزی را حذف کند.
 */

afterEach(() => {
  vi.restoreAllMocks()
})

describe('پاک‌سازیِ حلقه‌ی انیمیشن', () => {
  /**
   * ⚠️ باگی که R39 پیدا کرد.
   *
   * شمارنده‌ی آمارِ صفحه‌ی فرود یک `requestAnimationFrame`ِ **خودتکرار**
   * داشت که هیچ‌جا لغو نمی‌شد. دو پیامد داشت: با رفتنِ کامپوننت، حلقه ادامه
   * می‌داد و روی کامپوننتِ رفته `setState` می‌زد؛ و چون وابستگیِ افکت
   * `isInView` بود، هر بار که کاربر از این بخش بیرون و دوباره داخلِ دید
   * می‌آمد یک حلقه‌ی تازه شروع می‌شد در حالی که قبلی هنوز می‌دوید.
   */
  it('با رفتنِ کامپوننت، فریمِ در جریان لغو می‌شود', () => {
    const cancel = vi.spyOn(globalThis, 'cancelAnimationFrame')

    const { unmount } = render(<StatsSection />)

    unmount()

    expect(cancel).toHaveBeenCalled()
  })

  /**
   * ⚠️ لغو باید **آخرین** فریم را بگیرد، نه اولی را.
   *
   * ─── چرا این تست بعد از پاسِ خرابکاری اضافه شد ────────────────────────
   * تستِ بالا فقط می‌گفت «`cancelAnimationFrame` صدا زده شد». وقتی عمداً
   * `frame = requestAnimationFrame(tick)` داخلِ خودِ حلقه را به
   * `requestAnimationFrame(tick)` عوض کردم، همچنان سبز ماند — در حالی که
   * آن تغییر یعنی فقط شناسه‌ی **فریمِ اول** نگه داشته می‌شود و پاک‌سازی
   * فریمی را لغو می‌کند که از قبل اجرا شده؛ زنجیره‌ی خودتکرار سرِ جایش
   * می‌ماند و نشتی برمی‌گردد.
   */
  it('پس از چند فریم هم، همان فریمِ زنده لغو می‌شود', () => {
    let nextId = 1
    const pending = new Map<number, FrameRequestCallback>()

    vi.spyOn(globalThis, 'requestAnimationFrame').mockImplementation((callback) => {
      const id = nextId++

      pending.set(id, callback)

      return id
    })

    const cancelled: number[] = []

    vi.spyOn(globalThis, 'cancelAnimationFrame').mockImplementation((id) => {
      cancelled.push(id)
    })

    const { unmount } = render(<StatsSection />)

    // دو تیک را دستی جلو می‌بریم تا زنجیره چند فریم عمیق شود
    for (let step = 0; step < 2; step++) {
      for (const [id, callback] of [...pending]) {
        pending.delete(id)
        callback(performance.now())
      }
    }

    const lastRequested = nextId - 1

    unmount()

    expect(cancelled).toContain(lastRequested)
  })

  /**
   * هر شمارنده باید دقیقاً **یک** حلقه داشته باشد، نه بیشتر.
   *
   * شمارشِ فراخوانی‌های `requestAnimationFrame` در همان رندرِ اول، سقفِ
   * تعدادِ حلقه‌های هم‌زمان را نشان می‌دهد.
   */
  it('برای هر شمارنده بیش از یک حلقه نمی‌سازد', () => {
    const request = vi.spyOn(globalThis, 'requestAnimationFrame')

    const { unmount } = render(<StatsSection />)

    const counters = screen.getAllByText(/[۰-۹]/).length

    /*
     * jsdom فریم را اجرا نمی‌کند، پس هر شمارنده حداکثر یک فراخوانی ثبت
     * می‌کند. اگر روزی کسی حلقه‌ی دومی اضافه کند، این عدد بالا می‌رود.
     */
    expect(request.mock.calls.length).toBeLessThanOrEqual(Math.max(counters, 1) * 2)

    unmount()
  })
})

describe('کلیدِ پایدارِ گزینه‌های نظرسنجی', () => {
  function Composer() {
    const [draft, setDraft] = useState(emptyPoll())

    return <PollComposer draft={draft} onChange={setDraft} onClose={vi.fn()} />
  }

  it('هر گزینه شناسه‌ی یکتای خودش را دارد', () => {
    const poll = emptyPoll()

    const ids = poll.options.map((option) => option.id)

    expect(new Set(ids).size).toBe(ids.length)
  })

  /**
   * ⚠️ دو نظرسنجیِ تازه نباید شناسه‌ی مشترک بگیرند.
   *
   * پیش از این `EMPTY_POLL` یک **ثابتِ اشتراکی** بود؛ با شناسه‌دارشدنِ
   * گزینه‌ها، هر نظرسنجیِ تازه همان دو شناسه‌ی قبلی را می‌گرفت و React
   * گزینه‌های نظرسنجیِ قبلی را با تازه یکی می‌دید. حالا تابع است.
   */
  it('دو پیش‌نویسِ تازه شناسه‌ی مشترک ندارند', () => {
    const first = emptyPoll().options.map((option) => option.id)
    const second = emptyPoll().options.map((option) => option.id)

    expect(first.filter((id) => second.includes(id))).toHaveLength(0)
  })

  /**
   * ⚠️ سنجه‌ی اصلی: حذفِ گزینه‌ی میانی نباید محتوای بقیه را جابه‌جا کند.
   *
   * با کلیدِ اندیسی، حذفِ گزینه‌ی دوم باعث می‌شد React گرهِ DOMِ اندیسِ ۲ را
   * برای چیزی که قبلاً اندیسِ ۳ بود بازاستفاده کند — یعنی فوکوس و مکانِ
   * نشانگرِ کاربر روی ردیفِ اشتباه می‌ماند.
   */
  it('حذفِ گزینه‌ی میانی، مقدارِ گزینه‌های دیگر را جابه‌جا نمی‌کند', async () => {
    render(<Composer />)

    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: /افزودن گزینه/ }))

    const inputs = () => screen.getAllByPlaceholderText(/گزینه /)

    await user.type(inputs()[0], 'الف')
    await user.type(inputs()[1], 'ب')
    await user.type(inputs()[2], 'ج')

    expect(inputs().map((input) => (input as HTMLInputElement).value)).toEqual(['الف', 'ب', 'ج'])

    // حذفِ گزینه‌ی میانی
    await user.click(screen.getAllByRole('button', { name: /حذف گزینه/ })[1])

    expect(inputs().map((input) => (input as HTMLInputElement).value)).toEqual(['الف', 'ج'])
  })

  it('حذف تا حداقلِ دو گزینه ممکن است و بعد دکمه‌اش می‌رود', async () => {
    render(<Composer />)

    // با دو گزینه‌ی پیش‌فرض، اصلاً دکمه‌ی حذفی نباید باشد
    expect(screen.queryAllByRole('button', { name: /حذف گزینه/ })).toHaveLength(0)

    await userEvent.click(screen.getByRole('button', { name: /افزودن گزینه/ }))

    expect(screen.getAllByRole('button', { name: /حذف گزینه/ })).toHaveLength(3)
  })
})
