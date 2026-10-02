import { readFileSync } from 'node:fs'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * صفحه‌ی پشتیبانی (مرحلهٔ ۷ · فاز ۴): فرمِ بازخورد، چرخ، محتوا و صفِ ادمین.
 *
 * بخشِ سرورش در `tests/Feature/FeedbackTest.php` است و CSRF در آزمونِ مرورگر.
 */

const apiMock = vi.fn()

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
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  alertError: vi.fn(),
  confirmAction: vi.fn(() => Promise.resolve(true)),
}))

const { FeedbackSection } = await import('@/features/landing/support/FeedbackSection')
const { SupportWheel } = await import('@/features/landing/support/SupportWheel')
const { SupportAnswer, emphasize } = await import('@/features/landing/support/SupportAnswer')
const { supportTopics } = await import('@/features/landing/support/supportContent')
const { DEFAULT_SITE_CONTENT } = await import('@/shared/hooks/useSiteContent')
const { FeedbackInboxPage } = await import('@/features/system/FeedbackInboxPage')

beforeEach(() => {
  apiMock.mockReset()
})

async function fillValid(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByText('گزارشِ مشکل', { exact: true }))
  await user.type(screen.getByLabelText('متنِ پیام'), 'دکمه‌ی صدور قبض گاهی جواب نمی‌دهد.')
}

// ─── فرمِ بازخورد ──────────────────────────────────────────────────────────

describe('فرمِ بازخورد', () => {
  it('فرمِ خالی به سرور نمی‌رود و خطای هر فیلد را زیرِ خودش می‌گوید', async () => {
    const user = userEvent.setup()
    render(<FeedbackSection />)

    await user.click(screen.getByRole('button', { name: 'ارسالِ پیام' }))

    expect(await screen.findByText('نوعِ پیام را انتخاب کنید.')).toBeInTheDocument()
    expect(screen.getByText('متنِ پیام دستِ‌کم ۱۰ نویسه باشد.')).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('شماره‌ی نامعتبر و پیوندِ زیاد پیش از ارسال گرفته می‌شوند', async () => {
    const user = userEvent.setup()
    render(<FeedbackSection />)

    await user.click(screen.getByText('بازخورد', { exact: true }))
    await user.type(screen.getByLabelText('متنِ پیام'), 'http://a.ir و http://b.ir و www.c.ir')
    await user.type(screen.getByLabelText('شماره موبایل برای پاسخ (اختیاری)'), '0212')
    await user.click(screen.getByRole('button', { name: 'ارسالِ پیام' }))

    expect(await screen.findByText('حداکثر ۲ پیوند در پیام مجاز است.')).toBeInTheDocument()
    expect(screen.getByText('شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.')).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('ارسالِ درست، رقم‌های لاتین می‌فرستد و حالتِ موفق را نشان می‌دهد', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ message: 'پیامِ شما رسید. ممنون که وقت گذاشتید.' })
    render(<FeedbackSection />)

    await fillValid(user)
    await user.type(screen.getByLabelText('شماره موبایل برای پاسخ (اختیاری)'), '۰۹۱۲۱۲۳۴۵۶۷')
    await user.click(screen.getByRole('button', { name: 'ارسالِ پیام' }))

    expect(await screen.findByRole('status')).toHaveTextContent('پیامِ شما رسید')
    expect(apiMock).toHaveBeenCalledWith('/feedback', {
      method: 'POST',
      body: expect.objectContaining({ category: 'bug', phone: '09121234567', website: '' }),
    })
  })

  it('در حالِ ارسال، دکمه بسته است — دوبار-کلیک یک درخواست', async () => {
    const user = userEvent.setup()
    let release: (value: unknown) => void = () => {}
    apiMock.mockReturnValue(new Promise((resolve) => (release = resolve)))
    render(<FeedbackSection />)

    await fillValid(user)
    const button = screen.getByRole('button', { name: 'ارسالِ پیام' })
    await user.click(button)

    expect(await screen.findByRole('button', { name: 'در حالِ ارسال…' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'در حالِ ارسال…' }))
    expect(apiMock).toHaveBeenCalledTimes(1)

    release({ message: 'رسید' })
  })

  it('خطای فیلدیِ سرور زیرِ همان فیلد می‌نشیند', async () => {
    const user = userEvent.setup()
    apiMock.mockRejectedValue(
      new FakeApiError('داده نامعتبر', 422, { message: ['حداکثر ۲ پیوند در پیام مجاز است.'] }),
    )
    render(<FeedbackSection />)

    await fillValid(user)
    await user.click(screen.getByRole('button', { name: 'ارسالِ پیام' }))

    const field = screen.getByLabelText('متنِ پیام')
    await waitFor(() => expect(field).toHaveAttribute('aria-invalid', 'true'))
    expect(screen.getByText('حداکثر ۲ پیوند در پیام مجاز است.')).toBeInTheDocument()
  })

  it('سقفِ نرخ پیامِ روشن می‌دهد، نه خطای خام', async () => {
    const user = userEvent.setup()
    apiMock.mockRejectedValue(new FakeApiError('Too Many Attempts.', 429))
    render(<FeedbackSection />)

    await fillValid(user)
    await user.click(screen.getByRole('button', { name: 'ارسالِ پیام' }))

    expect(await screen.findByText(/چند دقیقه‌ی دیگر دوباره امتحان کنید/)).toBeInTheDocument()
  })

  it('تله‌ی ربات برای انسان نادیدنی و بیرون از ترتیبِ Tab است', () => {
    const { container } = render(<FeedbackSection />)
    const trap = container.querySelector('input[name="website"]')!

    expect(trap.getAttribute('tabindex')).toBe('-1')
    expect(trap.closest('[aria-hidden="true"]')).not.toBeNull()
    // 🔴 `left: -10000px` در صفحه‌ی RTL اسکرولِ افقیِ ‎۱۰٬۰۰۰ پیکسلی می‌ساخت
    expect(trap.closest('.sr-only')).not.toBeNull()
  })
})

// ─── چرخ ───────────────────────────────────────────────────────────────────

describe('چرخِ پشتیبانی', () => {
  it('Enter و Space ربع را انتخاب می‌کنند', () => {
    const onSelect = vi.fn()
    render(<SupportWheel topics={supportTopics} activeId={null} onSelect={onSelect} />)
    const quarter = screen.getByRole('button', { name: new RegExp(supportTopics[2].title) })

    fireEvent.keyDown(quarter, { key: 'Enter' })
    fireEvent.keyDown(quarter, { key: ' ' })

    expect(onSelect).toHaveBeenCalledTimes(2)
    expect(onSelect).toHaveBeenCalledWith(supportTopics[2].id)
  })

  it('کلیک حاشیه‌ی مشکیِ مرورگر نمی‌گذارد', () => {
    render(<SupportWheel topics={supportTopics} activeId={null} onSelect={vi.fn()} />)

    for (const topic of supportTopics) {
      expect(screen.getByRole('button', { name: new RegExp(topic.title) })).toHaveClass(
        'outline-none',
      )
    }
  })
})

// ─── محتوا ─────────────────────────────────────────────────────────────────

describe('محتوای پشتیبانی', () => {
  const all = supportTopics.flatMap((topic) =>
    topic.entries.map((e) => `${e.question} ${e.answer}`),
  )

  /*
   * ⚠️ هر ادعا با کد سنجیده شد (STAGE7_ROADMAP). این‌ها همان ادعاهای نادرستِ
   * پیشین‌اند که نباید برگردند.
   */
  it('راهِ تماسی که وجود ندارد معرفی نمی‌شود', () => {
    const text = all.join(' ')

    expect(text).not.toMatch(/ایمیل[^ ]* (با ما|پشتیبانی)|از طریق ایمیل/)
    expect(text).not.toMatch(/ساعت ۹ تا ۱۷|ساعاتِ پاسخ/)
    // کیف را ساکن مستقیم شارژ نمی‌کند؛ موجودی از مازاد می‌آید
    expect(text).not.toMatch(/ساکن (می‌تواند )?(آن را )?شارژ(ش)? (می‌کند|کند)/)
  })

  it('بخشِ تماس، تلفن و شبکه‌ها را از تنظیماتِ سایت نشان می‌دهد', () => {
    const contact = supportTopics.find((topic) => topic.id === 'contact')!
    const channels = contact.entries.map((e) => e.channel).filter(Boolean)

    expect(channels).toEqual(['phone', 'social', 'feedback'])

    const phoneEntry = contact.entries.find((e) => e.channel === 'phone')!
    render(<SupportAnswer entry={phoneEntry} site={DEFAULT_SITE_CONTENT} onFeedback={vi.fn()} />)
    expect(screen.getByRole('link', { name: /۰۲۱/ })).toHaveAttribute('href', 'tel:02188776655')
  })

  it('«**پررنگ**» پررنگ می‌شود، نه ستاره — و هرگز HTML ساخته نمی‌شود', () => {
    const { container } = render(
      <p>{emphasize('بستن **با ساکن** است <img src=x onerror=alert(1)>')}</p>,
    )

    expect(container.textContent).not.toContain('**')
    expect(container.querySelector('strong')?.textContent).toBe('با ساکن')
    expect(container.querySelector('img')).toBeNull()
  })

  it('FAQِ گوگل از همان فایلی می‌آید که صفحه نشان می‌دهد', () => {
    const blade = readFileSync('resources/views/public/support.blade.php', 'utf8')

    expect(blade).toContain("resource_path('content/support-topics.json')")
    // 🔴 پیش از این پنج پرسشِ جدا اینجا نوشته شده بود که روی صفحه نبودند
    expect(blade).not.toContain('هزینه استفاده از ساکنا چقدر است؟')
  })
})

// ─── صفِ ادمین ─────────────────────────────────────────────────────────────

describe('صفِ بازخوردها', () => {
  it('متنِ پیام متن رندر می‌شود، نه HTML', async () => {
    apiMock.mockResolvedValue({
      data: [
        {
          id: 1,
          category: 'feedback',
          categoryLabel: 'بازخورد',
          message: '<img src=x onerror="window.__xss=1"> متن',
          name: null,
          phone: '09121234567',
          status: 'new',
          statusLabel: 'تازه',
          createdAt: '2026-10-02T10:00:00+03:30',
        },
      ],
      meta: { currentPage: 1, lastPage: 1, total: 1 },
      counts: { new: 1 },
    })
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const { container } = render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <FeedbackInboxPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText(/onerror/)).toBeInTheDocument()
    expect(container.querySelector('li img')).toBeNull()
    expect(screen.getByRole('link', { name: /09121234567/ })).toHaveAttribute(
      'href',
      'tel:09121234567',
    )
  })
})
