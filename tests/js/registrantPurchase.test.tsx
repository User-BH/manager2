import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RegistrantPurchasePage } from '@/features/registrant/RegistrantPurchasePage'
import type { AccountSettingsResponse, PlanCard } from '@/shared/ui/plans/types'

/**
 * خریدِ حسابِ اولیه با جادوگرِ ۱۳/۶ (ایرادات ۱۰، بندهای ۳، ۹ تا ۱۹ و ۲۳ تا ۲۵).
 *
 *   نوعِ مجتمع ← پلن ← مدت ← تأیید (نام و نشانی) ← پرداخت
 *
 * فقط پرداختِ آنلاین، بی رسید و بی «تمدیدِ پلنِ فعلی»؛ فرم slug، مدت، نام و نوع را می‌فرستد و مبلغ را سرور تعیین
 * می‌کند. مسیرِ واقعی تا داشبوردِ مدیر در E2E است؛ قاعده‌های سرور در `AccountPlansTest`.
 */

const card = (
  slug: string,
  tier: PlanCard['tier'],
  tierLabel: string,
  prices: [number, string][],
  extra: Partial<PlanCard> = {},
): PlanCard => ({
  slug,
  name: tierLabel,
  description: null,
  tier,
  tierLabel,
  complexType: null,
  capabilities: ['تا ۲۵ واحد'],
  features: [],
  includesEverything: false,
  prices: prices.map(([months, priceLabel]) => ({
    months,
    price: Number(priceLabel.replace(/\D/g, '')),
    priceLabel,
  })),
  isCurrent: false,
  ...extra,
})

const response = vi.hoisted(() => ({ value: {} as AccountSettingsResponse }))
const alertInfo = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', () => ({
  api: vi.fn(() => Promise.resolve(response.value)),
  ApiError: class extends Error {},
}))
vi.mock('@/shared/lib/alert', () => ({ alertInfo, alertSuccess: vi.fn() }))

/*
 * ⚠️ داده **همان لحظه‌ی** `submit()` گرفته می‌شود — همان چیزی که مرورگر می‌فرستد.
 */
let submitted: { form: HTMLFormElement; fields: Record<string, FormDataEntryValue> }[] = []

function renderPage(path = '/account') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <RegistrantPurchasePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function toConfirm(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: /مسکونی/ }))
  await user.click(screen.getByRole('button', { name: /حرفه‌ای/ }))
  await user.click(screen.getByRole('button', { name: /۳ ماهه/ }))
}

describe('خریدِ حسابِ اولیه: نوع، پلن، مدت، تأیید، پرداخت', () => {
  beforeEach(() => {
    submitted = []
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (
      this: HTMLFormElement,
    ) {
      submitted.push({ form: this, fields: Object.fromEntries(new FormData(this).entries()) })
    })
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined)
    response.value = {
      account: 'initial',
      complexName: null,
      complexType: null,
      complexTypeLabel: null,
      currentPlan: 'free',
      currentPlanLabel: 'رایگان',
      current: null,
      renewal: null,
      usage: null,
      catalog: {
        residential: [
          card('res-basic', 'basic', 'پایه', [[1, '۱۰۰,۰۰۰']]),
          card('res-pro', 'professional', 'حرفه‌ای', [
            [1, '۲۰۰,۰۰۰'],
            [3, '۵۵۰,۰۰۰'],
          ]),
          card('res-adv', 'advanced', 'پیشرفته', [[12, '۴,۰۰۰,۰۰۰']], { includesEverything: true }),
        ],
        commercial: [card('com-basic', 'basic', 'پایه‌ی تجاری', [[1, '۱۵۰,۰۰۰']])],
      },
      durations: [1, 3, 6, 12, 18, 24],
      baseFeatures: ['صدورِ قبض و شارژِ دوره‌ای'],
      checkoutEnabled: true,
      checkoutAction: '/subscription/checkout',
      supportPhone: '021-1',
      history: [],
    }
    alertInfo.mockReset()
  })
  afterEach(() => vi.restoreAllMocks())

  it('اول دو کارتِ مسکونی و تجاری با توضیحِ تمدید و تغییر؛ پلن‌ها فقط همان نوع؛ بازگشت بی شروعِ دوباره', async () => {
    const user = userEvent.setup()
    const { container } = renderPage()

    expect(
      await screen.findByRole('heading', { level: 2, name: 'نوع مجتمع خود را انتخاب کنید.' }),
    ).toBeInTheDocument()
    expect(container.textContent).not.toMatch(/رسید|کارت به کارت|آپلود|تمدید پلن فعلی/)
    expect(screen.getByText(/مدتِ باقی‌مانده‌ی پلنِ قبلی منتقل نمی‌شود/)).toBeInTheDocument()
    const steps = screen.getByRole('navigation', { name: 'مراحلِ خرید' })
    expect(within(steps).getByText('نوع مجتمع')).toHaveAttribute('aria-current', 'step')

    await user.click(screen.getByRole('button', { name: /تجاری/ }))
    expect(screen.getByRole('button', { name: /پایه‌ی تجاری/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /حرفه‌ای/ })).toBeNull()

    await user.click(screen.getByRole('button', { name: 'بازگشت به انتخابِ مسکونی یا تجاری' }))
    await user.click(screen.getByRole('button', { name: /مسکونی/ }))
    expect(screen.getAllByRole('button', { name: /پایه|حرفه‌ای|پیشرفته/ })).toHaveLength(3)
    expect(screen.queryByRole('button', { name: /پایه‌ی تجاری/ })).toBeNull()
    // «همه‌ی امکانات بدون محدودیت» فقط روی کارتی که سرور گفته
    expect(screen.getAllByText('تمامِ امکاناتِ سایت، بدون محدودیت')).toHaveLength(1)
  })

  it('مدت فقط از مدت‌های قیمت‌دارِ همان پلن؛ تأیید همه‌چیز را نشان می‌دهد', async () => {
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByRole('button', { name: /مسکونی/ }))
    await user.click(screen.getByRole('button', { name: /حرفه‌ای/ }))

    expect(screen.getByRole('button', { name: /۱ ماهه/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /۳ ماهه/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /۶ ماهه/ })).toBeNull()

    await user.click(screen.getByRole('button', { name: /۳ ماهه/ }))
    expect(screen.getByRole('heading', { name: 'تأییدِ نهایی' })).toBeInTheDocument()
    for (const text of [
      'مسکونی',
      'حرفه‌ای — حرفه‌ای',
      '۳ ماه',
      '۵۵۰,۰۰۰ تومان',
      'پلنِ فعالی ندارید',
    ]) {
      expect(screen.getByText(text)).toBeInTheDocument()
    }
    expect(
      screen.getByText('پس از پرداختِ موفق، مجتمعتان ساخته می‌شود و مدیرِ آن می‌شوید.'),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'بازگشت به انتخابِ مدت' }))
    expect(screen.getByRole('button', { name: /۳ ماهه/ })).toBeInTheDocument()
  })

  it('بی نامِ مجتمع چیزی فرستاده نمی‌شود و نام فقط حرفِ فارسی و رقم می‌گیرد', async () => {
    const user = userEvent.setup()
    renderPage()
    await toConfirm(user)

    await user.click(screen.getByRole('button', { name: 'تأیید و پرداخت' }))
    expect(await screen.findByText('نامِ مجتمع را وارد کنید.')).toBeInTheDocument()
    expect(submitted).toHaveLength(0)

    const name = screen.getByLabelText(/نامِ مجتمع/)
    await user.type(name, 'Sun!مجتمع ۱۲')
    expect(name).toHaveValue('مجتمع ۱۲')
  })

  it('slug، مدت، نام و نوع را به درگاه می‌فرستد — مبلغ نه، تأییدِ تغییر نه', async () => {
    const user = userEvent.setup()
    renderPage()
    await toConfirm(user)
    await user.type(screen.getByLabelText(/نامِ مجتمع/), 'مجتمع آفتاب')
    await user.type(screen.getByLabelText('نشانی (اختیاری)'), 'خیابانِ اول')
    await user.click(screen.getByRole('button', { name: 'تأیید و پرداخت' }))

    await waitFor(() => expect(submitted).toHaveLength(1))
    const { form, fields } = submitted[0]
    expect(form.getAttribute('action')).toBe('/subscription/checkout')
    expect(form.method.toLowerCase()).toBe('post')
    expect(fields).toMatchObject({
      plan: 'res-pro',
      months: '3',
      confirm_change: '0',
      purpose: 'new_complex',
      complex_type: 'residential',
      complex_name: 'مجتمع آفتاب',
      complex_address: 'خیابانِ اول',
    })
    expect(Object.keys(fields)).not.toContain('amount')
    expect(Object.keys(fields)).not.toContain('price')
  })

  it('درگاهِ خاموش گفته می‌شود و فرمی نمی‌رود', async () => {
    response.value.checkoutEnabled = false
    const user = userEvent.setup()
    renderPage()
    await toConfirm(user)
    await user.type(screen.getByLabelText(/نامِ مجتمع/), 'مجتمع آفتاب')
    await user.click(screen.getByRole('button', { name: 'تأیید و پرداخت' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'پرداختِ آنلاینِ اشتراک فعلاً در دسترس نیست',
    )
    expect(submitted).toHaveLength(0)
  })

  it('بازگشتِ ناموفق از درگاه پیامِ روشن می‌دهد', async () => {
    renderPage('/account?checkout=failed')
    await waitFor(() =>
      expect(alertInfo).toHaveBeenCalledWith(
        'پرداخت انجام نشد.',
        'مجتمعی ساخته نشد. می‌توانید دوباره تلاش کنید.',
      ),
    )
  })
})
