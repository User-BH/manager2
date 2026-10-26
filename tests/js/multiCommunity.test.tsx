import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Outlet } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CurrentUser, MembershipSummary } from '@/shared/types'

/**
 * هسته‌ی چندمجتمعی در رابط — مرحلهٔ ۱۳ · فاز ۳ (ایرادات ۱۰، بندهای ۳۲ تا ۳۵ و ۴۳–۴۴؛ ایرادات ۱۱، بندهای ۵.۵ تا ۵.۸).
 *
 * قاعده‌ی قطعی در سرور است (`MultiCommunityContextTest`): کدام نشست «انتخاب» است، کدام بی‌مجتمع، و هیچ داده یا
 * نوشتنی پیش از گزینش. این‌جا: صفحه‌ی انتخاب فقط کارتِ مجتمع‌های معتبر را دارد و «ایجادِ حسابِ جدید» ندارد، روتر برای
 * `select` هیچ داشبوردی بار نمی‌کند، نوارِ بالا نامِ مجتمعِ همین نشست را می‌گوید، و داشبوردِ اولیه‌ی عضو دروغِ «عضو هیچ
 * مجتمعی نیستید» نمی‌گوید.
 */

const switched: number[] = []
const leaveToAuth = vi.fn()
const logout = vi.fn(() => Promise.resolve())

vi.mock('@/shared/lib/accountContext', () => ({
  switchContext: (id: number) => {
    switched.push(id)
    return Promise.resolve()
  },
  enterInitialContext: vi.fn(() => Promise.resolve()),
  contextMismatch: () => false,
}))
vi.mock('@/shared/lib/leaveToAuth', () => ({ leaveToAuth: () => leaveToAuth() }))
vi.mock('@/shared/layout/DashboardLayout', () => ({
  DashboardLayout: () => (
    <div data-testid="shell">
      <Outlet />
    </div>
  ),
}))
vi.mock('@/features/dashboard/DashboardPage', () => ({
  DashboardPage: () => <p>داشبوردِ مجتمع</p>,
}))

const membership = (
  complexId: number,
  extra: Partial<MembershipSummary> = {},
): MembershipSummary => ({
  complexId,
  complexName: `مجتمع ${complexId}`,
  complexTypeLabel: complexId % 2 === 0 ? 'تجاری' : 'مسکونی',
  role: 'owner',
  roleLabel: 'مالک',
  status: 'active',
  current: false,
  ...extra,
})

const user = (extra: Partial<CurrentUser> = {}): CurrentUser => ({
  id: 7,
  name: 'سارا',
  firstName: 'سارا',
  lastName: 'دوعضوی',
  needsName: false,
  phone: '09120000000',
  role: 'owner',
  roleLabel: 'مالک',
  isAdmin: false,
  isSuperAdmin: false,
  complex: null,
  accountState: 'initial',
  dashboard: 'select',
  canWrite: false,
  pendingInvitations: 0,
  memberships: [membership(1, { role: 'complex_admin', roleLabel: 'مدیر' }), membership(2)],
  ...extra,
})

type Modules = {
  AppRouter: (typeof import('@/app/AppRouter'))['AppRouter']
  CommunitySelectPage: (typeof import('@/features/context/CommunitySelectPage'))['CommunitySelectPage']
  ActiveCommunityBar: (typeof import('@/shared/layout/ActiveCommunityBar'))['ActiveCommunityBar']
  RegistrantDashboardPage: (typeof import('@/features/registrant/RegistrantDashboardPage'))['RegistrantDashboardPage']
  navigation: typeof import('@/shared/config/navigation')
  useAuthStore: (typeof import('@/shared/stores/authStore'))['useAuthStore']
  createQueryClient: (typeof import('@/shared/lib/queryClient'))['createQueryClient']
}
let m: Modules

// ایمپورتِ سرد یک بار و با بودجه‌ی خودش (همان درسِ ۱۰/۱۵)
beforeAll(async () => {
  const [
    { AppRouter },
    { CommunitySelectPage },
    { ActiveCommunityBar },
    { RegistrantDashboardPage },
    navigation,
    { useAuthStore },
    { createQueryClient },
  ] = await Promise.all([
    import('@/app/AppRouter'),
    import('@/features/context/CommunitySelectPage'),
    import('@/shared/layout/ActiveCommunityBar'),
    import('@/features/registrant/RegistrantDashboardPage'),
    import('@/shared/config/navigation'),
    import('@/shared/stores/authStore'),
    import('@/shared/lib/queryClient'),
  ])
  m = {
    AppRouter,
    CommunitySelectPage,
    ActiveCommunityBar,
    RegistrantDashboardPage,
    navigation,
    useAuthStore,
    createQueryClient,
  }
}, 30_000)

beforeEach(() => {
  switched.length = 0
  leaveToAuth.mockClear()
  logout.mockClear()
})

function show(node: React.ReactNode, who: CurrentUser, path = '/dashboard') {
  m.useAuthStore.setState({ user: who, isLoading: false, logout } as never)
  return render(
    <QueryClientProvider client={m.createQueryClient()}>
      <MemoryRouter initialEntries={[path]}>{node}</MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('صفحه‌ی انتخابِ مجتمع', () => {
  it('هر مجتمعِ معتبر یک کارت با نوع و نقش؛ معلق نه', async () => {
    show(
      <m.CommunitySelectPage />,
      user({
        memberships: [
          membership(1, { role: 'complex_admin', roleLabel: 'مدیر' }),
          membership(2),
          membership(3, { status: 'suspended' }),
        ],
      }),
    )

    const list = await screen.findByRole('list', { name: 'مجتمع‌های شما' })
    const cards = list.querySelectorAll('button')
    expect(Array.from(cards).map((b) => b.textContent)).toEqual([
      'مجتمع 1مسکونی · مدیر',
      'مجتمع 2تجاری · مالک',
    ])
    expect(screen.getByRole('heading', { name: 'انتخاب مجتمع' })).toBeInTheDocument()
  })

  it('«ایجادِ حسابِ جدید» این‌جا نیست (بندِ ۵.۵)', async () => {
    show(<m.CommunitySelectPage />, user())

    await screen.findByRole('list', { name: 'مجتمع‌های شما' })
    expect(screen.queryByText(/ایجاد حساب جدید/)).toBeNull()
    expect(screen.queryByText(/خرید/)).toBeNull()
  })

  it('کلیک روی کارت همان تعویضِ واقعیِ زمینه است', async () => {
    show(<m.CommunitySelectPage />, user())

    fireEvent.click(await screen.findByText('مجتمع 2'))
    await waitFor(() => expect(switched).toEqual([2]))
  })

  it('خروج از حساب: نشست بسته و به ورود', async () => {
    show(<m.CommunitySelectPage />, user())

    fireEvent.click(await screen.findByText('خروج از حساب'))
    await waitFor(() => expect(leaveToAuth).toHaveBeenCalled())
    expect(logout).toHaveBeenCalled()
  })
})

describe('روتر برای نشستِ «انتخاب»', () => {
  it('هر نشانی‌ای فقط صفحه‌ی انتخاب است — نه داشبوردِ مجتمع، نه داشبوردِ اولیه', async () => {
    for (const path of ['/dashboard', '/units', '/account', '/messenger']) {
      const { unmount } = show(<m.AppRouter />, user(), path)

      expect(await screen.findByRole('heading', { name: 'انتخاب مجتمع' })).toBeInTheDocument()
      expect(screen.queryByTestId('shell')).toBeNull()
      expect(screen.queryByText('داشبوردِ مجتمع')).toBeNull()
      unmount()
    }
  })
})

describe('نامِ مجتمعِ فعال بالای نوار', () => {
  it('نامِ کاملِ مجتمعِ همین نشست و نقش؛ با تعویض، نامِ تازه', () => {
    const inA = user({
      dashboard: 'manager',
      accountState: 'member',
      role: 'complex_admin',
      roleLabel: 'مدیر',
      complex: { id: 1, name: 'مجتمع مسکونی آفتاب', type: 'residential' } as CurrentUser['complex'],
    })
    show(<m.ActiveCommunityBar />, inA)

    expect(screen.getByRole('note', { name: 'مجتمعِ فعال' }).textContent).toBe(
      'مجتمع مسکونی آفتاب· مدیر',
    )

    act(() =>
      m.useAuthStore.setState({
        user: {
          ...inA,
          role: 'owner',
          roleLabel: 'مالک',
          complex: {
            id: 2,
            name: 'مجتمع تجاری نفیس',
            type: 'commercial',
          } as CurrentUser['complex'],
        },
      }),
    )
    expect(screen.getByTestId('active-community').textContent).toBe('مجتمع تجاری نفیس')
    expect(screen.getByRole('note', { name: 'مجتمعِ فعال' }).textContent).toContain('مالک')
  })

  it('بی مجتمع (سوپر ادمینِ بی‌انتخاب) چیزی نیست', () => {
    show(<m.ActiveCommunityBar />, user({ dashboard: 'platform', isSuperAdmin: true }))

    expect(screen.queryByRole('note', { name: 'مجتمعِ فعال' })).toBeNull()
  })
})

describe('داشبوردِ اولیه‌ی عضو («ایجادِ حسابِ جدید»)', () => {
  it('می‌گوید کجا عضو است و راهِ بازگشت را، نه «هنوز عضو هیچ مجتمعی نیستید»', async () => {
    show(<m.RegistrantDashboardPage />, user({ dashboard: 'initial' }))

    expect(await screen.findByText('مجتمعِ تازه با همین حساب')).toBeInTheDocument()
    expect(screen.getByText(/«مجتمع 1»، «مجتمع 2» عضو هستید/)).toBeInTheDocument()
    expect(screen.queryByText('ثبت‌نام شما کامل است؛ هنوز عضو هیچ مجتمعی نیستید')).toBeNull()
  })

  /*
   * 🔴 خرابکاری این را خواست (۱۳/۳): بی `useCheckoutReturn` در داشبوردِ اولیه، عضوی که از «ایجادِ حسابِ جدید» خرید پس از
   * بازگشتِ بانک در داشبوردِ اولیه می‌ماند و مجتمعِ تازه‌اش را خودش باید پیدا می‌کرد — آزمون‌های هوک فقط خودِ هوک را
   * می‌سنجیدند، نه این‌که صفحه صدایش می‌زند.
   */
  it('بازگشتِ موفق از خرید، از همین داشبورد به مجتمعِ تازه می‌رود', async () => {
    show(
      <m.RegistrantDashboardPage />,
      user({
        dashboard: 'initial',
        memberships: [membership(1), membership(3, { role: 'complex_admin', roleLabel: 'مدیر' })],
      }),
      '/dashboard?checkout=success&tracking=9&complex=3',
    )

    await waitFor(() => expect(switched).toEqual([3]))
  })

  it('حسابِ اولیه‌ی واقعی (بی عضویت) همان پیامِ پیشین', async () => {
    show(<m.RegistrantDashboardPage />, user({ dashboard: 'initial', memberships: [] }))

    expect(
      await screen.findByText('ثبت‌نام شما کامل است؛ هنوز عضو هیچ مجتمعی نیستید'),
    ).toBeInTheDocument()
  })
})

describe('«تنظیماتِ حساب» در منوی کناری (۱۳/۳)', () => {
  it('مدیر و سوپر ادمین دارند؛ واحد نه', () => {
    const has = (role: CurrentUser['role']) =>
      m.navigation
        .visibleSections(role)
        .some((section) => section.items.some((item) => item.path === '/account'))

    expect([has('complex_admin'), has('super_admin'), has('owner'), has('tenant')]).toEqual([
      true,
      true,
      false,
      false,
    ])
  })
})
