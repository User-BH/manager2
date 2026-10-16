import { act, fireEvent, render as rtlRender, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactElement } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { JoinByManagerPhone } from '@/features/registrant/JoinByManagerPhone'

/**
 * درخواستِ عضویت با شماره‌ی مدیر — مرحلهٔ ۱۲ · فاز ۳ (بندِ ۱۲).
 *
 * سرور مجتمع‌ها را از عضویتِ **فعالِ** مدیر می‌خواند (`JoinRequestController::managedByPhone`). مدیرِ چند مجتمع:
 * کاربر خودش یکی را برمی‌گزیند و دکمه تا آن موقع بسته است؛ درخواست به مجتمعِ حدسی نمی‌رود. قاعده‌ی سرور:
 * `MembershipPathsTest`.
 */

const apiMock = vi.fn()
vi.mock('@/shared/lib/api', () => ({
  api: (...args: unknown[]) => apiMock(...args),
  ApiError: class extends Error {
    errors: Record<string, string[]> = {}
  },
}))

function render(ui: ReactElement) {
  return rtlRender(<QueryClientProvider client={new QueryClient()}>{ui}</QueryClientProvider>)
}

const TWO = {
  found: true,
  complexName: null,
  complexes: [
    { id: 4, name: 'مجتمع آفتاب' },
    { id: 9, name: 'مجتمع مهتاب' },
  ],
}

async function typePhone(phone: string) {
  fireEvent.change(screen.getByLabelText('شماره موبایل مدیر مجتمع'), { target: { value: phone } })
  // مکثِ پیش از پرسش (۴۰۰ میلی‌ثانیه)
  await act(async () => {
    await vi.advanceTimersByTimeAsync(450)
  })
}

const sendButton = () => screen.getByRole('button', { name: /ارسال درخواست/ })

describe('درخواستِ عضویت با شماره‌ی مدیر', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    apiMock.mockReset()
  })
  afterEach(() => vi.useRealTimers())

  it('یک مجتمع: نامش نشان داده می‌شود و درخواست بی شناسه‌ی مجتمع می‌رود', async () => {
    apiMock.mockImplementation((url: string) =>
      Promise.resolve(
        url.startsWith('/join-requests/lookup')
          ? { found: true, complexName: 'مجتمع آفتاب', complexes: [{ id: 4, name: 'مجتمع آفتاب' }] }
          : {},
      ),
    )
    render(<JoinByManagerPhone />)

    await typePhone('09120001111')
    expect(screen.getByText('مجتمع آفتاب')).toBeTruthy()
    expect(screen.queryByRole('radio')).toBeNull()

    await act(async () => {
      fireEvent.click(sendButton())
      // درخواستِ ارسال یک ریزکار است؛ پیش از سنجش تمام شود
      await Promise.resolve()
    })
    expect(apiMock).toHaveBeenLastCalledWith('/join-requests', {
      method: 'POST',
      body: { phone: '09120001111' },
    })
  })

  it('مدیرِ دو مجتمع: تا انتخاب دکمه بسته است و مجتمعِ برگزیده فرستاده می‌شود', async () => {
    apiMock.mockImplementation((url: string) =>
      Promise.resolve(url.startsWith('/join-requests/lookup') ? TWO : {}),
    )
    render(<JoinByManagerPhone />)

    await typePhone('09120001111')
    expect(screen.getByText(/مدیر چند مجتمع است/)).toBeTruthy()
    expect(screen.getAllByRole('radio')).toHaveLength(2)
    expect((sendButton() as HTMLButtonElement).disabled).toBe(true)

    fireEvent.click(screen.getByRole('radio', { name: /مجتمع مهتاب/ }))
    expect((sendButton() as HTMLButtonElement).disabled).toBe(false)

    await act(async () => {
      fireEvent.click(sendButton())
      // درخواستِ ارسال یک ریزکار است؛ پیش از سنجش تمام شود
      await Promise.resolve()
    })
    expect(apiMock).toHaveBeenLastCalledWith('/join-requests', {
      method: 'POST',
      body: { phone: '09120001111', complex_id: 9 },
    })
  })

  it('انتخاب به همان شماره بسته است: شماره‌ی تازه انتخابِ قبلی را نمی‌برد', async () => {
    apiMock.mockImplementation((url: string) =>
      Promise.resolve(url.startsWith('/join-requests/lookup') ? TWO : {}),
    )
    render(<JoinByManagerPhone />)

    await typePhone('09120001111')
    fireEvent.click(screen.getByRole('radio', { name: /مجتمع مهتاب/ }))

    await typePhone('09120002222')
    expect((sendButton() as HTMLButtonElement).disabled).toBe(true)
    expect(
      screen.getAllByRole('radio').every((radio) => !(radio as HTMLInputElement).checked),
    ).toBe(true)
  })
})
