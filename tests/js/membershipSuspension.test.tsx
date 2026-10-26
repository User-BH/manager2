import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import type { CurrentUser, MembershipSummary } from '@/shared/types'

/**
 * عضویتِ معلق در رابط — مرحلهٔ ۱۰ · فاز ۶.
 *
 * قاعده در سرور است (`MembershipSuspensionTest`): تعویض به مجتمعِ معلق رد می‌شود. اینجا رابط همان را می‌گوید —
 * مجتمعِ معلق در فهرستِ تعویضِ منوی آدمک نیست (از ۱۳/۳؛ ایرادات ۱۱، بندِ ۵.۳: «فقط مجتمع‌هایی که دسترسیِ معتبر
 * دارد» — پیش از آن دیده و بسته بود)، حسابِ تنها‌معلق «هنوز عضو هیچ مجتمعی نیستید» نمی‌بیند و جای تعلیق را می‌خواند،
 * و بازگشت از خرید هرگز به عضویتِ معلق تعویض نمی‌کند.
 */

const membership = (
  complexId: number,
  current: boolean,
  status: MembershipSummary['status'] = 'active',
): MembershipSummary => ({
  complexId,
  complexName: `مجتمع ${complexId}`,
  role: 'owner',
  roleLabel: 'مالک',
  status,
  current,
})

const user = (memberships: MembershipSummary[], extra: Partial<CurrentUser> = {}): CurrentUser => ({
  id: 7,
  name: 'علی',
  firstName: 'علی',
  lastName: 'معلق',
  needsName: false,
  phone: '09120000000',
  role: 'owner',
  roleLabel: 'مالک',
  isAdmin: false,
  isSuperAdmin: false,
  complex: memberships.some((m) => m.current)
    ? ({
        id: memberships.find((m) => m.current)!.complexId,
        name: 'x',
        type: 'residential',
      } as CurrentUser['complex'])
    : null,
  accountState: memberships.some((m) => m.current) ? 'member' : 'initial',
  canWrite: true,
  pendingInvitations: 0,
  memberships,
  ...extra,
})

const switched: number[] = []

beforeEach(() => {
  vi.resetModules()
  switched.length = 0
  vi.doMock('@/shared/lib/accountContext', () => ({
    switchContext: (id: number) => {
      switched.push(id)
      return Promise.resolve()
    },
  }))
  vi.doMock('@/shared/lib/alert', () => ({
    toastSuccess: vi.fn(),
    toastError: vi.fn(),
    alertInfo: vi.fn(),
    alertError: vi.fn(),
    confirmAction: vi.fn(() => Promise.resolve(true)),
  }))
})

/*
 * ⚠️ مهلتِ ۲۰ ثانیه، نه ۵ ِ پیش‌فرض — همان دلیلِ `registrantExperience.test.tsx`: پس از `vi.resetModules()` کلِ گرافِ
 * ماژولِ منوی کاربر (motion، آیکون‌ها، store) درونِ خودِ تست سرد بار می‌شود. زیرِ بارِ هوکِ pre-commit (eslint،
 * prettier و pint هم‌زمان) همین تست با ۵ ثانیه در ۵٫۳ ثانیه افتاد؛ تنها و بی بار ۲۵ میلی‌ثانیه است. ادعاها همان‌اند.
 */
describe('«مجتمع‌های دیگر» با عضویتِ معلق', { timeout: 20_000 }, () => {
  it('مجتمعِ معلق در فهرستِ تعویض نیست؛ مجتمعِ فعالِ دیگر هست و کلیک بر آن تعویض است', async () => {
    const { useAuthStore } = await import('@/shared/stores/authStore')
    const { createQueryClient } = await import('@/shared/lib/queryClient')
    const { UserMenu } = await import('@/shared/layout/UserMenu')
    useAuthStore.setState({
      user: user([membership(1, true), membership(2, false, 'suspended'), membership(3, false)]),
      isLoading: false,
    })

    render(
      <QueryClientProvider client={createQueryClient()}>
        <MemoryRouter>
          <UserMenu />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    fireEvent.click(screen.getAllByRole('button')[0])

    const group = await screen.findByRole('group', { name: 'مجتمع‌های دیگر' })
    expect(screen.queryByText('مجتمع 2')).toBeNull()

    fireEvent.click(screen.getByText('مجتمع 3'))
    await waitFor(() => expect(switched).toEqual([3]))
    expect(group).toBeInTheDocument()
  })
})

describe('داشبوردِ اولیه برای حسابِ تنها‌معلق', () => {
  it('می‌گوید کجا معلق است، نه «هنوز عضو هیچ مجتمعی نیستید»', async () => {
    const { useAuthStore } = await import('@/shared/stores/authStore')
    const { createQueryClient } = await import('@/shared/lib/queryClient')
    const { RegistrantDashboardPage } =
      await import('@/features/registrant/RegistrantDashboardPage')
    useAuthStore.setState({ user: user([membership(4, false, 'suspended')]), isLoading: false })

    render(
      <QueryClientProvider client={createQueryClient()}>
        <MemoryRouter>
          <RegistrantDashboardPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText('عضویت شما معلق است')).toBeInTheDocument()
    expect(screen.getByText(/«مجتمع 4» از سوی مدیرِ همان مجتمع معلق شده است/)).toBeInTheDocument()
    expect(screen.queryByText('ثبت‌نام شما کامل است؛ هنوز عضو هیچ مجتمعی نیستید')).toBeNull()
  })
})

describe('بازگشت از خرید', () => {
  it('به عضویتِ معلق تعویض نمی‌کند', async () => {
    const { useAuthStore } = await import('@/shared/stores/authStore')
    const { useCheckoutReturn } = await import('@/shared/hooks/useCheckoutReturn')
    useAuthStore.setState({
      user: user([membership(1, true), membership(5, false, 'suspended')]),
      isLoading: false,
    })

    function Probe() {
      useCheckoutReturn()
      return null
    }
    render(
      <MemoryRouter initialEntries={['/dashboard?checkout=success&complex=5']}>
        <Probe />
      </MemoryRouter>,
    )

    await new Promise((r) => setTimeout(r, 20))
    expect(switched).toEqual([])
  })
})
