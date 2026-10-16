import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { CurrentUser, SearchGroup, SearchResponse } from '@/shared/types'
import type {
  ConversationSummary,
  ConversationsResponse,
} from '@/features/messaging/messenger/types'

/**
 * جست‌وجوی مدیر در رابط — مرحلهٔ ۱۰ · فاز ۸ (بندهای ۲۰، ۴۱، ۸۲ و ۸۳ ِ فایلِ معماری).
 *
 * قاعده‌ی مرز در سرور است (`ManagerSearchTest`، `ConversationSearchTest`). این‌جا سه چیزی که فقط رابط
 * می‌تواند خراب کند: گروهِ بریده‌شده راهی به باقی‌اش دارد، فهرستِ گفت‌وگوها از ۳۰ جلوتر می‌رود و جست‌وجو را
 * به سرور می‌فرستد، و تاریخچه‌ی جست‌وجوی یک حساب و مجتمع به حساب یا مجتمعِ دیگر نمی‌رسد.
 */

const apiMock = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/api')>('@/shared/lib/api')

  return { ...actual, api: apiMock }
})

const { useAuthStore } = await import('@/shared/stores/authStore')
const { useSearchStore } = await import('@/shared/stores/searchStore')
const { SearchResultsPage } = await import('@/features/tools/search/SearchResultsPage')
const { MessengerPage } = await import('@/features/messaging/messenger/MessengerPage')

function user(
  id: number,
  complexId: number,
  role: CurrentUser['role'] = 'complex_admin',
): CurrentUser {
  return {
    id,
    name: 'مدیر',
    firstName: 'مدیر',
    lastName: 'آزمون',
    needsName: false,
    phone: '09120000000',
    role,
    roleLabel: 'مدیر',
    isAdmin: role === 'complex_admin',
    isSuperAdmin: false,
    complex: {
      id: complexId,
      name: `مجتمع ${complexId}`,
      type: 'residential',
    } as CurrentUser['complex'],
    accountState: 'member',
    canWrite: true,
    pendingInvitations: 0,
    memberships: [],
  }
}

function render_(ui: React.ReactNode, path: string, pattern: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path={pattern} element={ui} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function group(id: string, items: number, extra: Partial<SearchGroup> = {}): SearchGroup {
  return {
    id,
    title: 'ساکنین',
    icon: 'Users',
    path: '/residents',
    count: items,
    hasMore: false,
    nextPage: null,
    items: Array.from({ length: items }, (_, i) => ({
      id: (extra.nextPage === null ? 100 : 0) + i + 1,
      title: `پرویز ${i + 1}`,
      subtitle: '',
      badge: null,
      path: '/residents',
    })),
    ...extra,
  }
}

beforeEach(() => {
  /*
   * هر آزمون با خروج و ورودِ تازه آغاز می‌شود؛ همان مسیری که debounce ِ معلقِ آزمونِ قبلی را لغو می‌کند.
   * ⚠️ پیش‌تر `setQuery('')` بود: زمان‌سنجِ تازه‌ای می‌ساخت که ۴۵۰ میلی‌ثانیه بعد `results` را تهی می‌کرد —
   * زیرِ بارِ موازی وسطِ آزمونِ نخست، که نتیجه‌اش را خودش در store گذاشته بود.
   */
  useAuthStore.setState({ user: null })
  apiMock.mockReset()
  window.localStorage.clear()
  useAuthStore.setState({ user: user(1, 10), isLoading: false })
  useSearchStore.setState({
    query: '',
    debouncedQuery: '',
    results: null,
    isSearching: false,
    error: null,
  })
})

describe('جست‌وجوی سراسری: گروهِ بریده‌شده', () => {
  it('نمای کلی «بیش از» می‌گوید و پیوندِ «نمایشِ همه» دارد', async () => {
    useSearchStore.setState({
      query: 'پرویز',
      results: { query: 'پرویز', total: 8, groups: [group('residents', 8, { hasMore: true })] },
    })

    render_(<SearchResultsPage />, '/search?q=پرویز', '/search')

    expect(await screen.findByText(/بیش از ۸ مورد یافت شد/)).toBeInTheDocument()
    // سربرگ از store پیش از فهرست می‌آید؛ پیوند با خودِ فهرست — منتظرِ خودش، نه همان لحظه (زیرِ بار شکست)
    const all = await screen.findByRole('link', { name: /نمایشِ همه‌ی ساکنین/ })
    expect(all.getAttribute('href')).toBe(
      `/search?q=${encodeURIComponent('پرویز')}&group=residents`,
    )
  })

  it('گروهِ باز صفحه به صفحه از سرور می‌خواند تا صفحه‌ی بعدی نماند', async () => {
    apiMock.mockImplementation((url: string): Promise<SearchResponse> => {
      const page = Number(new URL(url, 'http://x').searchParams.get('page'))
      const g =
        page === 1
          ? group('residents', 20, { hasMore: true, nextPage: 2 })
          : group('residents', 5, { nextPage: null })

      return Promise.resolve({
        query: 'پرویز',
        total: g.count,
        groups: [g],
        group: 'residents',
        page,
      })
    })

    render_(<SearchResultsPage />, '/search?q=پرویز&group=residents', '/search')

    expect(await screen.findByText(/صفحه‌ی ۱/)).toBeInTheDocument()
    expect(await screen.findAllByText(/^پرویز /)).toHaveLength(20)
    expect(screen.queryByRole('link', { name: 'صفحه‌ی قبل' })).toBeNull()

    // صفحه در نشانی است: «بازگشت» و بارگذاریِ دوباره همان صفحه را می‌دهند
    const next = screen.getByRole('link', { name: 'صفحه‌ی بعد' })
    expect(next.getAttribute('href')).toBe(
      `/search?q=${encodeURIComponent('پرویز')}&group=residents&page=2`,
    )
    fireEvent.click(next)

    expect(await screen.findByText(/صفحه‌ی ۲/)).toBeInTheDocument()
    await waitFor(() => expect(screen.getAllByText(/^پرویز /)).toHaveLength(5))
    expect(screen.queryByRole('link', { name: 'صفحه‌ی بعد' })).toBeNull()
    expect(screen.getByRole('link', { name: 'صفحه‌ی قبل' })).toBeInTheDocument()

    // عبارتِ نشانی به جعبه‌ی سربرگ هم می‌رود و جست‌وجوی کلیِ آن پس از debounce، پیش یا پس از کلیک، جدا
    // فرستاده می‌شود؛ ترتیبِ صفحه‌ها در درخواست‌های خودِ گروه سنجیده می‌شود، نه با جایگاه در همه‌ی فراخوانی‌ها
    const urls = apiMock.mock.calls
      .map((call) => String(call[0]))
      .filter((u) => new URL(u, 'http://x').searchParams.get('group') === 'residents')
    expect(urls).toHaveLength(2)
    expect(urls[0]).toContain('page=1')
    expect(urls[1]).toContain('page=2')
  })
})

describe('تاریخچه‌ی جست‌وجو', () => {
  it('مالِ همین حساب در همین مجتمع است', () => {
    useSearchStore.getState().rememberSearch('علی احمدی', 3)
    expect(useSearchStore.getState().recent.map((r) => r.query)).toEqual(['علی احمدی'])

    // همان حساب در مجتمعِ دیگر: تاریخچه‌ی الف دیده نمی‌شود
    useAuthStore.setState({ user: user(1, 20) })
    expect(useSearchStore.getState().recent).toEqual([])

    // حسابِ دیگر روی همان مرورگر
    useAuthStore.setState({ user: user(2, 10) })
    expect(useSearchStore.getState().recent).toEqual([])

    // خروج: هیچ
    useAuthStore.setState({ user: null })
    expect(useSearchStore.getState().recent).toEqual([])
    useSearchStore.getState().rememberSearch('بی‌صاحب', 1)
    expect(
      Object.keys(window.localStorage).filter((k) => k.startsWith('app:recent-searches')),
    ).toEqual(['app:recent-searches:1:10'])

    // بازگشت به همان حساب و مجتمع: تاریخچه‌ی خودش
    useAuthStore.setState({ user: user(1, 10) })
    expect(useSearchStore.getState().recent.map((r) => r.query)).toEqual(['علی احمدی'])
  })

  it('نتیجه‌ی جست‌وجوی حسابِ قبلی با خروج پاک می‌شود', () => {
    useSearchStore.setState({ query: 'سارا', results: { query: 'سارا', total: 1, groups: [] } })
    useAuthStore.setState({ user: user(2, 10) })

    expect(useSearchStore.getState().results).toBeNull()
    expect(useSearchStore.getState().query).toBe('')
  })

  it('عبارتِ نوشته‌شده پیش از خروج پس از خروج فرستاده نمی‌شود', () => {
    // debounce ِ معلق مالِ حسابِ قبلی است: نه درخواستی با نشستِ تازه، نه نتیجه‌ای در store ِ حسابِ تازه
    apiMock.mockResolvedValue({ query: 'سارا', total: 1, groups: [] })
    vi.useFakeTimers()
    try {
      useSearchStore.getState().setQuery('سارا')
      useAuthStore.setState({ user: user(2, 10) })
      vi.advanceTimersByTime(1000)
    } finally {
      vi.useRealTimers()
    }

    expect(apiMock).not.toHaveBeenCalled()
    expect(useSearchStore.getState().isSearching).toBe(false)
    expect(useSearchStore.getState().debouncedQuery).toBe('')
  })
})

describe('پیام‌رسان: فهرستِ گفت‌وگوها', () => {
  function conversation(id: number): ConversationSummary {
    return {
      id,
      type: 'unit',
      title: `واحد ${id}`,
      canPost: true,
      readOnlyReason: null,
      membersCanPost: true,
      unreadCount: 0,
      lastMessage: null,
    }
  }

  function page(ids: number[], nextCursor: string | null, isAdmin = true): ConversationsResponse {
    return {
      data: ids.map(conversation),
      hasMore: nextCursor !== null,
      nextCursor,
      canSend: true,
      reason: null,
      isAdmin,
      unreadTotal: 0,
      units: [],
      members: [],
    }
  }

  it('از صفحه‌ی اول جلوتر می‌رود و جست‌وجو را به سرور می‌فرستد', async () => {
    apiMock.mockImplementation((url: string) => {
      if (url.startsWith('/messenger/reports')) return Promise.resolve({ data: [], openCount: 0 })
      const params = new URL(url, 'http://x').searchParams
      if (params.get('q')) return Promise.resolve(page([10], null))
      if (params.get('cursor') === '5:31') return Promise.resolve(page([31, 32], null))

      return Promise.resolve(page([1, 2, 3], '5:31'))
    })

    render_(<MessengerPage />, '/messenger', '/messenger')

    fireEvent.click(await screen.findByRole('button', { name: 'گفت‌وگوهای بیشتر' }))
    expect(await screen.findByText('واحد 32')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'گفت‌وگوهای بیشتر' })).toBeNull()

    fireEvent.change(screen.getByRole('searchbox', { name: 'جست‌وجوی گفت‌وگوها' }), {
      target: { value: 'مالک واحد ۱۰' },
    })

    expect(await screen.findByText('واحد 10', {}, { timeout: 2000 })).toBeInTheDocument()
    expect(screen.queryByText('واحد 32')).toBeNull()
    const searched = apiMock.mock.calls
      .map((call) => String(call[0]))
      .find((u) => u.startsWith('/messenger/conversations?q='))
    expect(new URL(searched!, 'http://x').searchParams.get('q')).toBe('مالک واحد ۱۰')
  })

  it('ساکن کادرِ جست‌وجو نمی‌بیند (بندِ ۴۲)', async () => {
    useAuthStore.setState({ user: user(3, 10, 'tenant') })
    apiMock.mockImplementation(() => Promise.resolve(page([1], null, false)))

    render_(<MessengerPage />, '/messenger', '/messenger')

    expect(await screen.findByText('واحد 1')).toBeInTheDocument()
    expect(screen.queryByRole('searchbox')).toBeNull()
  })
})
