import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CurrentUser } from '@/shared/types'

/**
 * نوارِ بالا و پنلِ جست‌وجو — مرحلهٔ ۱۳ · فاز ۴ (ایرادات ۱۱، بندهای ۲.۱ تا ۲.۱۱؛ ایرادات ۱۰، بندهای ۵۲ تا ۶۳).
 *
 * چهار داشبورد، یک ساختار: آیکون‌های لازم هستند، ممنوع‌ها نیستند، کلیک کارِ واقعی می‌کند، و جست‌وجوی عرضِ کم پنلِ زیرِ
 * سربرگ است (نه Modal) با ورودی، «جستجو» و چند جست‌وجوی آخر؛ نتیجه در محتوای اصلی. مرزِ داده‌ی جست‌وجو در سرور است
 * (`TopBarSearchTest`، `ManagerSearchTest`)؛ واکنش‌گرایی و لایه در مرورگرِ واقعی (`tests/e2e/top-bar.spec.ts`).
 */

const apiMock = vi.hoisted(() => vi.fn())
vi.mock('@/shared/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/api')>('@/shared/lib/api')
  return { ...actual, api: apiMock }
})

type Modules = {
  Header: (typeof import('@/shared/layout/Header'))['Header']
  RegistrantLayout: (typeof import('@/features/registrant/RegistrantLayout'))['RegistrantLayout']
  topBar: typeof import('@/shared/config/topBar')
  useAuthStore: (typeof import('@/shared/stores/authStore'))['useAuthStore']
  useSearchStore: (typeof import('@/shared/stores/searchStore'))['useSearchStore']
}
let m: Modules

// ایمپورتِ سرد یک بار و با بودجه‌ی خودش (همان درسِ ۱۰/۱۵)
beforeAll(async () => {
  const [{ Header }, { RegistrantLayout }, topBar, { useAuthStore }, { useSearchStore }] =
    await Promise.all([
      import('@/shared/layout/Header'),
      import('@/features/registrant/RegistrantLayout'),
      import('@/shared/config/topBar'),
      import('@/shared/stores/authStore'),
      import('@/shared/stores/searchStore'),
    ])
  m = { Header, RegistrantLayout, topBar, useAuthStore, useSearchStore }
}, 30_000)

function user(role: CurrentUser['role'], dashboard: CurrentUser['dashboard']): CurrentUser {
  return {
    id: 3,
    name: 'کاربرِ نوار',
    firstName: 'کاربرِ',
    lastName: 'نوار',
    needsName: false,
    phone: '09120000003',
    role,
    roleLabel: role,
    isAdmin: role === 'complex_admin' || role === 'super_admin',
    isSuperAdmin: role === 'super_admin',
    complex:
      dashboard === 'initial'
        ? null
        : ({ id: 1, name: 'مجتمع آفتاب', type: 'residential' } as CurrentUser['complex']),
    accountState:
      dashboard === 'initial' ? 'initial' : dashboard === 'platform' ? 'platform' : 'member',
    dashboard,
    canWrite: dashboard !== 'initial',
    pendingInvitations: 0,
    memberships: [],
  }
}

let where = ''
function Where() {
  const location = useLocation()
  where = location.pathname + location.search
  return null
}

function show(ui: React.ReactNode, who: CurrentUser, path = '/dashboard') {
  m.useAuthStore.setState({ user: who, isLoading: false })
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="*" element={ui} />
        </Routes>
        <Where />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  window.localStorage.clear()
  apiMock.mockReset().mockResolvedValue({ data: [], unread: 0, items: [] })
  m.useSearchStore.getState().clearRecent()
  m.useSearchStore.setState({ query: '', results: null })
})

/** نام‌های دسترس‌پذیرِ ابزارهای نوار — آن‌چه صفحه‌خوان و کاربرِ کیبورد می‌بینند */
const TOOL = {
  search: /^جستجو$/,
  searchBox: 'جستجو در واحدها، ساکنین و قبوض',
  recent: /^جستجوهای اخیر/,
  calculator: 'ماشین حساب',
  notifications: /اعلان/,
  theme: /حالت (تاریک|روشن)/,
}

describe('ماتریسِ آیکون‌ها (پیکربندی)', () => {
  it('اولیه: آدمک، اعلان، تم — بی جست‌وجو و ماشین‌حساب؛ سه داشبوردِ عضو همه‌ی ابزارها', () => {
    const { TOP_BAR } = m.topBar

    expect(TOP_BAR.initial).toEqual(['notifications', 'theme', 'account'])
    for (const dashboard of ['unit', 'manager', 'platform'] as const) {
      expect(TOP_BAR[dashboard], dashboard).toEqual([
        'search',
        'recentSearches',
        'calculator',
        'notifications',
        'theme',
        'account',
      ])
    }
  })

  it('تقویم و دستیار تا ساخته‌شدنشان آیکون ندارند (بی آیکونِ بی‌کارکرد)', () => {
    const all = Object.values(m.topBar.TOP_BAR).flat() as string[]

    expect(all).not.toContain('calendar')
    expect(all).not.toContain('assistant')
    expect(Object.keys(m.topBar.PLANNED_TOP_BAR)).toEqual(['calendar', 'assistant'])
  })

  it('داشبوردِ نوار از پاسخِ سرور، نه از حدس', () => {
    const of = m.topBar.topBarDashboardOf

    expect(of(user('owner', 'initial'))).toBe('initial')
    expect(of(user('owner', 'unit'))).toBe('unit')
    expect(of(user('complex_admin', 'manager'))).toBe('manager')
    expect(of(user('super_admin', 'platform'))).toBe('platform')
    // نقشِ ذخیره‌شده‌ی مدیر در زمینه‌ای که سرور «واحد» می‌گوید، ابزارِ مدیر نمی‌سازد
    expect(of({ ...user('complex_admin', 'unit') })).toBe('unit')
  })
})

describe('هر چهار داشبورد در رابط', () => {
  for (const [role, dashboard] of [
    ['owner', 'unit'],
    ['complex_admin', 'manager'],
    ['super_admin', 'platform'],
  ] as const) {
    it(`${dashboard}: جست‌وجو، اخیرها، ماشین‌حساب، اعلان، تم و آدمک`, () => {
      show(<m.Header />, user(role, dashboard))
      const bar = screen.getByRole('banner')

      expect(within(bar).getByRole('button', { name: TOOL.search })).toBeInTheDocument()
      expect(within(bar).getByRole('searchbox', { name: TOOL.searchBox })).toBeInTheDocument()
      expect(within(bar).getByRole('link', { name: TOOL.recent })).toBeInTheDocument()
      expect(within(bar).getByRole('link', { name: TOOL.calculator })).toBeInTheDocument()
      expect(within(bar).getByRole('button', { name: TOOL.notifications })).toBeInTheDocument()
      expect(within(bar).getByRole('button', { name: TOOL.theme })).toBeInTheDocument()
      expect(within(bar).getByRole('button', { name: /کاربرِ نوار/ })).toBeInTheDocument()
    })
  }

  it('اولیه: آدمک، اعلان، تم — و هیچ جست‌وجو، اخیرها یا ماشین‌حسابی', () => {
    show(<m.RegistrantLayout />, user('owner', 'initial'))
    const bar = screen.getByRole('banner')

    expect(within(bar).getByRole('button', { name: TOOL.notifications })).toBeInTheDocument()
    expect(within(bar).getByRole('button', { name: TOOL.theme })).toBeInTheDocument()
    expect(within(bar).getByRole('button', { name: /کاربرِ نوار/ })).toBeInTheDocument()
    expect(within(bar).queryByRole('button', { name: TOOL.search })).toBeNull()
    expect(within(bar).queryByRole('searchbox')).toBeNull()
    expect(within(bar).queryByRole('link', { name: TOOL.recent })).toBeNull()
    expect(within(bar).queryByRole('link', { name: TOOL.calculator })).toBeNull()
  })

  it('کلیک روی ماشین‌حساب کارِ واقعی است: همان صفحه در محتوای اصلی', () => {
    show(<m.Header />, user('owner', 'unit'))

    fireEvent.click(screen.getByRole('link', { name: TOOL.calculator }))
    expect(where).toBe('/calculator')
  })

  it('آیکونِ جست‌وجو tooltip ِ «جستجو» دارد و وضعیتش را می‌گوید', async () => {
    show(<m.Header />, user('owner', 'unit'))
    const toggle = screen.getByRole('button', { name: TOOL.search })

    fireEvent.mouseEnter(toggle.parentElement!.parentElement!)
    expect((await screen.findByRole('tooltip')).textContent).toBe('جستجو')
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(toggle.getAttribute('aria-controls')).toBe('top-bar-search-panel')
  })
})

describe('پنلِ جست‌وجوی عرضِ کم', () => {
  async function openPanel() {
    fireEvent.click(screen.getByRole('button', { name: TOOL.search }))
    return screen.findByRole('region', { name: 'جستجو' })
  }

  it('زیرِ سربرگ باز می‌شود — درونِ همان نوار، نه Modal — با ورودیِ فوکوس‌دار، «جستجو» و ×', async () => {
    show(<m.Header />, user('owner', 'unit'))
    const panel = await openPanel()

    expect(screen.getByRole('banner').contains(panel)).toBe(true)
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(panel.className).toMatch(/(^|\s)top-full(\s|$)/)
    const input = within(panel).getByRole('searchbox')
    await waitFor(() => expect(document.activeElement).toBe(input))
    expect(within(panel).getByRole('button', { name: 'جستجو' })).toBeDisabled()
    expect(within(panel).getByRole('button', { name: 'بستن جستجو' })).toBeInTheDocument()
    // آیکون و دکمه‌ی «جستجو» ِ پنل هم‌نام‌اند؛ آیکون همان است که پنل را کنترل می‌کند
    const toggle = document.querySelector('[aria-controls="top-bar-search-panel"]')
    expect(toggle?.getAttribute('aria-expanded')).toBe('true')
  })

  it('«جستجو» نتیجه را در محتوای اصلی باز می‌کند، به تاریخچه می‌افزاید و پنل را می‌بندد', async () => {
    show(<m.Header />, user('owner', 'unit'))
    const panel = await openPanel()

    fireEvent.change(within(panel).getByRole('searchbox'), { target: { value: 'واحد ۱۲' } })
    fireEvent.click(within(panel).getByRole('button', { name: 'جستجو' }))

    expect(where).toBe(`/search?q=${encodeURIComponent('واحد ۱۲')}`)
    expect(m.useSearchStore.getState().recent.map((r) => r.query)).toEqual(['واحد ۱۲'])
    await waitFor(() => expect(screen.queryByRole('region', { name: 'جستجو' })).toBeNull())
  })

  it('چند جست‌وجوی آخر (پنج تا) قابلِ انتخاب‌اند، و پیوندِ مدیریتِ همه', async () => {
    for (const q of ['الف۱', 'الف۲', 'الف۳', 'الف۴', 'الف۵', 'الف۶']) {
      m.useSearchStore.getState().rememberSearch(q, 1)
    }
    show(<m.Header />, user('complex_admin', 'manager'))
    const panel = await openPanel()

    const recent = within(panel).getByRole('list', { name: 'آخرین جستجوها' })
    expect(within(recent).getAllByRole('button')).toHaveLength(5)
    expect(within(recent).queryByText('الف۱')).toBeNull()
    expect(
      within(panel)
        .getByRole('link', { name: /مدیریتِ جستجوهای اخیر/ })
        .getAttribute('href'),
    ).toBe('/search')

    fireEvent.click(within(recent).getByText('الف۶'))
    expect(where).toBe(`/search?q=${encodeURIComponent('الف۶')}`)
  })

  it('بستن با ×، با دوباره‌کلیکِ آیکون، با کلیکِ بیرون و با Escape', async () => {
    show(<m.Header />, user('owner', 'unit'))
    const toggle = screen.getByRole('button', { name: TOOL.search })
    const gone = () =>
      waitFor(() => expect(screen.queryByRole('region', { name: 'جستجو' })).toBeNull())

    // ×: و فوکوس به آیکون برمی‌گردد
    fireEvent.click(within(await openPanel()).getByRole('button', { name: 'بستن جستجو' }))
    await gone()
    expect(document.activeElement).toBe(toggle)

    // دوباره‌کلیکِ آیکون
    await openPanel()
    fireEvent.click(toggle)
    await gone()

    // کلیکِ بیرون — ولی کلیک درونِ خودِ پنل نمی‌بندد
    const panel = await openPanel()
    fireEvent.pointerDown(within(panel).getByRole('searchbox'))
    expect(screen.getByRole('region', { name: 'جستجو' })).toBeInTheDocument()
    act(() => {
      document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    })
    await gone()

    // Escape
    await openPanel()
    fireEvent.keyDown(document, { key: 'Escape' })
    await gone()
    expect(document.activeElement).toBe(toggle)
  })
})
