import { act, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import axios from 'axios'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * جدول، نمودار، آپلود، اعلان و Error UX (مرحلهٔ ۵ · فاز ۶ · بندهای ۵۰ تا ۵۵).
 *
 * همه رفتاری‌اند: کلیک، رویداد و درخواستِ واقعیِ لایه‌ی API — نه «فلان
 * کامپوننت وارد شده؟».
 */

const request = vi.fn()

vi.mock('@/shared/lib/http', () => ({
  http: { request: (...args: unknown[]) => request(...args) },
  refreshCsrfToken: () => Promise.resolve(),
  setCsrfToken: vi.fn(),
  TIMEOUT_MS: 20_000,
  UPLOAD_TIMEOUT_MS: 120_000,
}))

const { ApiError } = await import('@/shared/lib/apiError')

// ⚠️ آکولاد: تابعی که beforeEach برگرداند، پس از تست به‌عنوانِ پاک‌سازی صدا زده می‌شود
beforeEach(() => {
  request.mockReset()
})

/* ── صفحه‌بند ──────────────────────────────────────────────────────────── */

describe('صفحه‌بند', () => {
  it('وقتی فقط یک صفحه هست چیزی رسم نمی‌کند', async () => {
    const { Pager } = await import('@/shared/ui/Pager')
    const { container } = render(
      <Pager meta={{ currentPage: 1, lastPage: 1, total: 5 }} onChange={vi.fn()} noun="واحد" />,
    )

    expect(container.innerHTML).toBe('')
  })

  it('قبلی و بعدی صفحه را عوض می‌کنند و در لبه‌ها خاموش‌اند', async () => {
    const { Pager } = await import('@/shared/ui/Pager')
    const onChange = vi.fn()
    render(
      <Pager meta={{ currentPage: 1, lastPage: 3, total: 50 }} onChange={onChange} noun="واحد" />,
    )

    expect(screen.getByRole('navigation', { name: 'صفحه‌بندی' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'قبلی' })).toHaveProperty('disabled', true)

    fireEvent.click(screen.getByRole('button', { name: 'بعدی' }))
    expect(onChange).toHaveBeenCalledWith(2)
  })

  /** 🔴 حذفِ آخرین ردیفِ صفحه‌ی آخر نباید «موردی نیست» نشان دهد. */
  it('صفحه‌ای که دیگر وجود ندارد به آخرین صفحه‌ی واقعی برمی‌گردد', async () => {
    const { Pager } = await import('@/shared/ui/Pager')
    const onChange = vi.fn()
    render(
      <Pager meta={{ currentPage: 4, lastPage: 3, total: 40 }} onChange={onChange} noun="واحد" />,
    )

    expect(onChange).toHaveBeenCalledWith(3)
  })
})

describe('صفحه‌ی واحدها با بیش از بیست واحد', () => {
  /*
   * 🔴 همان نقص: سرور بیست‌تا بیست‌تا می‌داد و صفحه فقط اولی را می‌خواست.
   * این تست با درخواستِ واقعیِ لایه‌ی API می‌بیند که صفحه‌ی دوم **خواسته**
   * می‌شود.
   */
  it('بعدی صفحه‌ی دوم را از سرور می‌خواهد', async () => {
    request.mockImplementation((config: { url: string }) => {
      const page = Number(new URL(config.url, 'http://x').searchParams.get('page') ?? '1')
      return Promise.resolve({
        status: 200,
        data: {
          data: [
            {
              id: page,
              unitNumber: `U-${page}`,
              floor: 1,
              area: 80,
              residentsCount: 1,
              occupancyStatus: 'owner',
              occupancyLabel: '',
              balance: 0,
              buildingName: null,
              owner: null,
              tenant: null,
            },
          ],
          meta: { currentPage: page, lastPage: 2, perPage: 20, total: 25 },
        },
      })
    })

    const { UnitsPage } = await import('@/features/units/UnitsPage')
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <UnitsPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    fireEvent.click(await screen.findByRole('button', { name: 'بعدی' }))

    await waitFor(() =>
      expect(
        request.mock.calls.some(([config]) => (config as { url: string }).url.includes('page=2')),
      ).toBe(true),
    )
    expect(await screen.findByText('U-2')).toBeTruthy()
  })
})

/* ── Error UXِ صفحه ────────────────────────────────────────────────────── */

describe('خطای صفحه از نگاشتِ مرکزی', () => {
  async function renderError(error: unknown) {
    const { ErrorState } = await import('@/shared/ui/PageState')
    const retry = vi.fn()
    render(<ErrorState error={error} onRetry={retry} />)
    return retry
  }

  it('قطعیِ شبکه: راهنمای اینترنت و تلاشِ دوباره', async () => {
    await renderError(new ApiError('ارتباط با سرور برقرار نشد.', 0))

    expect(screen.getByText(/اتصالِ اینترنت/)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'تلاش دوباره' })).toBeTruthy()
  })

  /** 🔴 دسترسی نداشتن با تکرار درست نمی‌شود؛ دکمه‌ی تکرار گمراه‌کننده بود. */
  it('۴۰۳: بی دکمه‌ی تکرار', async () => {
    await renderError(new ApiError('به این بخش دسترسی ندارید.', 403))

    expect(screen.getByText('به این بخش دسترسی ندارید.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })

  it('۴۰۱: پیوندِ ورود، بی تکرار و بی ریدایرکتِ خودکار', async () => {
    await renderError(new ApiError('نشست تمام شده است.', 401))

    expect(screen.getByRole('link', { name: 'ورود دوباره' }).getAttribute('href')).toBe('/auth')
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })

  it('۵۰۰: تلاشِ دوباره هست و کار می‌کند', async () => {
    const retry = await renderError(new ApiError('خطای سرور.', 500))

    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    expect(retry).toHaveBeenCalledOnce()
  })

  /** ⚠️ باگِ خودِ کد پیامِ انگلیسیِ درونی دارد و به کاربر نمی‌رسد. */
  it('خطای غیرِ API پیامِ درونی را نشان نمی‌دهد', async () => {
    await renderError(new TypeError("Cannot read properties of undefined (reading 'map')"))

    expect(screen.queryByText(/Cannot read/)).toBeNull()
    expect(screen.getByText(/بارگذاری ممکن نشد/)).toBeTruthy()
  })
})

/* ── آپلود ─────────────────────────────────────────────────────────────── */

describe('سنجشِ فایل پیش از ارسال', () => {
  const file = (name: string, type: string, mb: number) =>
    new File([new Uint8Array(Math.round(mb * 1024 * 1024))], name, { type })

  it('عکسِ ۸ مگابایتیِ دوربین پیش از ارسال رد می‌شود', async () => {
    const { RECEIPT_EXTENSIONS, uploadProblem } = await import('@/shared/lib/upload')

    expect(uploadProblem(file('photo.jpg', 'image/jpeg', 8), RECEIPT_EXTENSIONS)).toMatch(
      /حداکثر ۴ مگابایت/,
    )
    expect(uploadProblem(file('photo.jpg', 'image/jpeg', 3), RECEIPT_EXTENSIONS)).toBeNull()
  })

  /** همان دو فهرستِ سرور: رسید webp نمی‌پذیرد، پیوست می‌پذیرد. */
  it('قالب‌ها با سرور یکی‌اند', async () => {
    const { ATTACHMENT_EXTENSIONS, RECEIPT_EXTENSIONS, uploadProblem } =
      await import('@/shared/lib/upload')
    const webp = file('shot.webp', 'image/webp', 1)

    expect(uploadProblem(webp, RECEIPT_EXTENSIONS)).not.toBeNull()
    expect(uploadProblem(webp, ATTACHMENT_EXTENSIONS)).toBeNull()
    expect(
      uploadProblem(file('run.exe', 'application/x-msdownload', 1), ATTACHMENT_EXTENSIONS),
    ).not.toBeNull()
    // مرورگری که نوع را خالی می‌دهد؛ پسوند کافی است
    expect(uploadProblem(file('scan.pdf', '', 1), RECEIPT_EXTENSIONS)).toBeNull()
  })
})

describe('پیشرفت و لغوِ آپلود', () => {
  it('پیشرفتِ واقعیِ لایه‌ی HTTP به نوار می‌رسد', async () => {
    let resolve: (value: unknown) => void = () => {}
    request.mockImplementation(
      (config: { onUploadProgress?: (e: { loaded: number; total: number }) => void }) => {
        config.onUploadProgress?.({ loaded: 30, total: 100 })
        return new Promise((r) => (resolve = r))
      },
    )

    const { api } = await import('@/shared/lib/api')
    const { useUpload } = await import('@/shared/hooks/useUpload')
    const { result } = renderHook(() => useUpload())

    let pending: Promise<unknown> = Promise.resolve()
    act(() => {
      pending = result.current.send((options) =>
        api('/x', { method: 'POST', body: new FormData(), ...options }),
      )
    })

    await waitFor(() => expect(result.current.progress).toBeCloseTo(0.3))

    await act(async () => {
      resolve({ status: 200, data: { ok: true } })
      await pending
    })
    expect(result.current.progress).toBeNull()
  })

  it('لغو درخواست را واقعاً قطع می‌کند و «لغوِ کاربر» شناخته می‌شود', async () => {
    request.mockImplementation(
      (config: { signal: AbortSignal }) =>
        new Promise((_, reject) => {
          config.signal.addEventListener('abort', () => reject(new axios.CanceledError()))
        }),
    )

    const { api } = await import('@/shared/lib/api')
    const { useUpload, isUploadCancelled } = await import('@/shared/hooks/useUpload')
    const { result } = renderHook(() => useUpload())

    let outcome: unknown = null
    let pending: Promise<unknown> = Promise.resolve()
    act(() => {
      pending = result.current
        .send((options) => api('/x', { method: 'POST', body: new FormData(), ...options }))
        .catch((error: unknown) => (outcome = error))
    })

    await act(async () => {
      result.current.cancel()
      await pending
    })

    expect(isUploadCancelled(outcome)).toBe(true)
    expect(result.current.progress).toBeNull()
  })
})

/* ── نمودار ────────────────────────────────────────────────────────────── */

describe('نمودارها', () => {
  /*
   * ⚠️ Recharts کتابخانه‌ی سنگینی است؛ بارِ اولش بیش از پنج ثانیه طول کشید
   * و اولین تست را از timeout گذراند. همان درسِ `keyboardReach`: بارگذاری
   * جدا و با سقفِ خودش.
   */
  beforeAll(async () => {
    await import('@/features/dashboard/components/TrendChart')
    await import('@/features/dashboard/components/PaymentStatusChart')
  }, 60_000)

  it('روندِ تماماً صفر به‌جای دو خطِ صاف پیامِ «خالی» می‌دهد', async () => {
    const { TrendChart } = await import('@/features/dashboard/components/TrendChart')
    render(<TrendChart data={[{ label: 'مهر', income: 0, expense: 0 }]} currency="تومان" />)

    expect(screen.getByText(/هنوز درآمد یا هزینه‌ای/)).toBeTruthy()
  })

  it('عددهای روند برای صفحه‌خوان هم نوشته شده‌اند', async () => {
    const { TrendChart } = await import('@/features/dashboard/components/TrendChart')
    const { container } = render(
      <TrendChart data={[{ label: 'مهر', income: 1500000, expense: 200000 }]} currency="تومان" />,
    )

    const caption = container.querySelector('figcaption')?.textContent ?? ''
    expect(caption).toContain('مهر')
    expect(caption).toContain('۱,۵۰۰,۰۰۰')
  })

  it('وضعیتِ قبض‌ها برای صفحه‌خوان هم نوشته شده است', async () => {
    const { PaymentStatusChart } =
      await import('@/features/dashboard/components/PaymentStatusChart')
    const { container } = render(
      <PaymentStatusChart counts={{ paid: 3, partial: 0, pending: 1, unpaid: 2 }} />,
    )

    const caption = container.querySelector('figcaption')?.textContent ?? ''
    expect(caption).toContain('تسویه‌شده ۳')
    expect(caption).toContain('پرداخت‌نشده ۲')
  })
})

/* ── اعلان ─────────────────────────────────────────────────────────────── */

describe('اعلان‌ها', () => {
  /** پس از قطعی یا بازگشت به تب، تا شصت ثانیه عددِ کهنه نمی‌ماند. */
  it('وصل‌شدنِ دوباره همان لحظه تازه می‌کند', async () => {
    vi.resetModules()
    request.mockResolvedValue({ status: 200, data: { unreadCount: 2, items: [] } })

    const { useNotifications } = await import('@/shared/stores/notificationStore')
    renderHook(() => useNotifications())

    await waitFor(() => expect(request).toHaveBeenCalledTimes(1))

    act(() => {
      window.dispatchEvent(new Event('online'))
    })

    await waitFor(() => expect(request).toHaveBeenCalledTimes(2))
  })
})
