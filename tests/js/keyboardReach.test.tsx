import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { useState } from 'react'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * سه جایی که فازِ ۵ از قلم انداخته بود (مرحلهٔ ۵، تکمیلِ فاز ۵ — بندهای ۳۳ تا ۳۵).
 *
 * ─── 🔴 چرا این فایل پس از بسته‌شدنِ فاز ۵ آمد ─────────────────────────────
 * فاز ۵ مدیریتِ Focus را به `Modal`ِ مشترک داد. ولی اندازه‌گیریِ دوباره —
 * با پویشگری که تگ‌های چندخطیِ JSX را واقعاً می‌خواند — نشان داد سه جای
 * دیگر همان نیاز را دارند و از `Modal` استفاده نمی‌کنند:
 *
 *   • کشوی منوی موبایل     → نه dialog، نه Escape، نه نگه‌داشتنِ Focus
 *   • لایت‌باکسِ گالری      → Escape داشت، ولی Focus را نه
 *   • کارتِ اطلاعیه         → «خوانده‌شدن» فقط با ماوس ممکن بود
 *
 * ⚠️ و خودِ آن پویشگر یک بار پوچ بود: الگوی نخستش نمی‌توانست از `=>` درونِ
 * عبارت‌های JSX بگذرد و «صفر مورد» گزارش کرد. نسخهٔ درست ۱٬۳۰۱ تگ خواند و
 * پنج مورد پیدا کرد؛ چهارتا روکش بودند و یکی همین کارت.
 *
 * 🔴 این تست‌ها **کلید می‌زنند** و می‌بینند Focus واقعاً کجا می‌رود — نه اینکه
 * بپرسند «هوک صدا زده شده؟».
 */

const apiMock = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/api')>('@/shared/lib/api')

  return { ...actual, api: apiMock }
})

function tab(shift = false) {
  fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Tab', shiftKey: shift })
}

describe('کشوی منوی موبایل', () => {
  /*
   * ⚠️ بارگذاریِ ماژول‌ها پیش از تست‌ها (مرحلهٔ ۵ · فاز ۹).
   *
   * اولین تستِ این بخش هزینه‌ی importِ `Sidebar` و وابستگی‌هایش را می‌داد —
   * حدودِ دو ثانیه روی ماشینِ آزاد، و زیرِ بارِ قلابِ pre-commit بیش از
   * پنج ثانیه، یعنی timeoutِ پیش‌فرض. تست درست بود و ناپایدار. بارگذاری
   * این‌جا انجام می‌شود، با سقفِ زمانیِ خودش، تا زمانِ هر تست فقط کارِ خودش باشد.
   */
  beforeAll(async () => {
    await import('@/shared/layout/Sidebar')
  }, 30_000)

  beforeEach(async () => {
    const { useSidebarStore } = await import('@/shared/stores/sidebarStore')
    // ⚠️ حالتِ استور بینِ تست‌ها می‌ماند؛ هر تست از کشوی بسته شروع می‌کند
    useSidebarStore.setState({ mobileOpen: false })
  })

  async function renderDrawer() {
    const { Sidebar } = await import('@/shared/layout/Sidebar')
    const { useSidebarStore } = await import('@/shared/stores/sidebarStore')

    const opener = document.createElement('button')
    opener.textContent = 'بازکنندهٔ منو'
    document.body.appendChild(opener)
    opener.focus()

    render(
      <MemoryRouter>
        <Sidebar />
      </MemoryRouter>,
    )

    useSidebarStore.setState({ mobileOpen: true })

    return { opener, store: useSidebarStore }
  }

  /** 🔴 کشو به‌عنوانِ پنجرهٔ گفت‌وگو اعلام می‌شود، وگرنه صفحه‌خوان نمی‌فهمد چیزی باز شد. */
  it('به‌عنوانِ dialog اعلام می‌شود و Focus را داخلِ خودش می‌برد', async () => {
    await renderDrawer()

    const dialog = await screen.findByRole('dialog', { name: 'منوی اصلی' })

    await waitFor(() => expect(dialog.contains(document.activeElement)).toBe(true))
  })

  /** 🔴 Escape کشو را می‌بندد و Focus به همان‌جایی برمی‌گردد که بود. */
  it('Escape می‌بندد و Focus را برمی‌گرداند', async () => {
    const { opener, store } = await renderDrawer()

    await screen.findByRole('dialog', { name: 'منوی اصلی' })

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(store.getState().mobileOpen).toBe(false)
    await waitFor(() => expect(document.activeElement).toBe(opener))

    opener.remove()
  })

  /** ⚠️ Tab از کشو بیرون نمی‌رود و به صفحهٔ پشتِ روکش نمی‌رسد. */
  it('Tab داخلِ کشو می‌چرخد', async () => {
    await renderDrawer()

    const dialog = await screen.findByRole('dialog', { name: 'منوی اصلی' })

    for (let i = 0; i < 40; i++) {
      tab()
      expect(
        dialog.contains(document.activeElement),
        `پس از ${i + 1} Tab، Focus از کشو بیرون رفت.`,
      ).toBe(true)
    }
  })
})

describe('لایت‌باکسِ گالری', () => {
  const items = [
    { src: '/a.webp', title: 'نمای ورودی', description: 'توضیح', tags: ['برچسب'] },
    { src: '/b.webp', title: 'لابی', description: 'توضیح', tags: [] },
  ]

  function Harness() {
    const [index, setIndex] = useState<number | null>(null)

    return (
      <div>
        <button type="button" onClick={() => setIndex(0)}>
          تصویرِ گالری
        </button>
        <GalleryHarnessSlot index={index} setIndex={setIndex} />
      </div>
    )
  }

  let GalleryHarnessSlot: (props: {
    index: number | null
    setIndex: (i: number | null) => void
  }) => React.ReactElement

  beforeEach(async () => {
    const { GalleryLightbox } = await import('@/features/landing/home/components/GalleryLightbox')

    GalleryHarnessSlot = ({ index, setIndex }) => (
      <GalleryLightbox
        items={items}
        index={index}
        onClose={() => setIndex(null)}
        onNavigate={(next) => setIndex(next)}
      />
    )
  })

  it('با بازشدن، dialog است و Focus داخلِ آن می‌رود', async () => {
    render(<Harness />)

    const opener = screen.getByText('تصویرِ گالری')
    opener.focus()
    fireEvent.click(opener)

    const dialog = await screen.findByRole('dialog', { name: 'نمای ورودی' })

    await waitFor(() => expect(dialog.contains(document.activeElement)).toBe(true))
  })

  /** 🔴 پس از بستن، Focus به همان تصویری برمی‌گردد که بازش کرده بود. */
  it('پس از بستن، Focus به تصویرِ بازکننده برمی‌گردد', async () => {
    render(<Harness />)

    const opener = screen.getByText('تصویرِ گالری')
    opener.focus()
    fireEvent.click(opener)

    await screen.findByRole('dialog', { name: 'نمای ورودی' })

    fireEvent.keyDown(window, { key: 'Escape' })

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    await waitFor(() => expect(document.activeElement).toBe(opener))
  })
})

describe('کارتِ اطلاعیه با کیبورد', () => {
  function announcement(id: number, isRead: boolean) {
    return {
      id,
      title: `اطلاعیهٔ ${id}`,
      body: 'متن',
      audience: 'all',
      audienceLabel: 'همه',
      isPinned: false,
      isActive: true,
      isRead,
      publishedAt: null,
    }
  }

  async function renderPage(rows: ReturnType<typeof announcement>[], canManage = false) {
    apiMock.mockReset()
    apiMock.mockImplementation((path: string) => {
      if (path.startsWith('/announcements'))
        return Promise.resolve({
          data: rows,
          meta: { currentPage: 1, lastPage: 1, total: rows.length },
          unreadCount: rows.filter((r) => !r.isRead).length,
          canManage,
          audienceOptions: [],
        })

      return Promise.resolve({ unreadCount: 0, items: [] })
    })

    const { AnnouncementsPage } =
      await import('@/features/messaging/announcements/AnnouncementsPage')
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <AnnouncementsPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )
  }

  /** 🔴 قلبِ این تست: Enter روی کارتِ نخوانده همان کاری را می‌کند که کلیک. */
  it('Enter روی کارتِ نخوانده آن را خوانده علامت می‌زند', async () => {
    await renderPage([announcement(7, false)])

    const card = await screen.findByLabelText('اطلاعیهٔ نخوانده: اطلاعیهٔ 7')

    card.focus()
    expect(document.activeElement, 'کارتِ نخوانده Focus نمی‌گیرد.').toBe(card)

    fireEvent.keyDown(card, { key: 'Enter' })

    await waitFor(() =>
      expect(apiMock.mock.calls.some(([path]) => String(path).includes('/7/read'))).toBe(true),
    )
  })

  /**
   * 🔴 کلیدی که روی دکمهٔ **داخلِ** کارت زده می‌شود، کارت را فعال نمی‌کند.
   *
   * ─── چرا این تست جدا لازم شد ──────────────────────────────────────────
   * ⚠️ دکمهٔ بومی پیش از `click`، رویدادِ `keydown` می‌گیرد — و آن رویداد
   * تا کارت بالا می‌رود. `stopPropagation`ِ درونِ `onClick` دیر است.
   *
   * پس بدونِ نگهبانِ `event.target !== event.currentTarget`، مدیری که روی
   * «ویرایش» Enter می‌زد، اطلاعیه را **خوانده‌شده** هم علامت می‌زد.
   *
   * 🔴 خرابکاری این را لو داد: برداشتنِ نگهبان هیچ تستی را قرمز نمی‌کرد.
   */
  it('Enter روی دکمهٔ داخلِ کارت، کارت را خوانده علامت نمی‌زند', async () => {
    await renderPage([announcement(9, false)], true)

    const edit = await screen.findByLabelText('ویرایش اطلاعیهٔ 9')

    edit.focus()
    fireEvent.keyDown(edit, { key: 'Enter' })
    fireEvent.keyDown(edit, { key: ' ' })

    // ⚠️ یک تیک صبر، تا اگر فراخوانی‌ای قرار بود برود، رفته باشد
    await new Promise((resolve) => setTimeout(resolve, 20))

    expect(apiMock.mock.calls.some(([path]) => String(path).includes('/9/read'))).toBe(false)
  })
  /** ⚠️ کارتِ خوانده‌شده کاری برای انجام ندارد و توقفِ Tab رویش فقط شلوغی است. */
  it('کارتِ خوانده‌شده در مسیرِ Tab نیست', async () => {
    await renderPage([announcement(8, true)])

    const title = await screen.findByText('اطلاعیهٔ 8')
    const card = title.closest('article')

    expect(card?.hasAttribute('tabindex')).toBe(false)
  })
})
