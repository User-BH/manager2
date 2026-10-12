import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * QR ِ ورودِ عمومی و ثبت‌نام از پیوندِ دعوت در رابط (مرحلهٔ ۱۰ · فاز ۵؛ بندهای ۲۲، ۲۳، ۲۵ و ۹۰).
 *
 * قاعده‌ی قطعی در سرور است (`PublicJoinTest`، `DirectInvitationRoutingTest`)؛ اینجا سنجیده می‌شود که رابط
 * چه می‌فرستد و چه نشان می‌دهد: اسکن عضویت نیست و درخواست فقط با کلیکِ خودِ کاربر می‌رود، کد از نشانی پاک
 * می‌شود، توکنِ دعوت فقط وقتی هست همراهِ ثبت‌نام می‌رود، و مدیر دو مسیر را جدا می‌بیند.
 */

const calls = vi.hoisted(() => ({
  list: [] as { path: string; options?: { method?: string; body?: unknown } }[],
}))
const routes = vi.hoisted((): { map: Record<string, unknown> } => ({ map: {} }))
const toastTopSuccess = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', () => {
  class ApiError extends Error {
    status = 422
    errors: Record<string, string[]> = {}
    fieldError(field: string) {
      return this.errors[field]?.[0]
    }
  }
  return {
    ApiError,
    setCsrfToken: vi.fn(),
    api: vi.fn((path: string, options?: { method?: string; body?: unknown }) => {
      calls.list.push({ path, options })
      const key = `${options?.method ?? 'GET'} ${path}`
      const value = key in routes.map ? routes.map[key] : routes.map[path]
      if (value instanceof Error) return Promise.reject(value)
      return Promise.resolve(value ?? {})
    }),
  }
})
vi.mock('@/shared/lib/alert', () => ({
  toastTopSuccess,
  toastTopError: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  alertError: vi.fn(),
  confirmAction: vi.fn(() => Promise.resolve(true)),
}))

const { JoinPage } = await import('@/features/auth/JoinPage')
const { afterSignIn, joinCode, takeJoinCodeFromUrl } = await import('@/features/auth/joinCode')
const { JoinQrPanel } = await import('@/features/residents/JoinQrPanel')
const { RegisterForm } = await import('@/features/auth/components/RegisterForm')
const { ApiError } = await import('@/shared/lib/api')

const CODE = 'Ab9_-xYzAb9_-xYzAb9_-x'

function wrap(node: React.ReactNode, path = '/') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>{node}</MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  calls.list = []
  routes.map = {}
  toastTopSuccess.mockReset()
  sessionStorage.clear()
  window.history.replaceState(null, '', '/')
})

describe('کدِ QR در نشانی', () => {
  it('از # برداشته، از نشانی پاک و تا پس از ورود نگه داشته می‌شود', () => {
    window.history.replaceState(null, '', `/join#${CODE}`)

    takeJoinCodeFromUrl()

    expect(window.location.href).not.toContain(CODE)
    expect(joinCode()).toBe(CODE)
    // ورود ناوبریِ کامل است؛ پس از آن به صفحه‌ی پیوستن برمی‌گردد
    expect(afterSignIn()).toBe('/join')
  })

  it('کدِ بدشکل نگه داشته نمی‌شود و ورود به داشبورد می‌رود', () => {
    window.history.replaceState(null, '', '/join#not-a-code')
    takeJoinCodeFromUrl()

    expect(joinCode()).toBe('')
    expect(afterSignIn()).toBe('/dashboard')
  })

  it('نقطه‌ی ورود کد را پیش از پایش و تحلیل برمی‌دارد', () => {
    const entry = readFileSync(
      resolve(__dirname, '../../resources/js/app/entries/auth.tsx'),
      'utf8',
    )
    const take = entry.indexOf('takeJoinCodeFromUrl()')
    expect(take).toBeGreaterThan(-1)
    expect(take).toBeLessThan(entry.indexOf('initObservability()'))
  })

  it('هر چهار مسیرِ پایانِ ورود از همان تصمیم می‌گذرند', () => {
    for (const file of [
      'components/LoginForm.tsx',
      'VerifyOtpPage.tsx',
      'AuthPage.tsx',
      'ForgotPasswordPage.tsx',
    ]) {
      const code = readFileSync(
        resolve(__dirname, `../../resources/js/features/auth/${file}`),
        'utf8',
      )
      expect(code, file).toContain('afterSignIn()')
      expect(code, file).not.toContain("window.location.replace('/dashboard')")
    }
  })
})

describe('صفحه‌ی پیوستن', () => {
  it('برای مهمان: نامِ مجتمع، ثبت‌نام و ورود — و هیچ درخواستی', async () => {
    sessionStorage.setItem('sakena.joinCode', CODE)
    routes.map = { 'POST /join-link/preview': { complexName: 'مجتمع QR' }, '/me': { user: null } }

    wrap(<JoinPage />, '/join')

    expect(await screen.findByText('مجتمع QR')).toBeTruthy()
    expect(screen.getByText(/اسکنِ QR به‌تنهایی عضویت نیست/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'ثبت‌نام' }).getAttribute('href')).toBe(
      '/auth?tab=register',
    )
    expect(screen.getByRole('link', { name: 'حساب دارم؛ ورود' }).getAttribute('href')).toBe('/auth')
    expect(screen.queryByRole('button', { name: 'ارسالِ درخواستِ عضویت' })).toBeNull()

    // کد در بدنه، نه در مسیر
    const preview = calls.list.find((c) => c.path === '/join-link/preview')
    expect(preview?.options).toMatchObject({ method: 'POST', body: { code: CODE } })
    expect(calls.list.some((c) => c.path === '/join-requests')).toBe(false)
  })

  it('برای کاربرِ واردشده: درخواست فقط با کلیکِ خودش می‌رود و کد فراموش می‌شود', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem('sakena.joinCode', CODE)
    routes.map = {
      'POST /join-link/preview': { complexName: 'مجتمع QR' },
      '/me': { user: { id: 7 } },
      'POST /join-requests': { message: 'درخواست شما برای مدیر «مجتمع QR» ارسال شد.' },
    }

    wrap(<JoinPage />, '/join')
    const send = await screen.findByRole('button', { name: 'ارسالِ درخواستِ عضویت' })
    expect(calls.list.some((c) => c.path === '/join-requests')).toBe(false)

    await user.click(send)

    expect(await screen.findByText('درخواست فرستاده شد')).toBeTruthy()
    expect(screen.getByText(/تا تأییدِ مدیر عضوِ مجتمع نیستید/)).toBeTruthy()
    expect(calls.list.find((c) => c.path === '/join-requests')?.options).toMatchObject({
      method: 'POST',
      body: { code: CODE },
    })
    expect(sessionStorage.getItem('sakena.joinCode')).toBeNull()
  })

  it('خطای سرور (مثلاً «از قبل عضوید») نشان داده می‌شود', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem('sakena.joinCode', CODE)
    routes.map = {
      'POST /join-link/preview': { complexName: 'مجتمع QR' },
      '/me': { user: { id: 7 } },
      'POST /join-requests': new ApiError('شما از قبل عضوِ همین مجتمع هستید.', 422),
    }

    wrap(<JoinPage />, '/join')
    await user.click(await screen.findByRole('button', { name: 'ارسالِ درخواستِ عضویت' }))

    expect((await screen.findByRole('alert')).textContent).toContain('از قبل عضوِ همین مجتمع')
  })

  it('QR ِ باطل یا عوض‌شده پیامِ روشن می‌دهد و کد فراموش می‌شود', async () => {
    sessionStorage.setItem('sakena.joinCode', CODE)
    routes.map = { 'POST /join-link/preview': new Error('404'), '/me': { user: null } }

    wrap(<JoinPage />, '/join')

    expect(await screen.findByText('این QR معتبر نیست')).toBeTruthy()
    expect(sessionStorage.getItem('sakena.joinCode')).toBeNull()
  })
})

describe('QR در رابطِ مدیر', () => {
  it('دو مسیر کنارِ هم و جدا گفته می‌شوند (بندِ ۲۵)', async () => {
    routes.map = { '/join-link': { link: null, createdAt: null } }

    wrap(<JoinQrPanel />)

    const qr = await screen.findByRole('region', { name: 'QR ِ عمومی' })
    const direct = screen.getByRole('region', { name: 'دعوتِ مستقیم' })
    expect(qr.textContent).toContain('تأییدِ شما')
    expect(qr.textContent).toContain('اسکن به‌تنهایی عضویت نیست')
    expect(direct.textContent).toContain('عضویتِ مستقیم')
    expect(direct.textContent).toContain('بی تأییدِ دوباره')
  })

  it('خاموش: «ساختِ QR» همان POST است', async () => {
    const user = userEvent.setup()
    routes.map = { '/join-link': { link: null, createdAt: null } }

    wrap(<JoinQrPanel />)
    await user.click(await screen.findByRole('button', { name: 'ساختِ QR ِ عمومی' }))

    await waitFor(() =>
      expect(calls.list.some((c) => c.path === '/join-link' && c.options?.method === 'POST')).toBe(
        true,
      ),
    )
  })

  it('روشن: QR ِ همان پیوند، و «QR ِ تازه» با تأیید به rotate می‌رود', async () => {
    const user = userEvent.setup()
    const link = `https://sakena.ir/join#${CODE}`
    routes.map = { '/join-link': { link, createdAt: null } }

    wrap(<JoinQrPanel />)

    const svg = await screen.findByRole('img', { name: 'QR ِ ورودِ عمومیِ مجتمع' })
    // ۴۵ بایت ← نسخه‌ی ۴ (۳۳ ماژول) با حاشیه‌ی آرامِ ۴ از هر سو
    expect(svg.getAttribute('viewBox')).toBe('0 0 41 41')
    expect(screen.getByLabelText<HTMLInputElement>('پیوندِ QR').value).toBe(link)

    await user.click(screen.getByRole('button', { name: 'QR ِ تازه' }))
    await waitFor(() =>
      expect(
        calls.list.some((c) => c.path === '/join-link/rotate' && c.options?.method === 'POST'),
      ).toBe(true),
    )
  })
})

describe('ثبت‌نام از پیوندِ دعوت (بندِ ۹۰)', () => {
  async function registerThroughForm(user: ReturnType<typeof userEvent.setup>) {
    render(
      <MemoryRouter>
        <RegisterForm onRegistered={vi.fn()} />
      </MemoryRouter>,
    )
    routes.map = {
      'POST /register': { otpRequired: true, phone: '09123456789', dev_code: '123456' },
    }
    await user.type(screen.getByPlaceholderText('مثلاً علی'), 'علی')
    await user.type(screen.getByPlaceholderText('مثلاً محمدی'), 'محمدی')
    await user.type(screen.getByPlaceholderText('۰۹xxxxxxxxx'), '09123456789')
    await user.type(screen.getByPlaceholderText('حداقل ۸ نویسه'), 'Secret123')
    await user.type(screen.getByPlaceholderText('تکرار رمز عبور'), 'Secret123')
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: /دریافت کد تایید/ }))
    await screen.findByText(/کد شش‌رقمی به شماره/)
  }

  it('بی پیوندِ دعوت فقط کد می‌رود — ثبت‌نامِ عادی', async () => {
    const user = userEvent.setup()

    await registerThroughForm(user)
    routes.map['POST /register/verify'] = { message: 'ok', joined: null }
    const boxes = screen.getAllByRole('textbox')
    for (let i = 0; i < 6; i++) await user.type(boxes[i], String(i + 1))

    await waitFor(() => expect(toastTopSuccess).toHaveBeenCalled())
    expect(calls.list.find((c) => c.path === '/register/verify')?.options?.body).toEqual({
      code: '123456',
    })
    expect(toastTopSuccess.mock.calls[0][0]).not.toContain('عضوِ')
  })

  it('توکنِ پیوند همراهِ کد می‌رود و پیامِ «عضو شدید» می‌آید', async () => {
    const user = userEvent.setup()
    const { takeInviteTokenFromUrl } = await import('@/features/auth/inviteToken')
    window.history.replaceState(null, '', '/invite#invite-token-789')
    takeInviteTokenFromUrl()

    await registerThroughForm(user)
    routes.map['POST /register/verify'] = { message: 'ok', joined: 'مجتمع مستقیم' }
    const boxes = screen.getAllByRole('textbox')
    for (let i = 0; i < 6; i++) await user.type(boxes[i], String(i + 1))

    await waitFor(() => expect(toastTopSuccess).toHaveBeenCalled())
    expect(calls.list.find((c) => c.path === '/register/verify')?.options).toMatchObject({
      body: { code: '123456', invitation_token: 'invite-token-789' },
    })
    expect(toastTopSuccess.mock.calls[0][0]).toContain('عضوِ «مجتمع مستقیم» شدید')
  })
})
