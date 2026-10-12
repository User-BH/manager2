import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/shared/stores/authStore'
import type { CurrentUser } from '@/shared/types'

/**
 * دعوت‌های دریافتیِ عضو — مرحلهٔ ۱۰ · فاز ۴.
 *
 * عضوی که به مجتمعِ دیگری دعوت شده آن را در «پروفایلِ من» می‌بیند — فقط وقتی دعوتی هست،
 * پس پروفایلِ بقیه همان قبلی است. پذیرفتن عضویتِ دوم است و به زمینه‌ی مجتمعِ تازه می‌برد:
 * همان `enterNewContext` (کشِ API، خبر به تب‌ها، ناوبریِ کامل)، نه فقط تازه‌کردنِ صفحه.
 */

const apiMock = vi.fn()
vi.mock('@/shared/lib/api', () => ({
  api: (...args: unknown[]) => apiMock(...args),
  ApiError: class extends Error {
    errors: Record<string, string[]> = {}
  },
}))
const entered = vi.fn()
vi.mock('@/shared/lib/accountContext', () => ({
  enterNewContext: () => {
    entered()
    return Promise.resolve()
  },
  switchContext: vi.fn(),
}))

const PROFILE = {
  profile: {
    id: 7,
    name: 'علی دوعضوی',
    firstName: 'علی',
    lastName: 'دوعضوی',
    needsName: false,
    phone: '09120000000',
    email: null,
    nationalId: null,
    birthDate: null,
    birthDateLabel: null,
    emergencyPhone: null,
    address: null,
    bio: null,
    role: 'owner',
    roleLabel: 'مالک',
    isAdmin: false,
    isActive: true,
    canMessage: true,
    joinedAt: '۱۴۰۵/۰۷/۱۵',
    complex: null,
  },
  units: [],
  people: [],
  complexes: [],
  stats: { unitsCount: 0, billsCount: 0, paidCount: 0, totalDebt: 0 },
}

const INVITATION = {
  id: 31,
  complexName: 'مجتمع سوم',
  complexAddress: null,
  unitLabel: 'واحد ۴',
  roleLabel: 'مستأجر',
  invitedBy: 'مدیرِ سوم',
  createdAt: null,
}

function member(pendingInvitations: number): CurrentUser {
  return {
    id: 7,
    name: 'علی دوعضوی',
    firstName: 'علی',
    lastName: 'دوعضوی',
    needsName: false,
    phone: '09120000000',
    role: 'owner',
    roleLabel: 'مالک',
    isAdmin: false,
    isSuperAdmin: false,
    complex: { id: 1, name: 'الف' } as CurrentUser['complex'],
    accountState: 'member',
    canWrite: true,
    pendingInvitations,
    memberships: [
      { complexId: 1, complexName: 'الف', role: 'owner', roleLabel: 'مالک', current: true },
    ],
  }
}

async function renderProfile(user: CurrentUser) {
  useAuthStore.setState({ user, isLoading: false })
  const { ProfilePage } = await import('@/features/profile/ProfilePage')
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <ProfilePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('دعوت‌های دریافتیِ عضو', () => {
  beforeEach(() => {
    entered.mockReset()
    apiMock.mockReset().mockImplementation((path: string) => {
      if (path === '/profile') return Promise.resolve(PROFILE)
      if (path === '/invitations') return Promise.resolve({ data: [INVITATION] })
      if (path === `/invitations/${INVITATION.id}/code`)
        return Promise.resolve({ otpRequired: false })
      return Promise.resolve({ data: [], supported: false, sessions: [], devices: [] })
    })
  })

  it('عضوِ بی‌دعوت همان پروفایلِ قبلی را می‌بیند و دعوت‌ها خوانده نمی‌شوند', async () => {
    await renderProfile(member(0))

    expect(
      await screen.findByText('علی دوعضوی', { selector: 'h1, h2, p, span' }),
    ).toBeInTheDocument()
    expect(screen.queryByText('دعوت‌های شما')).toBeNull()
    expect(apiMock.mock.calls.map(([path]) => path)).not.toContain('/invitations')
  })

  it('عضوِ دعوت‌شده دعوت را می‌بیند و پذیرفتن به زمینه‌ی تازه می‌برد', async () => {
    await renderProfile(member(1))

    expect(await screen.findByText('دعوت‌های شما')).toBeInTheDocument()
    expect(screen.getByText('مجتمع سوم')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /پیوستن/ }))

    await waitFor(() => expect(entered).toHaveBeenCalledTimes(1))
    expect(apiMock).toHaveBeenCalledWith(`/invitations/${INVITATION.id}/accept`, { method: 'POST' })
  })
})
