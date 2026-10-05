import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AppRouter } from '@/app/AppRouter'
import { REGISTRANT_NAV } from '@/features/registrant/navigation'
import { useAuthStore } from '@/shared/stores/authStore'
import type { CurrentUser } from '@/shared/types'

/**
 * چهارمین تجربه: «ثبت‌نام‌کننده‌ی اولیه» (مرحلهٔ ۸ · فاز ۱).
 *
 * خواستِ کارفرما چهار داشبوردِ **مستقل** است، نه یک داشبورد که با نقش
 * آیتم‌هایش پنهان شود. پیش از این، کسی که به مجتمعی وصل نبود پوسته و
 * مسیرهای ساکن را می‌گرفت. این تست‌ها می‌سنجند که حالا:
 *   - پوسته‌ی خودش را می‌گیرد، نه سایدبارِ ساکن
 *   - صفحه‌های ساکن، مدیر و ادمینِ کل برایش وجود ندارند
 *   - عضوِ مجتمع دقیقاً همان پوسته‌ی قبلی را می‌گیرد (بی‌رگرسیون)
 *
 * مرزِ واقعی سمتِ سرور است (`InitialAccountTest`)؛ این‌جا تجربه.
 */

vi.mock('@/shared/lib/api', () => ({
  api: vi.fn((path: string) => {
    if (path.startsWith('/invitations')) return Promise.resolve({ data: [] })
    if (path.startsWith('/dashboard')) {
      return Promise.resolve({
        type: 'resident',
        unitCount: 0,
        totalDebt: 0,
        currency: 'تومان',
        units: [],
      })
    }
    return Promise.resolve({ data: [], meta: {}, unread: 0, unreadCount: 0 })
  }),
  ApiError: class extends Error {},
}))

function user(accountState: CurrentUser['accountState']): CurrentUser {
  return {
    id: 7,
    name: 'کاربر تازه‌ثبت‌نام',
    phone: '09120000002',
    role: 'owner',
    roleLabel: 'مالک',
    accountState,
    canWrite: accountState !== 'initial',
    pendingInvitations: 0,
    isAdmin: false,
    isSuperAdmin: false,
    complex:
      accountState === 'initial' ? null : ({ id: 1, name: 'آفتاب' } as CurrentUser['complex']),
  }
}

let currentPath = ''

// روتر، پوسته و صفحه هر سه تنبل‌اند؛ زیرِ بارِ اجرای موازیِ کلِ مجموعه یک ثانیه کم است
const SLOW = { timeout: 8000 }

function PathProbe() {
  currentPath = useLocation().pathname
  return null
}

function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <AppRouter />
        <PathProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/*
 * ⚠️ مهلتِ هر تست ۲۰ ثانیه، نه ۵ ِ پیش‌فرض: تستِ اول هزینه‌ی سردِ سه import ِ
 * تنبل (روتر، پوسته، صفحه) را می‌دهد و انتظارهایش (SLOW) خودشان تا ۸ ثانیه‌اند.
 * زیرِ بارِ hook ِ pre-commit همان تست با ۵ ثانیه پیاپی می‌افتاد.
 */
describe('تجربه‌ی ثبت‌نام‌کننده‌ی اولیه', { timeout: 20_000 }, () => {
  beforeEach(() => {
    currentPath = ''
  })

  it('داشبوردِ خودش را در پوسته‌ی خودش می‌گیرد — نه سایدبارِ ساکن', async () => {
    useAuthStore.setState({ user: user('initial'), isLoading: false })
    const { container } = renderAt('/dashboard')

    expect(
      await screen.findByText('ثبت‌نام شما کامل است؛ هنوز عضو هیچ مجتمعی نیستید', {}, SLOW),
    ).toBeInTheDocument()
    expect(container.querySelector('[data-experience="registrant"]')).not.toBeNull()

    const menu = screen.getByRole('navigation', { name: 'منوی حساب' })
    expect([...menu.querySelectorAll('a')].map((a) => a.getAttribute('href'))).toEqual(
      REGISTRANT_NAV.map((item) => item.to),
    )
    // هیچ مقصدِ ساکن یا مدیر در صفحه نیست
    for (const path of ['/my-bills', '/wallet', '/requests', '/messenger', '/units']) {
      expect(container.querySelector(`a[href="${path}"]`), path).toBeNull()
    }
  })

  it.each([
    '/my-bills',
    '/wallet',
    '/requests',
    '/messenger',
    '/units',
    '/system/feedback',
    '/چیزی',
  ])('%s مالِ این حالت نیست و به داشبوردِ خودش برمی‌گردد', async (path) => {
    useAuthStore.setState({ user: user('initial'), isLoading: false })
    renderAt(path)

    await waitFor(() => expect(currentPath).toBe('/dashboard'), SLOW)
    expect(
      await screen.findByText('ثبت‌نام شما کامل است؛ هنوز عضو هیچ مجتمعی نیستید', {}, SLOW),
    ).toBeInTheDocument()
  })

  it('هر چهار مقصدِ منو در همین تجربه باز می‌شوند', async () => {
    useAuthStore.setState({ user: user('initial'), isLoading: false })

    for (const { to } of REGISTRANT_NAV) {
      const { container, unmount } = renderAt(to)
      await waitFor(
        () => expect(container.querySelector('[data-experience="registrant"]'), to).not.toBeNull(),
        SLOW,
      )
      expect(currentPath).toBe(to)
      unmount()
    }
  })

  it('عضوِ مجتمع همان پوسته‌ی قبلی را می‌گیرد، نه این یکی', async () => {
    useAuthStore.setState({ user: user('member'), isLoading: false })
    const { container } = renderAt('/my-bills')

    await waitFor(() => expect(container.querySelector('aside')).not.toBeNull(), SLOW)
    expect(currentPath).toBe('/my-bills')
    expect(container.querySelector('[data-experience="registrant"]')).toBeNull()
  })
})
