import { render as rtlRender, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * تستِ یکپارچگیِ ثبت‌نام دومرحله‌ای.
 *
 * قیدِ اصلیِ محصول اینجاست: **حساب فقط پس از تاییدِ کد ساخته می‌شود.** پس
 * گامِ اول باید صرفاً کد بفرستد و گامِ دوم `/register/verify` را صدا بزند.
 * اگر این بشکند، شماره‌های تاییدنشده در فهرست اعضا رسوب می‌کنند — همان باگی
 * که کارفرما گزارش کرد.
 */

const apiMock = vi.fn()
const toastTopSuccessMock = vi.fn()

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
  toastTopSuccess: (...args: unknown[]) => toastTopSuccessMock(...args),
  toastTopError: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  alertError: vi.fn(),
  confirmAction: vi.fn(),
}))

const { RegisterForm } = await import('@/features/auth/components/RegisterForm')

function render(ui: React.ReactElement) {
  return rtlRender(<MemoryRouter>{ui}</MemoryRouter>)
}

/** پرکردنِ گامِ اولِ فرم با داده‌ی معتبر. */
async function fillStepOne(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByPlaceholderText('مثلاً علی محمدی'), 'علی محمدی')
  await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
  await user.type(screen.getByPlaceholderText('حداقل ۸ نویسه'), 'Secret123')
  await user.type(screen.getByPlaceholderText('تکرار رمز عبور'), 'Secret123')
  await user.click(screen.getByRole('checkbox'))
}

describe('RegisterForm — ثبت‌نام دومرحله‌ای', () => {
  beforeEach(() => {
    apiMock.mockReset()
    toastTopSuccessMock.mockReset()
  })

  it('ناهمخوانیِ رمز و تکرارش را نشان می‌دهد و درخواستی نمی‌فرستد', async () => {
    const user = userEvent.setup()
    render(<RegisterForm />)

    await user.type(screen.getByPlaceholderText('مثلاً علی محمدی'), 'علی محمدی')
    await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
    await user.type(screen.getByPlaceholderText('حداقل ۸ نویسه'), 'Secret123')
    await user.type(screen.getByPlaceholderText('تکرار رمز عبور'), 'Different1')
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))

    expect(await screen.findByText('رمز عبور و تکرار آن یکسان نیستند')).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('بدون پذیرشِ قوانین جلو نمی‌رود', async () => {
    const user = userEvent.setup()
    render(<RegisterForm />)

    await user.type(screen.getByPlaceholderText('مثلاً علی محمدی'), 'علی محمدی')
    await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
    await user.type(screen.getByPlaceholderText('حداقل ۸ نویسه'), 'Secret123')
    await user.type(screen.getByPlaceholderText('تکرار رمز عبور'), 'Secret123')
    // تیکِ قوانین عمداً زده نمی‌شود
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))

    expect(await screen.findByText('برای ادامه باید قوانین را بپذیرید')).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('گامِ اول فقط کد می‌فرستد و حساب نمی‌سازد', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ otpRequired: true, phone: '09123456789', dev_code: '123456' })
    render(<RegisterForm />)

    await fillStepOne(user)
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))

    await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(1))
    expect(apiMock.mock.calls[0][0]).toBe('/register')

    // به گامِ کد رفته باشیم
    expect(await screen.findByText(/کد شش‌رقمی به شماره/)).toBeInTheDocument()
    // و هنوز هیچ تماسی با verify نشده باشد
    expect(apiMock.mock.calls.every((c) => c[0] !== '/register/verify')).toBe(true)
  })

  it('با کاملِ‌شدنِ کد، حساب ساخته می‌شود و به فرم ورود برمی‌گردیم', async () => {
    const user = userEvent.setup()
    const onRegistered = vi.fn()
    apiMock.mockResolvedValue({ otpRequired: true, phone: '09123456789', dev_code: '123456' })
    render(<RegisterForm onRegistered={onRegistered} />)

    await fillStepOne(user)
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))
    await screen.findByText(/کد شش‌رقمی به شماره/)

    // گامِ دوم: پرکردنِ شش خانه
    apiMock.mockResolvedValue({ message: 'ثبت‌نام کامل شد.' })
    const boxes = screen.getAllByRole('textbox')
    for (let i = 0; i < 6; i++) await user.type(boxes[i], String(i + 1))

    await waitFor(() =>
      expect(apiMock.mock.calls.some((c) => c[0] === '/register/verify')).toBe(true),
    )
    // تنها اینجاست که حساب ساخته می‌شود، و کاربر به فرم ورود هدایت می‌شود
    await waitFor(() => expect(onRegistered).toHaveBeenCalled())
    expect(toastTopSuccessMock).toHaveBeenCalled()
  })

  it('کدِ نادرست پیام خطا می‌دهد و حساب ساخته نمی‌شود', async () => {
    const user = userEvent.setup()
    const onRegistered = vi.fn()
    apiMock.mockResolvedValue({ otpRequired: true, phone: '09123456789', dev_code: '123456' })
    render(<RegisterForm onRegistered={onRegistered} />)

    await fillStepOne(user)
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))
    await screen.findByText(/کد شش‌رقمی به شماره/)

    apiMock.mockRejectedValue(
      new FakeApiError('کد نادرست', 422, { code: ['کد واردشده نادرست یا منقضی شده است.'] }),
    )
    const boxes = screen.getAllByRole('textbox')
    for (let i = 0; i < 6; i++) await user.type(boxes[i], '9')

    expect(await screen.findByText('کد واردشده نادرست یا منقضی شده است.')).toBeInTheDocument()
    expect(onRegistered).not.toHaveBeenCalled()
  })
})
