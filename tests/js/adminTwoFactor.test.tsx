import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * رابطِ عاملِ دومِ سوپر ادمین (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۵): گامِ دومِ ورود، رفتن به آن از فرمِ شماره (۲۰۲۶-۱۰-۲۹)، و
 * کارتِ راه‌اندازی در «اطلاعاتِ کاربری». قاعده‌ها در سرورند (`AdminTwoFactorTest`)؛ این‌جا فقط این‌که رابط همان را
 * بفرستد و نشان دهد که باید — و کلید و کدها جایی جز همان گام نمانند.
 */

const apiMock = vi.fn()

class FakeApiError extends Error {
  status: number
  errors: Record<string, string[]>
  code?: string
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
  toastTopError: vi.fn(),
  toastTopSuccess: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  alertError: vi.fn(),
  confirmAction: vi.fn(),
}))

vi.mock('@/features/auth/components/SlidePuzzle', () => ({
  SlidePuzzle: ({ onSolved }: { onSolved: (v: boolean) => void }) => (
    <button type="button" onClick={() => onSolved(true)}>
      حل-پازل
    </button>
  ),
}))

const replaceMock = vi.fn()
Object.defineProperty(window, 'location', {
  configurable: true,
  value: {
    ...window.location,
    replace: replaceMock,
    pathname: '/auth/two-factor',
    search: '',
    hash: '',
  },
})

const { default: TwoFactorLoginPage } = await import('@/features/auth/TwoFactorLoginPage')
const { LoginForm } = await import('@/features/auth/components/LoginForm')
const { default: TwoFactorCard } = await import('@/features/profile/TwoFactorCard')

function renderLogin(state: unknown) {
  return render(
    <MemoryRouter initialEntries={[{ pathname: '/auth/two-factor', state }]}>
      <Routes>
        <Route path="/auth/two-factor" element={<TwoFactorLoginPage />} />
        <Route path="/auth" element={<p>صفحه‌ی ورود</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

function renderCard() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <TwoFactorCard />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiMock.mockReset()
  replaceMock.mockReset()
})

describe('گامِ دومِ ورود (TOTP)', () => {
  it('بی گامِ رمز به صفحه‌ی ورود برمی‌گردد', () => {
    renderLogin(null)

    expect(screen.getByText('صفحه‌ی ورود')).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('کدِ شش‌رقمی را همان لحظه‌ی کامل‌شدن می‌فرستد و پس از ورود می‌رود', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ user: { id: 1 } })
    renderLogin({ fromPassword: true })

    await user.click(screen.getAllByRole('textbox')[0])
    await user.keyboard('123456')

    await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(1))
    expect(apiMock.mock.calls[0][0]).toBe('/login/two-factor')
    expect(apiMock.mock.calls[0][1].body).toEqual({ code: '123456' })
    await waitFor(() => expect(replaceMock).toHaveBeenCalledWith('/dashboard'))
  })

  it('کدِ بازیابی جای کدِ برنامه را می‌گیرد و خطای سرور نشان داده می‌شود', async () => {
    const user = userEvent.setup()
    apiMock.mockRejectedValue(
      new FakeApiError('خطا', 422, {
        recovery_code: ['کدِ بازیابی نادرست است یا پیش‌تر به کار رفته.'],
      }),
    )
    renderLogin({ fromPassword: true })

    await user.click(screen.getByRole('button', { name: /ورود با کد بازیابی/ }))
    await user.type(screen.getByPlaceholderText('xxxxx-xxxxx'), ' abcde-fghjk ')
    await user.click(screen.getByRole('button', { name: 'ورود با کد بازیابی' }))

    await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(1))
    expect(apiMock.mock.calls[0][1].body).toEqual({ recovery_code: 'abcde-fghjk' })
    expect(await screen.findByRole('alert')).toHaveTextContent('پیش‌تر به کار رفته')
    expect(replaceMock).not.toHaveBeenCalled()
  })
})

describe('فرمِ شماره', () => {
  it('سوپر ادمین با شماره و رمز به گامِ کدِ برنامه می‌رود، نه کدِ پیامکی (۲۰۲۶-۱۰-۲۹، بندِ ۲)', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ twoFactorRequired: true })
    render(
      <MemoryRouter initialEntries={['/auth']}>
        <Routes>
          <Route path="/auth" element={<LoginForm />} />
          <Route path="/auth/two-factor" element={<p>گام-کد-برنامه</p>} />
          <Route path="/auth/verify" element={<p>گام-پیامک</p>} />
        </Routes>
      </MemoryRouter>,
    )

    await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09120000001')
    await user.type(screen.getByPlaceholderText('رمز عبور خود را وارد کنید'), 'secret123')
    await user.click(screen.getByText('حل-پازل'))
    await user.click(screen.getByRole('button', { name: /ورود به پنل/ }))

    expect(await screen.findByText('گام-کد-برنامه')).toBeInTheDocument()
    expect(screen.queryByText('گام-پیامک')).toBeNull()
  })
})

describe('کارتِ Authenticator ِ سوپر ادمین', () => {
  it('راه‌اندازی: QR و کلید، تأیید با کد، و کدهای بازیابی فقط همان یک بار', async () => {
    const user = userEvent.setup()
    apiMock.mockImplementation((path: string) => {
      if (path === '/profile/two-factor')
        return Promise.resolve({
          enabled: false,
          setupRequired: true,
          confirmedAt: null,
          recoveryCodesRemaining: 0,
        })
      if (path === '/profile/two-factor/setup')
        return Promise.resolve({
          secret: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
          uri: 'otpauth://totp/Sakena:sakena.admin?secret=X',
        })
      if (path === '/profile/two-factor/confirm')
        return Promise.resolve({ recoveryCodes: ['aaaaa-bbbbb', 'ccccc-ddddd'] })
      return Promise.reject(new Error(path))
    })
    renderCard()

    expect(await screen.findByText(/بخش‌های مدیریتی بسته‌اند/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'راه‌اندازی' }))

    expect(await screen.findByRole('img', { name: /QR/ })).toBeInTheDocument()
    expect(screen.getByText('ABCD EFGH IJKL MNOP QRST UVWX YZ23 4567')).toBeInTheDocument()

    // دکمه تا شش رقم غیرفعال است
    const confirm = screen.getByRole('button', { name: /تأیید و فعال‌سازی/ })
    expect(confirm).toBeDisabled()
    await user.type(screen.getByLabelText(/کد شش‌رقمی/), '12a34567')
    expect(screen.getByLabelText(/کد شش‌رقمی/)).toHaveValue('123456')
    await user.click(confirm)

    await waitFor(() =>
      expect(apiMock).toHaveBeenCalledWith('/profile/two-factor/confirm', {
        method: 'POST',
        body: { code: '123456' },
      }),
    )
    expect(await screen.findByText('aaaaa-bbbbb')).toBeInTheDocument()
    // کلید دیگر روی صفحه نیست
    expect(screen.queryByText(/ABCD EFGH/)).toBeNull()

    await user.click(screen.getByRole('button', { name: 'جایی امن نگه داشتم' }))
    expect(screen.queryByText('aaaaa-bbbbb')).toBeNull()
  })

  it('فعال: تعویض و کدهای تازه هر دو کدِ فعلیِ برنامه می‌خواهند؛ دکمه‌ی غیرفعال‌سازی نیست', async () => {
    const user = userEvent.setup()
    apiMock.mockImplementation((path: string) => {
      if (path === '/profile/two-factor')
        return Promise.resolve({
          enabled: true,
          setupRequired: false,
          confirmedAt: '۱۴۰۵/۰۸/۰۳',
          recoveryCodesRemaining: 7,
        })
      if (path === '/profile/two-factor/recovery-codes')
        return Promise.resolve({ recoveryCodes: ['eeeee-fffff'] })
      return Promise.reject(new Error(path))
    })
    renderCard()

    expect(await screen.findByText(/۷ کدِ بازیابیِ باقی‌مانده/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /غیرفعال/ })).toBeNull()

    await user.click(screen.getByRole('button', { name: 'کدهای بازیابیِ تازه' }))
    await user.type(screen.getByLabelText(/کد شش‌رقمی/), '654321')
    await user.click(screen.getByRole('button', { name: 'ادامه' }))

    await waitFor(() =>
      expect(apiMock).toHaveBeenCalledWith('/profile/two-factor/recovery-codes', {
        method: 'POST',
        body: { code: '654321' },
      }),
    )
    expect(await screen.findByText('eeeee-fffff')).toBeInTheDocument()
  })
})
