import { render as rtlRender, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useNavigationType } from 'react-router-dom'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * تستِ کامپوننتیِ فرمِ ورود — بحرانی‌ترین فرمِ سایت.
 *
 * چهار چیزی که اینجا سنجیده می‌شود، هر چهار مورد پیش‌تر باگ بوده‌اند:
 *  • اعتبارسنجیِ سمت کلاینت پیش از تماس با سرور
 *  • دروازه‌ی پازل: بدون حلِ پازل نباید درخواستی برود
 *  • «شماره یا رمز نادرست» باید *هر دو* اینپوت را قرمز کند و توستِ بالا-وسط بدهد
 *  • هر ورودِ ناموفق باید پازل را از نو بسازد
 */

const apiMock = vi.fn()
const toastTopErrorMock = vi.fn()

class FakeApiError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(message: string, status: number, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
  fieldError(field: string) {
    return this.errors[field]?.[0]
  }
}

vi.mock('@/shared/lib/api', () => ({
  api: (...args: unknown[]) => apiMock(...args),
  ApiError: FakeApiError,
  setCsrfToken: vi.fn(),
}))

vi.mock('@/shared/lib/alert', () => ({
  toastTopError: (...args: unknown[]) => toastTopErrorMock(...args),
  toastTopSuccess: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  alertError: vi.fn(),
  confirmAction: vi.fn(),
}))

/*
 * پازل با یک دکمه‌ی ساده جانشین می‌شود.
 *
 * دلیلش این است که پازلِ واقعی با کشیدنِ نشانگر (pointer capture) حل می‌شود و
 * در jsdom قابل تقلید نیست. تستِ خودِ پازل کارِ این فایل نیست؛ اینجا فقط لازم
 * است بتوانیم حالتِ «حل‌شده» و «حل‌نشده» را کنترل کنیم. `resetSignal` هم بازتاب
 * داده می‌شود تا بتوانیم بسنجیم که پس از خطا پازل از نو ساخته می‌شود.
 */
vi.mock('@/features/auth/components/SlidePuzzle', () => ({
  SlidePuzzle: ({
    onSolved,
    resetSignal,
  }: {
    onSolved: (v: boolean) => void
    resetSignal?: number
  }) => (
    <div>
      <button type="button" onClick={() => onSolved(true)}>
        حل-پازل
      </button>
      <span data-testid="puzzle-reset">{resetSignal ?? 0}</span>
    </div>
  ),
}))

const { LoginForm } = await import('@/features/auth/components/LoginForm')

/** فرم از useNavigate و Link استفاده می‌کند، پس به بستر روتر نیاز دارد. */
function render(ui: React.ReactElement) {
  return rtlRender(<MemoryRouter>{ui}</MemoryRouter>)
}

/*
 * حالتِ فیلد از صفت خوانده می‌شود، نه از رنگِ درون‌خطی (مرحلهٔ ۷ · فاز ۱):
 * رنگ را `.field` در CSS از همین صفت‌ها می‌گیرد.
 */
function stateOf(el: HTMLElement): 'invalid' | 'valid' | 'neutral' {
  const invalid = el.getAttribute('aria-invalid') === 'true'
  const valid = el.dataset.valid === 'true'
  // ⚠️ هر دو با هم یعنی همان باگِ «سبز و قرمزِ هم‌زمان»
  if (invalid && valid) throw new Error('فیلد هم‌زمان نامعتبر و معتبر است')
  return invalid ? 'invalid' : valid ? 'valid' : 'neutral'
}

describe('LoginForm', () => {
  beforeEach(() => {
    apiMock.mockReset()
    toastTopErrorMock.mockReset()
    localStorage.clear()
  })

  it('برای فرمِ خالی خطای اعتبارسنجی نشان می‌دهد و به سرور درخواست نمی‌زند', async () => {
    const user = userEvent.setup()
    render(<LoginForm />)

    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    expect(await screen.findByText('شماره موبایل را وارد کنید')).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('بدون حلِ پازل درخواست نمی‌فرستد و توستِ اخطار می‌دهد', async () => {
    const user = userEvent.setup()
    render(<LoginForm />)

    await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
    await user.type(screen.getByPlaceholderText('رمز عبور خود را وارد کنید'), 'secret123')
    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    await waitFor(() => expect(toastTopErrorMock).toHaveBeenCalled())
    expect(toastTopErrorMock.mock.calls[0][0]).toContain('پازل')
    // مهم‌ترین بخش: هیچ تماسی با سرور نباید انجام شده باشد
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('با پازلِ حل‌شده درخواست را می‌فرستد', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ otpRequired: true, phone: '09123456789' })
    render(<LoginForm />)

    await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
    await user.type(screen.getByPlaceholderText('رمز عبور خود را وارد کنید'), 'secret123')
    await user.click(screen.getByText('حل-پازل'))
    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(1))
    expect(apiMock.mock.calls[0][0]).toBe('/login')
  })

  it('«شماره یا رمز نادرست» هر دو اینپوت را قرمز می‌کند و توست می‌دهد', async () => {
    const user = userEvent.setup()
    apiMock.mockRejectedValue(
      new FakeApiError('شماره تلفن یا رمز عبور نادرست است.', 422, {
        phone: ['شماره تلفن یا رمز عبور نادرست است.'],
      }),
    )
    render(<LoginForm />)

    const phone = screen.getByPlaceholderText('۰۹xxxxxxxxx')
    const password = screen.getByPlaceholderText('رمز عبور خود را وارد کنید')

    await user.type(phone, '09123456789')
    await user.type(password, 'wrongpass')
    await user.click(screen.getByText('حل-پازل'))
    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    await waitFor(() => expect(toastTopErrorMock).toHaveBeenCalled())

    // پیام باید در توست باشد، نه زیرِ اینپوت
    expect(toastTopErrorMock.mock.calls[0][0]).toBe('شماره تلفن یا رمز عبور نادرست است.')
    // و هر دو اینپوت قرمز شوند
    await waitFor(() => {
      expect(stateOf(phone)).toBe('invalid')
      expect(stateOf(password)).toBe('invalid')
    })
  })

  it('پیش از ارسال خنثی؛ پس از ارسال، درست فقط سبز و نادرست فقط قرمز', async () => {
    const user = userEvent.setup()
    render(<LoginForm />)

    const phone = screen.getByPlaceholderText('۰۹xxxxxxxxx')
    const password = screen.getByPlaceholderText('رمز عبور خود را وارد کنید')

    // ⚠️ پیش از اعتبارسنجی، شماره‌ی کامل هم سبز نیست — هنوز سنجیده نشده
    await user.type(phone, '09123456789')
    await user.type(password, '12')
    expect(stateOf(phone)).toBe('neutral')
    expect(stateOf(password)).toBe('neutral')

    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    await waitFor(() => expect(stateOf(password)).toBe('invalid'))
    expect(stateOf(phone)).toBe('valid')

    // پس از ارسال، با هر تایپ دوباره سنجیده می‌شود
    await user.type(password, '3456')
    await waitFor(() => expect(stateOf(password)).toBe('valid'))
  })

  it('گامِ کد جای فرمِ ورود را در تاریخچه می‌گیرد، نه یک گامِ تازه', async () => {
    /*
     * ⚠️ مرحلهٔ ۷ · فاز ۱: کلِ جریانِ ورود **یک** ورودی در تاریخچه است.
     * اگر گامِ کد `push` شود، پس از ورود «بازگشت» از داشبورد به فرمِ ورود
     * می‌رسد.
     */
    function NavigationType() {
      return <p>نوعِ ناوبری: {useNavigationType()}</p>
    }

    const user = userEvent.setup()
    apiMock.mockResolvedValue({ otpRequired: true, phone: '09123456789' })
    rtlRender(
      <MemoryRouter initialEntries={['/', '/auth']} initialIndex={1}>
        <Routes>
          <Route path="/auth" element={<LoginForm />} />
          <Route path="/auth/verify" element={<NavigationType />} />
        </Routes>
      </MemoryRouter>,
    )

    await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
    await user.type(screen.getByPlaceholderText('رمز عبور خود را وارد کنید'), 'secret123')
    await user.click(screen.getByText('حل-پازل'))
    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    expect(await screen.findByText('نوعِ ناوبری: REPLACE')).toBeInTheDocument()
  })

  it('پس از ورودِ ناموفق، پازل از نو ساخته می‌شود', async () => {
    const user = userEvent.setup()
    apiMock.mockRejectedValue(new FakeApiError('خطا', 422, { phone: ['نادرست'] }))
    render(<LoginForm />)

    expect(screen.getByTestId('puzzle-reset')).toHaveTextContent('0')

    await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
    await user.type(screen.getByPlaceholderText('رمز عبور خود را وارد کنید'), 'wrongpass')
    await user.click(screen.getByText('حل-پازل'))
    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    // شمارنده‌ی بازنشانی باید بالا رفته باشد → پازل تازه
    await waitFor(() => expect(screen.getByTestId('puzzle-reset')).toHaveTextContent('1'))
  })

  it('پیوندِ «ورود با نام کاربری» به صفحه‌ی جدایش می‌رود (۱۳/۱)', () => {
    render(<LoginForm />)

    expect(screen.getByRole('link', { name: 'ورود با نام کاربری' })).toHaveAttribute(
      'href',
      '/auth/username',
    )
  })

  it('کپی‌کردن از فیلد رمز مسدود است', async () => {
    const user = userEvent.setup()
    render(<LoginForm />)

    const password = screen.getByPlaceholderText('رمز عبور خود را وارد کنید')
    await user.type(password, 'Secret123')

    const copyEvent = new Event('copy', { bubbles: true, cancelable: true })
    const notPrevented = password.dispatchEvent(copyEvent)

    // dispatchEvent برای رویدادِ preventDefault‌شده false برمی‌گرداند
    expect(notPrevented).toBe(false)
  })
})
