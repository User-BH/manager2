import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import type { CurrentUser, MembershipSummary } from '@/shared/types'

/**
 * خریدِ مجتمعِ تازه با حسابی که از قبل عضو است — رابط (مرحلهٔ ۱۰ · فاز ۵؛ تصمیمِ کارفرما).
 *
 * قاعده‌ی قطعی در سرور است (`MemberComplexPurchaseTest`)؛ اینجا: عضو راهِ خرید را در منو می‌بیند، فرمِ درگاه
 * «مجتمعِ تازه» می‌فرستد نه تمدید، و پس از بازگشتِ موفق همین تب — با تعویضِ ۱۰/۴ که سرور می‌سنجد — به
 * مجتمعِ تازه می‌رود؛ `complex` ِ نشانی بی عضویت هیچ تعویضی نمی‌سازد.
 */

const membership = (complexId: number, current: boolean, role = 'owner'): MembershipSummary => ({
  complexId,
  complexName: `مجتمع ${complexId}`,
  role: role as MembershipSummary['role'],
  roleLabel: role,
  status: 'active',
  current,
})

const user = (memberships: MembershipSummary[], extra: Partial<CurrentUser> = {}): CurrentUser => ({
  id: 7,
  name: 'علی',
  firstName: 'علی',
  lastName: 'خریدار',
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
  accountState: 'member',
  canWrite: true,
  pendingInvitations: 0,
  memberships,
  ...extra,
})

const switched: Array<[number, string | undefined]> = []
const toasts: string[] = []

beforeEach(() => {
  vi.resetModules()
  switched.length = 0
  toasts.length = 0
  vi.doMock('@/shared/lib/accountContext', () => ({
    switchContext: (id: number, path?: string) => {
      switched.push([id, path])
      return Promise.resolve()
    },
  }))
  vi.doMock('@/shared/lib/alert', () => ({
    toastSuccess: (title: string) => toasts.push(title),
    toastError: vi.fn(),
    alertInfo: vi.fn(),
    alertError: vi.fn(),
    confirmAction: vi.fn(() => Promise.resolve(true)),
  }))
})

async function renderReturn(current: CurrentUser, url: string) {
  const { useAuthStore } = await import('@/shared/stores/authStore')
  const { useCheckoutReturn } = await import('@/shared/hooks/useCheckoutReturn')
  useAuthStore.setState({ user: current, isLoading: false })

  function Probe() {
    useCheckoutReturn()
    return null
  }
  render(
    <MemoryRouter initialEntries={[url]}>
      <Probe />
    </MemoryRouter>,
  )
}

describe('بازگشت از خریدِ مجتمعِ تازه', () => {
  it('عضوِ مجتمعِ دیگر با همین تب به مجتمعِ تازه می‌رود — از راهِ تعویضِ سرور', async () => {
    await renderReturn(
      user([
        membership(1, true),
        membership(2, false, 'tenant'),
        membership(3, false, 'complex_admin'),
      ]),
      '/dashboard?checkout=success&tracking=9&complex=3',
    )

    await waitFor(() => expect(switched).toEqual([[3, '/dashboard?checkout=success']]))
    expect(toasts).toEqual([])
  })

  it('`complex` ِ نشانی بی عضویتِ فعال هیچ تعویضی نمی‌سازد', async () => {
    await renderReturn(user([membership(1, true)]), '/dashboard?checkout=success&complex=99')

    await waitFor(() => expect(toasts).toHaveLength(1))
    expect(switched).toEqual([])
  })

  it('حسابی که همین حالا در مجتمعِ تازه است فقط پیامِ موفقیت می‌گیرد', async () => {
    await renderReturn(
      user([membership(3, true, 'complex_admin')]),
      '/dashboard?checkout=success&complex=3',
    )

    await waitFor(() => expect(toasts).toHaveLength(1))
    expect(switched).toEqual([])
  })

  it('بی بازگشت از درگاه کاری نمی‌کند', async () => {
    await renderReturn(user([membership(1, true), membership(3, false)]), '/dashboard?complex=3')

    await new Promise((r) => setTimeout(r, 20))
    expect([switched, toasts]).toEqual([[], []])
  })
})

describe('راهِ خرید در منوی کاربر', () => {
  let menu: {
    useAuthStore: (typeof import('@/shared/stores/authStore'))['useAuthStore']
    createQueryClient: (typeof import('@/shared/lib/queryClient'))['createQueryClient']
    UserMenu: (typeof import('@/shared/layout/UserMenu'))['UserMenu']
  }

  /*
   * ایمپورتِ `UserMenu` و درختِ ماژول‌هایش یک بار، با بودجه‌ی زمانیِ خودش. `beforeEach` ِ بالا `vi.resetModules()`
   * می‌زند، پس ایمپورت در بدنه‌ی هر آزمون هر بار سرد بود و زیرِ بارِ موازیِ کلِ Vitest (هوکِ پیش از کامیت) از ۵ ثانیه
   * گذشت — شکستی که به رفتار ربطی نداشت. نمونه‌ها بیرون از رجیستری هم کار می‌کنند و store همان است که `UserMenu`
   * می‌خواند (یک درخت).
   */
  beforeAll(async () => {
    const [{ useAuthStore }, { createQueryClient }, { UserMenu }] = await Promise.all([
      import('@/shared/stores/authStore'),
      import('@/shared/lib/queryClient'),
      import('@/shared/layout/UserMenu'),
    ])
    menu = { useAuthStore, createQueryClient, UserMenu }
  }, 30_000)

  async function renderMenu(current: CurrentUser) {
    const { useAuthStore, createQueryClient, UserMenu } = menu
    useAuthStore.setState({ user: current, isLoading: false })

    render(
      <QueryClientProvider client={createQueryClient()}>
        <MemoryRouter>
          <UserMenu />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    fireEvent.click(screen.getAllByRole('button')[0])
    await screen.findByText('پروفایل من')
  }

  it('عضو — حتی تک‌مجتمعی و غیرِ مدیر — «خریدِ مجتمعِ تازه» را می‌بیند', async () => {
    await renderMenu(user([membership(1, true, 'tenant')]))

    expect(screen.getByText('خریدِ مجتمعِ تازه')).toBeInTheDocument()
  })

  it('ادمینِ کل و حسابِ اولیه نه (اولیه در داشبوردِ خودش می‌خرد)', async () => {
    await renderMenu(user([], { accountState: 'platform', isSuperAdmin: true }))
    expect(screen.queryByText('خریدِ مجتمعِ تازه')).toBeNull()
  })
})

describe('فرمِ درگاه', () => {
  let page: {
    useAuthStore: (typeof import('@/shared/stores/authStore'))['useAuthStore']
    createQueryClient: (typeof import('@/shared/lib/queryClient'))['createQueryClient']
    RegistrantPurchasePage: (typeof import('@/features/registrant/RegistrantPurchasePage'))['RegistrantPurchasePage']
  }

  // همان دلیلِ بالا: ایمپورتِ سردِ صفحه بیرون از زمانِ آزمون، با `api` ِ ساختگی که پیش از آن ثبت می‌شود
  beforeAll(async () => {
    vi.resetModules()
    vi.doMock('@/shared/lib/api', () => ({
      api: () =>
        Promise.resolve({
          checkoutEnabled: true,
          checkoutAction: '/subscription/checkout',
          newComplexPlans: { residential: [], commercial: [] },
        }),
      csrfToken: () => 'tok',
    }))
    const [{ useAuthStore }, { createQueryClient }, { RegistrantPurchasePage }] = await Promise.all(
      [
        import('@/shared/stores/authStore'),
        import('@/shared/lib/queryClient'),
        import('@/features/registrant/RegistrantPurchasePage'),
      ],
    )
    page = { useAuthStore, createQueryClient, RegistrantPurchasePage }
  }, 30_000)

  afterAll(() => {
    vi.doUnmock('@/shared/lib/api')
  })

  it('«مجتمعِ تازه» می‌فرستد، نه تمدیدِ مجتمعِ فعال', async () => {
    const { useAuthStore, createQueryClient, RegistrantPurchasePage } = page
    useAuthStore.setState({ user: user([membership(1, true)]), isLoading: false })

    render(
      <QueryClientProvider client={createQueryClient()}>
        <MemoryRouter>
          <RegistrantPurchasePage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByText('ساختِ مجتمع و خریدِ اشتراک')
    const purpose = document.querySelector<HTMLInputElement>(
      'form[action="/subscription/checkout"] input[name="purpose"]',
    )
    expect(purpose?.value).toBe('new_complex')
    // و متنِ عضو: عضویت‌های فعلی دست نمی‌خورند
    expect(screen.getByText(/عضویت و نقشتان در مجتمع‌های فعلی دست نمی‌خورد/)).toBeInTheDocument()
  })
})
