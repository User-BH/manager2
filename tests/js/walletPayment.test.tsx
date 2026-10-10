import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/shared/lib/apiError'
import { PayBillPage } from '@/features/payments/pay/PayBillPage'

/**
 * پرداخت از کیف و تکرارِ درخواست (مرحلهٔ ۹ · تصمیمِ کارفرما: «duplicate request و
 * retry تراکنشِ دوباره نسازند»).
 *
 * یک قصدِ کاربر = یک `Idempotency-Key`. اگر پاسخ به خاطرِ قطعیِ شبکه نرسید و کاربر
 * دوباره زد، **همان** کلید می‌رود تا سرور دوباره برندارد؛ پس از پاسخِ قطعیِ سرور
 * (موفق یا رد)، پرداختِ بعدی کلیدِ تازه می‌گیرد.
 */

const calls = vi.hoisted(() => ({
  list: [] as {
    path: string
    options?: { method?: string; body?: { idempotency_key?: string } }
  }[],
}))
const outcomes = vi.hoisted(() => ({ queue: [] as (Error | object)[] }))

vi.mock('@/shared/lib/api', async () => {
  const { ApiError: RealApiError } = await import('@/shared/lib/apiError')
  return {
    ApiError: RealApiError,
    api: vi.fn(
      (path: string, options?: { method?: string; body?: { idempotency_key?: string } }) => {
        if (!options?.method) {
          return Promise.resolve({
            bill: {
              id: 5,
              unitLabel: 'واحد ۵',
              periodLabel: 'مهر',
              totalAmount: 100000,
              paidAmount: 0,
              remaining: 100000,
              statusLabel: 'پرداخت‌نشده',
              dueDate: null,
            },
            currency: 'تومان',
            onlineEnabled: false,
            onlineAction: '',
            card: null,
            walletBalance: 300000,
          })
        }
        calls.list.push({ path, options })
        const next = outcomes.queue.shift() ?? { message: 'پرداخت شد.' }
        return next instanceof Error ? Promise.reject(next) : Promise.resolve(next)
      },
    ),
  }
})
vi.mock('@/shared/lib/alert', () => ({
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  alertError: vi.fn(),
  confirmAction: vi.fn(() => Promise.resolve(true)),
}))

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/pay/5']}>
        <Routes>
          <Route path="/pay/:billId" element={<PayBillPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function clickWalletPay() {
  const button = await screen.findByRole('button', { name: /^پرداخت\s+[۰-۹0-9]/ })
  await userEvent.click(button)
  await waitFor(() => expect(button).not.toBeDisabled())
}

beforeEach(() => {
  calls.list = []
  outcomes.queue = []
})

describe('پرداخت از کیف و تکرارِ درخواست', () => {
  it('پس از قطعیِ شبکه، تلاشِ دوباره همان کلید را می‌فرستد', async () => {
    outcomes.queue = [new ApiError('ارتباط با سرور برقرار نشد.', 0), { message: 'پرداخت شد.' }]
    renderPage()

    await clickWalletPay()
    await clickWalletPay()

    const [first, second] = calls.list.map((call) => call.options?.body?.idempotency_key)
    expect(first).toMatch(/^[A-Za-z0-9_-]{8,64}$/)
    expect(second).toBe(first)
  })

  it('پس از پاسخِ قطعیِ سرور، پرداختِ بعدی کلیدِ تازه می‌گیرد', async () => {
    outcomes.queue = [new ApiError('این قبض بدهی باز ندارد.', 422), { message: 'پرداخت شد.' }]
    renderPage()

    await clickWalletPay()
    await clickWalletPay()

    const [first, second] = calls.list.map((call) => call.options?.body?.idempotency_key)
    expect(first).toBeTruthy()
    expect(second).toBeTruthy()
    expect(second).not.toBe(first)
  })
})
