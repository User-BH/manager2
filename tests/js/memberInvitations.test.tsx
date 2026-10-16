import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
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
      {
        complexId: 1,
        complexName: 'الف',
        role: 'owner',
        roleLabel: 'مالک',
        status: 'active',
        current: true,
      },
    ],
  }
}

let ProfilePage: (typeof import('@/features/profile/ProfilePage'))['ProfilePage']

function renderProfile(user: CurrentUser) {
  useAuthStore.setState({ user, isLoading: false })
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
  /*
   * ایمپورتِ سردِ درختِ ماژول‌ها یک بار و با بودجه‌ی زمانیِ خودش، نه در بدنه‌ی نخستین آزمون: زیرِ بارِ موازیِ کلِ Vitest
   * (۱۶ کارگر؛ هوکِ پیش از کامیت) همین ایمپورت آزمون را از ۵ ثانیه گذراند (۵٫۴ ثانیه سنجیده شد) — شکستی که به رفتار
   * ربطی نداشت و آزمونِ بعدی را هم با رندرِ نیمه‌کاره آلوده می‌کرد.
   */
  beforeAll(async () => {
    ;({ ProfilePage } = await import('@/features/profile/ProfilePage'))
  }, 30_000)

  beforeEach(() => {
    entered.mockReset()
    apiMock.mockReset().mockImplementation((path: string) => {
      if (path === '/profile') return Promise.resolve(PROFILE)
      if (path === '/invitations') return Promise.resolve({ data: [INVITATION] })
      if (path === `/invitations/${INVITATION.id}/code`)
        return Promise.resolve({ otpRequired: true, message: 'کد فرستاده شد.', devCode: '123456' })
      return Promise.resolve({ data: [], supported: false, sessions: [], devices: [] })
    })
  })

  it('عضوِ بی‌دعوت همان پروفایلِ قبلی را می‌بیند و دعوت‌ها خوانده نمی‌شوند', async () => {
    renderProfile(member(0))

    expect(
      await screen.findByText('علی دوعضوی', { selector: 'h1, h2, p, span' }),
    ).toBeInTheDocument()
    expect(screen.queryByText('دعوت‌های شما')).toBeNull()
    expect(apiMock.mock.calls.map(([path]) => path)).not.toContain('/invitations')
  })

  it('عضوِ دعوت‌شده دعوت را می‌بیند و پذیرفتن به زمینه‌ی تازه می‌برد', async () => {
    renderProfile(member(1))

    expect(await screen.findByText('دعوت‌های شما')).toBeInTheDocument()
    expect(screen.getByText('مجتمع سوم')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /پیوستن/ }))

    // ۱۲/۳ (بندِ ۱۵): پذیرش همیشه با کدِ پیامکیِ همان شماره است — بی کد چیزی فرستاده نمی‌شود
    const code = await screen.findByLabelText('کدِ تأیید')
    expect(apiMock.mock.calls.map(([path]) => path)).not.toContain(
      `/invitations/${INVITATION.id}/accept`,
    )
    fireEvent.change(code, { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: /تأیید و پیوستن/ }))

    await waitFor(() => expect(entered).toHaveBeenCalledTimes(1))
    expect(apiMock).toHaveBeenCalledWith(`/invitations/${INVITATION.id}/accept`, {
      method: 'POST',
      body: { code: '123456' },
    })
  })
})
