import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { CurrentUser } from '@/shared/types'

/**
 * «جست‌وجوهای اخیر» — مرحلهٔ ۱۲ · فاز ۸ (بندهای ۵۰ تا ۵۵ و ۵۷ ِ سندِ هسته).
 *
 *   • از سایدبار برداشته شد؛ کنترلِ مشخص در نوارِ بالا کنارِ جعبه‌ی جست‌وجو؛
 *   • فهرست در ناحیه‌ی محتوا (`/search` بی عبارت)، با حذفِ تکی، «پاک کردن همه» و حالتِ خالی؛
 *   • 🔴 خروج و پایانِ نشست تاریخچه‌ی **همان** حساب را از دستگاه پاک می‌کنند — حسابِ دیگرِ همان مرورگر دست نمی‌خورد.
 *
 * قاعده‌ی مرزِ مجتمع در خودِ جست‌وجو سمتِ سرور است (`ManagerSearchTest`، جاروی چندمجتمعیِ `BolaSweepTest`).
 */

const apiMock = vi.hoisted(() => vi.fn())
const clearCache = vi.hoisted(() => vi.fn(() => Promise.resolve()))

vi.mock('@/shared/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/api')>('@/shared/lib/api')

  return { ...actual, api: apiMock }
})
vi.mock('@/shared/pwa/registerServiceWorker', async () => {
  const actual = await vi.importActual<typeof import('@/shared/pwa/registerServiceWorker')>(
    '@/shared/pwa/registerServiceWorker',
  )

  return { ...actual, clearApiCache: clearCache }
})

const { useAuthStore } = await import('@/shared/stores/authStore')
const { useSearchStore } = await import('@/shared/stores/searchStore')
const { SearchResultsPage } = await import('@/features/tools/search/SearchResultsPage')
const { RecentSearchesButton } = await import('@/shared/layout/RecentSearchesButton')
const { SidebarContent } = await import('@/shared/layout/SidebarContent')

function user(id: number, complexId: number): CurrentUser {
  return {
    id,
    name: 'مدیر',
    firstName: 'مدیر',
    lastName: 'آزمون',
    needsName: false,
    phone: '09120000000',
    role: 'complex_admin',
    roleLabel: 'مدیر',
    isAdmin: true,
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

function at(path: string, ui: React.ReactNode) {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="*" element={ui} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  window.localStorage.clear()
  apiMock.mockReset().mockResolvedValue({})
  useAuthStore.setState({ user: user(1, 10), isLoading: false })
  useSearchStore.getState().clearRecent()
})

describe('جست‌وجوهای اخیر', () => {
  it('کنترلِ نوارِ بالا به همان صفحه‌ی ناحیه‌ی محتوا می‌رود و شمار را می‌گوید', () => {
    useSearchStore.getState().rememberSearch('واحد ۱۲', 2)
    useSearchStore.getState().rememberSearch('علی احمدی', 1)
    at('/dashboard', <RecentSearchesButton />)

    const link = screen.getByRole('link', { name: 'جستجوهای اخیر (۲)' })
    expect(link.getAttribute('href')).toBe('/search')
    // یک پیوند، فقط از `sm`؛ در عرضِ کم پنلِ زیرِ سربرگ جایش است (۱۳/۴، `topBar.test.tsx`)
    expect(screen.getAllByRole('link')).toHaveLength(1)
    expect(link.className).toMatch(/(^|\s)hidden(\s|$)/)
    expect(link.className).toMatch(/(^|\s)sm:flex(\s|$)/)
  })

  it('سایدبار دیگر «جستجوهای اخیر» ندارد', () => {
    useSearchStore.getState().rememberSearch('واحد ۱۲', 2)
    at('/dashboard', <SidebarContent collapsed={false} />)

    expect(screen.queryByText(/جستجوهای اخیر/)).toBeNull()
    expect(screen.queryByText('واحد ۱۲')).toBeNull()
  })

  it('در ناحیه‌ی محتوا: باز کردن، حذفِ تکی، «پاک کردن همه» و حالتِ خالی', () => {
    useSearchStore.getState().rememberSearch('واحد ۱۲', 2)
    useSearchStore.getState().rememberSearch('علی احمدی', 1)
    at('/search', <SearchResultsPage />)

    expect(screen.getByRole('heading', { name: 'جستجوهای اخیر' })).toBeInTheDocument()
    expect(screen.getByText('واحد ۱۲')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'حذف «واحد ۱۲» از تاریخچه' }))
    expect(screen.queryByText('واحد ۱۲')).toBeNull()
    expect(screen.getByText('علی احمدی')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /پاک کردن همه/ }))
    expect(screen.getByText('تاریخچه‌ی جستجو خالی است.')).toBeInTheDocument()
    expect(useSearchStore.getState().recent).toEqual([])
  })

  it('🔴 خروج تاریخچه‌ی همین حساب را در همه‌ی مجتمع‌هایش پاک می‌کند و حسابِ دیگر را نه', async () => {
    window.localStorage.setItem('app:recent-searches:1:10', '[]')
    window.localStorage.setItem('app:recent-searches:1:20', '[]')
    window.localStorage.setItem('app:recent-searches:2:10', '[]')
    useSearchStore.getState().rememberSearch('قبضِ مهر', 4)

    await useAuthStore.getState().logout()

    expect(
      Object.keys(window.localStorage)
        .filter((k) => k.startsWith('app:recent-searches'))
        .sort(),
    ).toEqual(['app:recent-searches:2:10'])
    expect(useSearchStore.getState().recent).toEqual([])
  })

  it('🔴 پایانِ نشست (از دستگاهِ دیگر) هم همان را پاک می‌کند', async () => {
    window.localStorage.setItem(
      'app:recent-searches:1:10',
      JSON.stringify([{ query: 'x', total: 1, at: 1 }]),
    )
    window.localStorage.setItem('app:recent-searches:2:10', '[]')

    await useAuthStore
      .getState()
      .expire('session_ended')
      .catch(() => undefined)

    expect(window.localStorage.getItem('app:recent-searches:1:10')).toBeNull()
    expect(window.localStorage.getItem('app:recent-searches:2:10')).toBe('[]')
  })
})
