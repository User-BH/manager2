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
  await user.type(screen.getByPlaceholderText('مثلاً علی'), 'علی')
  await user.type(screen.getByPlaceholderText('مثلاً محمدی'), 'محمدی')
  await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
  await user.click(screen.getByRole('checkbox'))
}

describe('RegisterForm — ثبت‌نام دومرحله‌ای', () => {
  beforeEach(() => {
    apiMock.mockReset()
    toastTopSuccessMock.mockReset()
  })

  it('بی رمز (۲۰۲۶-۱۰-۳۰): فرم فیلدِ رمز ندارد و رمزی به سرور نمی‌رود', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ otpRequired: true, phone: '09123456789' })
    render(<RegisterForm />)

    expect(document.querySelector('input[type="password"]')).toBeNull()
    await fillStepOne(user)
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))

    await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(1))
    expect(apiMock.mock.calls[0][1].body).not.toHaveProperty('password')
    expect(apiMock.mock.calls[0][1].body).not.toHaveProperty('password_confirmation')
  })

  it('بدون پذیرشِ قوانین جلو نمی‌رود', async () => {
    const user = userEvent.setup()
    render(<RegisterForm />)

    await user.type(screen.getByPlaceholderText('مثلاً علی'), 'علی')
    await user.type(screen.getByPlaceholderText('مثلاً محمدی'), 'محمدی')
    await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
    // تیکِ قوانین عمداً زده نمی‌شود
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))

    expect(await screen.findByText('برای ادامه باید قوانین را بپذیرید')).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  /*
   * همان قاعده‌ی سرور (مرحلهٔ ۹ · فاز ۳): فقط حرف، فاصله و نیم‌فاصله. فیلترِ قبلی
   * (`filterPersianLetters`) کلِ بلوکِ عربی را راه می‌داد، از جمله «،» و «؟».
   */
  it('نام و نام خانوادگی هنگامِ تایپ فقط حرفِ فارسی می‌گیرند', async () => {
    const user = userEvent.setup()
    render(<RegisterForm />)

    const first = screen.getByPlaceholderText('مثلاً علی')
    const last = screen.getByPlaceholderText('مثلاً محمدی')
    await user.type(first, 'عليAli2،؟')
    await user.type(last, 'محمدی‌نژاد')

    expect(first).toHaveValue('علی')
    expect(last).toHaveValue('محمدی‌نژاد')
  })

  it('گامِ اول فقط کد می‌فرستد و حساب نمی‌سازد', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ otpRequired: true, phone: '09123456789', dev_code: '123456' })
    render(<RegisterForm />)

    await fillStepOne(user)
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))

    await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(1))
    expect(apiMock.mock.calls[0][0]).toBe('/register')
    // نام و نام خانوادگی جدا می‌روند (مرحلهٔ ۹ · فاز ۳)؛ `name` دیگر فرستاده نمی‌شود
    expect(apiMock.mock.calls[0][1].body).toMatchObject({ first_name: 'علی', last_name: 'محمدی' })
    expect(apiMock.mock.calls[0][1].body).not.toHaveProperty('name')

    // به گامِ کد رفته باشیم
    expect(await screen.findByText(/کد شش‌رقمی به شماره/)).toBeInTheDocument()
    // و هنوز هیچ تماسی با verify نشده باشد
    expect(apiMock.mock.calls.every((c) => c[0] !== '/register/verify')).toBe(true)
  })

  it('با کاملِ‌شدنِ کد، حساب ساخته و کاربر مستقیم واردِ داشبورد می‌شود', async () => {
    const user = userEvent.setup()
    const replace = vi.fn()
    vi.stubGlobal('location', {
      ...window.location,
      replace,
      pathname: '/auth',
      search: '',
      hash: '',
    })
    apiMock.mockResolvedValue({ otpRequired: true, phone: '09123456789', dev_code: '123456' })
    render(<RegisterForm />)

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
    // تنها اینجاست که حساب ساخته می‌شود؛ ثبت‌نام همان ورود است — بی گامِ «اکنون وارد شوید»
    await waitFor(() => expect(replace).toHaveBeenCalledWith('/dashboard'))
    expect(toastTopSuccessMock).toHaveBeenCalled()
    vi.unstubAllGlobals()
  })

  it('کدِ نادرست پیام خطا می‌دهد و حساب ساخته نمی‌شود', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ otpRequired: true, phone: '09123456789', dev_code: '123456' })
    render(<RegisterForm />)

    await fillStepOne(user)
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))
    await screen.findByText(/کد شش‌رقمی به شماره/)

    apiMock.mockRejectedValue(
      new FakeApiError('کد نادرست', 422, { code: ['کد واردشده نادرست یا منقضی شده است.'] }),
    )
    const boxes = screen.getAllByRole('textbox')
    for (let i = 0; i < 6; i++) await user.type(boxes[i], '9')

    expect(await screen.findByText('کد واردشده نادرست یا منقضی شده است.')).toBeInTheDocument()
    expect(toastTopSuccessMock).not.toHaveBeenCalled()
  })
})
