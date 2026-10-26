import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AccountPage } from '@/features/account/AccountPage'
import type {
  AccountSettingsResponse,
  PaymentHistoryResponse,
  PlanCard,
} from '@/shared/ui/plans/types'

/**
 * «تنظیماتِ حساب» ِ مدیر (۱۳/۶؛ ایرادات ۱۰، بندهای ۴، ۲۰ تا ۲۴ و ۲۶ تا ۲۸).
 *
 * پلنِ فعلی و مجتمع بالای صفحه، «تمدیدِ پلنِ فعلی» مستقیم به گامِ مدت، هشدار و تأییدِ صریحِ «تغییرِ پلن»، و
 * تاریخچه‌ی صفحه‌بندی‌شده. قاعده‌ی واقعی در سرور است (`AccountPlansTest`)؛ این‌جا فقط آنچه رابط می‌پرسد و می‌فرستد.
 */

const card = (
  slug: string,
  tier: PlanCard['tier'],
  tierLabel: string,
  isCurrent = false,
): PlanCard => ({
  slug,
  name: tierLabel,
  description: null,
  tier,
  tierLabel,
  complexType: 'residential',
  capabilities: [],
  features: [],
  includesEverything: false,
  prices: [
    { months: 1, price: 200000, priceLabel: '۲۰۰,۰۰۰' },
    { months: 3, price: 550000, priceLabel: '۵۵۰,۰۰۰' },
  ],
  isCurrent,
})

const state = vi.hoisted(() => ({
  settings: {} as AccountSettingsResponse,
  history: {} as PaymentHistoryResponse,
  requested: [] as string[],
}))

vi.mock('@/shared/lib/api', () => ({
  api: vi.fn((path: string) => {
    state.requested.push(path)
    return Promise.resolve(
      path.startsWith('/subscription/history') ? state.history : state.settings,
    )
  }),
  ApiError: class extends Error {},
}))
vi.mock('@/shared/lib/alert', () => ({
  alertInfo: vi.fn(),
  alertSuccess: vi.fn(),
  alertError: vi.fn(),
  confirmAction: vi.fn(() => Promise.resolve(true)),
  toastSuccess: vi.fn(),
}))

let submitted: Record<string, FormDataEntryValue>[] = []

function renderPage(path = '/account') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <AccountPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  submitted = []
  state.requested = []
  vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (
    this: HTMLFormElement,
  ) {
    submitted.push(Object.fromEntries(new FormData(this).entries()))
  })
  vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined)
  state.settings = {
    account: 'manager',
    complexName: 'مجتمع مسکونی آفتاب',
    complexType: 'residential',
    complexTypeLabel: 'مسکونی',
    currentPlan: 'res-pro',
    currentPlanLabel: 'حرفه‌ای',
    current: {
      id: 9,
      plan: 'res-pro',
      planLabel: 'حرفه‌ای',
      status: 'active',
      statusLabel: 'فعال',
      method: 'online',
      methodLabel: 'پرداخت آنلاین',
      amount: 550000,
      amountLabel: '۵۵۰,۰۰۰',
      buyerName: 'مدیر',
      startsAt: '۱۴۰۵/۰۷/۰۱',
      endsAt: '۱۴۰۵/۱۰/۰۱',
      daysLeft: 45,
      trackingCode: null,
      reviewNote: null,
      hasReceipt: false,
      createdAt: '۱۴۰۵/۰۷/۰۱',
      tierLabel: 'حرفه‌ای',
      months: 3,
      paidUntil: '۱۴۰۵/۱۰/۰۱',
      daysLeftTotal: 45,
    },
    renewal: { plan: 'res-pro' },
    usage: { units: 10, unitLimit: null },
    catalog: {
      residential: [
        card('res-basic', 'basic', 'پایه'),
        card('res-pro', 'professional', 'حرفه‌ای', true),
        card('res-adv', 'advanced', 'پیشرفته'),
      ],
    },
    durations: [1, 3, 6, 12, 18, 24],
    baseFeatures: ['پیام‌رسان'],
    checkoutEnabled: true,
    checkoutAction: '/subscription/checkout',
    supportPhone: '021-1',
    history: [],
  }
})
afterEach(() => vi.restoreAllMocks())

describe('تنظیماتِ حسابِ مدیر', () => {
  it('پلنِ فعلی و مجتمع بالای صفحه؛ «تمدیدِ پلنِ فعلی» مستقیم به مدت، بی انتخابِ دوباره‌ی نوع و پلن', async () => {
    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText(/پلنِ فعلیِ شما: حرفه‌ای — ۳ ماهه/)).toBeInTheDocument()
    expect(screen.getByText('مجتمع: مجتمع مسکونی آفتاب')).toBeInTheDocument()
    expect(screen.getByText('۴۵ روز باقی‌مانده')).toBeInTheDocument()
    // مدیر نوعِ مجتمع را انتخاب نمی‌کند: از کارت‌های پلن شروع می‌شود
    expect(screen.queryByRole('heading', { name: 'نوع مجتمع خود را انتخاب کنید.' })).toBeNull()

    await user.click(screen.getByRole('button', { name: 'تمدید پلن فعلی' }))
    expect(screen.getByRole('heading', { name: 'مدتِ اشتراکِ پلنِ «حرفه‌ای»' })).toBeInTheDocument()
    expect(screen.getByText(/مدتِ تازه به مدتِ باقی‌مانده اضافه می‌شود/)).toBeInTheDocument()
    const steps = screen.getByRole('navigation', { name: 'مراحلِ خرید' })
    expect(within(steps).getByText('مدت اشتراک')).toHaveAttribute('aria-current', 'step')

    await user.click(screen.getByRole('button', { name: /۳ ماهه/ }))
    expect(
      screen.getByText(/به پایانِ اشتراکِ فعلی \(۱۴۰۵\/۱۰\/۰۱\) اضافه می‌شود/),
    ).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'تأیید و پرداخت' }))

    await waitFor(() => expect(submitted).toHaveLength(1))
    expect(submitted[0]).toMatchObject({
      plan: 'res-pro',
      months: '3',
      confirm_change: '0',
      purpose: '',
    })
  })

  it('تغییرِ پلن: هشدارِ روشن و تأییدِ صریح پیش از پرداخت؛ بی تأیید چیزی نمی‌رود', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: /پیشرفته/ }))
    expect(screen.getByText(/این تغییرِ پلن است/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /۱ ماهه/ }))

    expect(screen.getByRole('heading', { name: 'تأییدِ تغییرِ پلن' })).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent(
      'پلنِ فعلیِ شما خاتمه پیدا می‌کند و مدتِ باقی‌مانده‌اش منتقل نمی‌شود',
    )
    const pay = screen.getByRole('button', { name: 'تأیید و پرداخت' })
    expect(pay).toBeDisabled()
    await user.click(pay)
    // و بی دکمه هم (Enter در فرم): خودِ ارسال تأیید را می‌خواهد، نه فقط دکمه‌ی خاموش
    fireEvent.submit(pay.closest('form')!)
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(submitted).toHaveLength(0)

    await user.click(screen.getByRole('checkbox', { name: /متوجه شدم/ }))
    await user.click(pay)
    await waitFor(() => expect(submitted).toHaveLength(1))
    expect(submitted[0]).toMatchObject({ plan: 'res-adv', months: '1', confirm_change: '1' })
  })

  it('«تاریخچه‌ی پرداخت‌ها» محتوای وسط را عوض می‌کند و صفحه‌بندی دارد', async () => {
    const user = userEvent.setup()
    state.history = {
      data: [
        {
          id: 'subscription-1',
          planLabel: 'حرفه‌ای',
          tierLabel: 'حرفه‌ای',
          complexTypeLabel: 'مسکونی',
          complexName: 'مجتمع مسکونی آفتاب',
          months: 3,
          amountLabel: '۳,۵۰۰,۰۰۰',
          date: '۲۵ مهر ۱۴۰۵',
          time: '14:35',
          status: 'active',
          statusLabel: 'موفق',
          trackingCode: '1',
        },
      ],
      meta: { currentPage: 1, lastPage: 2, total: 16 },
    }
    renderPage('/account/history')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'تاریخچه پرداخت‌ها' }),
    ).toBeInTheDocument()
    expect(await screen.findByText('حرفه‌ای — ۳ ماهه')).toBeInTheDocument()
    expect(screen.getByText(/۲۵ مهر ۱۴۰۵ ساعت 14:35/)).toBeInTheDocument()
    expect(screen.getByText('۳,۵۰۰,۰۰۰ تومان')).toBeInTheDocument()
    expect(screen.getByText('موفق')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'تمدید پلن فعلی' })).toBeNull()

    await user.click(screen.getByRole('button', { name: /بعدی/ }))
    await waitFor(() => expect(state.requested).toContain('/subscription/history?page=2'))
  })
})
