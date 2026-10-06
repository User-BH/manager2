import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RegistrantPurchasePage } from '@/features/registrant/RegistrantPurchasePage'

/**
 * صفحه‌ی خریدِ حسابِ اولیه (مرحلهٔ ۹ · فاز ۵؛ تصمیمِ قطعیِ کارفرما).
 *
 * فقط پرداختِ آنلاین، بی رسید؛ فرم فقط slug ِ پلن و نام و نوع را می‌فرستد و
 * مبلغ را سرور تعیین می‌کند. مسیرِ واقعی تا داشبوردِ مدیر در E2E است.
 */

const response = vi.hoisted(() => ({
  value: {
    checkoutEnabled: true,
    checkoutAction: '/subscription/checkout',
    newComplexPlans: {
      residential: [
        {
          value: 'basic',
          label: 'پایه',
          price: 249000,
          priceLabel: '۲۴۹,۰۰۰',
          months: 1,
          features: ['تا ۲۵ واحد'],
        },
      ],
      commercial: [
        {
          value: 'shop',
          label: 'پکیجِ فروشگاه',
          price: 900000,
          priceLabel: '۹۰۰,۰۰۰',
          months: 12,
          features: [],
        },
      ],
    },
  },
}))
const alertInfo = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', () => ({
  api: vi.fn(() => Promise.resolve(response.value)),
  ApiError: class extends Error {},
}))
vi.mock('@/shared/lib/alert', () => ({ alertInfo, alertSuccess: vi.fn() }))

/*
 * ⚠️ داده **همان لحظه‌ی** `submit()` گرفته می‌شود — همان چیزی که مرورگر می‌فرستد.
 * پس از آن React دوباره رندر می‌کند و ورودی‌های پنهان به پیش‌فرض برمی‌گردند؛
 * خواندنِ فرم پس از رندر داده‌ی خالی نشان می‌داد که هرگز فرستاده نشده بود.
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

describe('خریدِ حسابِ اولیه: نام، نوع، پلن، پرداختِ آنلاین', () => {
  beforeEach(() => {
    submitted = []
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (
      this: HTMLFormElement,
    ) {
      submitted.push({ form: this, fields: Object.fromEntries(new FormData(this).entries()) })
    })
    response.value.checkoutEnabled = true
    alertInfo.mockReset()
  })
  afterEach(() => vi.restoreAllMocks())

  it('هیچ راهِ رسیدی نیست؛ پلن‌ها فقط پس از انتخابِ نوع و همان نوع', async () => {
    const user = userEvent.setup()
    const { container } = renderPage()

    expect(
      await screen.findByRole('heading', { level: 1, name: 'ساختِ مجتمع و خریدِ اشتراک' }),
    ).toBeInTheDocument()
    expect(container.textContent).not.toMatch(/رسید|کارت به کارت|آپلود/)
    expect(screen.queryByRole('radiogroup', { name: 'پکیج‌ها' })).toBeNull()

    await user.click(screen.getByRole('radio', { name: 'تجاری' }))
    expect(screen.getByRole('radio', { name: /پکیجِ فروشگاه/ })).toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: /پایه/ })).toBeNull()
    await user.click(screen.getByRole('radio', { name: /پکیجِ فروشگاه/ }))

    // عوض‌کردنِ نوع، پلنِ نوعِ دیگر را برمی‌دارد — نه اینکه بی‌صدا بفرستدش
    await user.click(screen.getByRole('radio', { name: 'مسکونی' }))
    expect(screen.getByRole('radio', { name: /پایه/ })).not.toBeChecked()
    expect(screen.queryByRole('radio', { name: /پکیجِ فروشگاه/ })).toBeNull()

    await user.type(screen.getByLabelText('نامِ مجتمع'), 'مجتمع آفتاب')
    await user.click(screen.getByRole('button', { name: 'پرداختِ آنلاین' }))
    expect(screen.getByRole('alert')).toHaveTextContent('یک پکیج انتخاب کنید.')
    expect(submitted).toHaveLength(0)
  })

  it('نامِ مجتمع هنگامِ تایپ فقط حرفِ فارسی و رقم می‌گیرد', async () => {
    const user = userEvent.setup()
    renderPage()
    const name = await screen.findByLabelText('نامِ مجتمع')

    await user.type(name, 'Sun!مجتمع ۱۲')
    expect(name).toHaveValue('مجتمع ۱۲')
  })

  it('بی نام، نوع یا پلن چیزی فرستاده نمی‌شود و پیامِ روشن می‌آید', async () => {
    const user = userEvent.setup()
    renderPage()
    const pay = await screen.findByRole('button', { name: 'پرداختِ آنلاین' })

    // دکمه با طولِ ورودی خاموش نمی‌شود؛ هر خطا زیرِ فیلدِ خودش
    expect(pay).toBeEnabled()
    await user.click(pay)
    expect(await screen.findByText('نامِ مجتمع را وارد کنید.')).toBeInTheDocument()
    expect(screen.getByText('نوعِ مجتمع را انتخاب کنید.')).toBeInTheDocument()
    expect(screen.getByText('یک پکیج انتخاب کنید.')).toBeInTheDocument()

    await user.type(screen.getByLabelText('نامِ مجتمع'), 'مجتمع آفتاب')
    await user.click(screen.getByRole('radio', { name: 'مسکونی' }))
    await user.click(pay)
    await waitFor(() => expect(screen.queryByText('نامِ مجتمع را وارد کنید.')).toBeNull())
    expect(screen.queryByText('نوعِ مجتمع را انتخاب کنید.')).toBeNull()
    expect(screen.getByRole('alert')).toHaveTextContent('یک پکیج انتخاب کنید.')

    expect(submitted).toHaveLength(0)
  })

  it('فقط slug و نام و نوع را به درگاه می‌فرستد — مبلغ نه', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.type(await screen.findByLabelText('نامِ مجتمع'), 'مجتمع آفتاب')
    await user.click(screen.getByRole('radio', { name: 'مسکونی' }))
    await user.click(screen.getByRole('radio', { name: /پایه/ }))
    await user.type(screen.getByLabelText('نشانی (اختیاری)'), 'خیابانِ اول')
    await user.click(screen.getByRole('button', { name: 'پرداختِ آنلاین' }))

    await waitFor(() => expect(submitted).toHaveLength(1))
    const { form, fields } = submitted[0]
    expect(form.getAttribute('action')).toBe('/subscription/checkout')
    expect(form.method.toLowerCase()).toBe('post')
    expect(fields).toMatchObject({
      plan: 'basic',
      complex_name: 'مجتمع آفتاب',
      complex_type: 'residential',
      complex_address: 'خیابانِ اول',
    })
    expect(Object.keys(fields).sort()).toEqual([
      '_token',
      'complex_address',
      'complex_name',
      'complex_type',
      'plan',
    ])
    expect(screen.getByRole('button', { name: /در حالِ انتقال به درگاه/ })).toBeDisabled()
  })

  it('درگاهِ خاموش گفته می‌شود و فرمی نمی‌رود', async () => {
    response.value.checkoutEnabled = false
    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText('پرداختِ آنلاین هنوز فعال نشده است.')).toBeInTheDocument()
    await user.type(screen.getByLabelText('نامِ مجتمع'), 'مجتمع آفتاب')
    await user.click(screen.getByRole('radio', { name: 'مسکونی' }))
    await user.click(screen.getByRole('radio', { name: /پایه/ }))
    await user.click(screen.getByRole('button', { name: 'پرداختِ آنلاین' }))

    expect(screen.getByRole('alert')).toHaveTextContent('پرداختِ آنلاین هنوز فعال نشده است.')
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
