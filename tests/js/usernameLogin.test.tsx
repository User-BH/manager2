import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * ورود با نام کاربری (مرحلهٔ ۱۳ · فاز ۱؛ پیامِ کارفرما ۲۰۲۶-۱۰-۱۹، بخش ۳) — فقط حساب‌های آزمایشیِ Preview، روی
 * همان `/auth` (۲۰۲۶-۱۰-۲۹، بندِ ۴). نوعِ شناسه صریح به سرور می‌رود، شماره در بدنه نیست، و «نادرست» همان رفتارِ فرمِ
 * شماره را دارد.
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

// پازلِ واقعی با کشیدنِ نشانگر حل می‌شود؛ این‌جا فقط «حل‌شده» لازم است (همان جانشینِ LoginForm.test)
vi.mock('@/features/auth/components/SlidePuzzle', () => ({
  SlidePuzzle: ({ onSolved }: { onSolved: (v: boolean) => void }) => (
    <button type="button" onClick={() => onSolved(true)}>
      حل-پازل
    </button>
  ),
}))

const { default: UsernameLoginPage } = await import('@/features/auth/UsernameLoginPage')

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/auth']}>
      <UsernameLoginPage />
    </MemoryRouter>,
  )
}

describe('UsernameLoginPage', () => {
  beforeEach(() => {
    apiMock.mockReset()
    toastTopErrorMock.mockReset()
  })

  it('نوعِ شناسه را صریح می‌فرستد، شماره نمی‌فرستد و ورودی را پالوده می‌کند', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({})
    renderPage()

    // حرفِ بزرگ و نویسه‌ی نامجاز همان لحظه پالوده می‌شود
    await user.type(screen.getByPlaceholderText('نام کاربری'), 'Demo.Multi!')
    expect(screen.getByPlaceholderText('نام کاربری')).toHaveValue('demo.multi')

    await user.type(screen.getByPlaceholderText('رمز عبور خود را وارد کنید'), 'secret123')
    await user.click(screen.getByText('حل-پازل'))
    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(1))
    expect(apiMock.mock.calls[0][0]).toBe('/login')
    expect(apiMock.mock.calls[0][1].body).toEqual({
      identifier_type: 'username',
      username: 'demo.multi',
      password: 'secret123',
    })
  })

  it('بی پازل و با نام کاربریِ بدقالب به سرور نمی‌رود', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.type(screen.getByPlaceholderText('نام کاربری'), '9ab')
    await user.type(screen.getByPlaceholderText('رمز عبور خود را وارد کنید'), 'secret123')
    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    expect(await screen.findByText(/با حرف انگلیسی شروع می‌شود/)).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('«نادرست» هر دو اینپوت را قرمز می‌کند و توست می‌دهد', async () => {
    const user = userEvent.setup()
    apiMock.mockRejectedValue(
      new FakeApiError('نام کاربری یا رمز عبور نادرست است.', 422, {
        username: ['نام کاربری یا رمز عبور نادرست است.'],
      }),
    )
    renderPage()

    const username = screen.getByPlaceholderText('نام کاربری')
    const password = screen.getByPlaceholderText('رمز عبور خود را وارد کنید')
    await user.type(username, 'demo.single')
    await user.type(password, 'wrongpass')
    await user.click(screen.getByText('حل-پازل'))
    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    await waitFor(() =>
      expect(toastTopErrorMock).toHaveBeenCalledWith('نام کاربری یا رمز عبور نادرست است.'),
    )
    await waitFor(() => {
      expect(username).toHaveAttribute('aria-invalid', 'true')
      expect(password).toHaveAttribute('aria-invalid', 'true')
    })
  })

  it('تنها فرمِ ورودِ Preview است: نه پیوندِ ورود با شماره، نه ثبت‌نام', () => {
    renderPage()

    expect(screen.getByRole('heading', { name: 'ورود به محیط آزمایشی' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'ورود با شماره موبایل' })).toBeNull()
    expect(screen.queryByPlaceholderText('۰۹xxxxxxxxx')).toBeNull()
    expect(screen.queryByText(/ثبت‌نام/)).toBeNull()
  })
})
