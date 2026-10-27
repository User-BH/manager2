import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AssistantDock } from '@/features/assistant/AssistantDock'
import type { AssistantAnswer, AssistantHistory } from '@/features/assistant/types'

/**
 * دستیارِ داخلی در رابط (۱۳/۸؛ ایرادات ۱۱، بندهای ۴.۳ و ۴.۱۵). قاعده‌ها و داده در `AssistantTest` ِ سرور؛ این‌جا دکمه‌ی
 * شناور، پنل، سه حالتِ پاسخ، بازه و مبنا، و اینکه جز متنِ پرسش چیزی فرستاده نمی‌شود.
 */

const answer = (extra: Partial<AssistantAnswer> = {}): AssistantAnswer => ({
  status: 'found',
  text: 'هزینه‌های مجتمع در مهر ۱۴۰۵: ۲ مورد، جمعاً ۴۲۰,۵۰۰ تومان.',
  headline: 'هزینه‌های مجتمع در مهر ۱۴۰۵: ۲ مورد، جمعاً ۴۲۰,۵۰۰ تومان.',
  facts: [
    { label: 'تعداد', value: '۲' },
    { label: 'جمع', value: '۴۲۰,۵۰۰ تومان' },
  ],
  columns: ['عنوان', 'تاریخ', 'مبلغ', 'دسته'],
  rows: [['آسانسور', '۱۴۰۵/۰۷/۱۰', '۳۰۰,۰۰۰ تومان', 'مالکانه']],
  more: false,
  basis: 'هزینه‌های ثبت‌شده در دوره‌ی مالیِ همین بازه',
  period: 'مهر ۱۴۰۵ (۱ مهر ۱۴۰۵ تا ۳۰ مهر ۱۴۰۵)',
  note: '«ماهِ قبل» یعنی ماهِ شمسیِ پیش از ماهِ جاری.',
  options: [],
  tool: 'finance.expenses',
  source: 'expenses',
  engine: 'none',
  ...extra,
})

const state = vi.hoisted(() => ({
  history: null as AssistantHistory | null,
  reply: null as AssistantAnswer | null,
  fail: null as Error | null,
  calls: [] as { path: string; method: string; body?: unknown }[],
}))

vi.mock('@/shared/lib/api', () => ({
  api: vi.fn((path: string, options: { method?: string; body?: unknown } = {}) => {
    state.calls.push({ path, method: options.method ?? 'GET', body: options.body })
    if (path === '/assistant/history' && !options.method) return Promise.resolve(state.history)
    if (path === '/assistant/ask') {
      if (state.fail) return Promise.reject(state.fail)
      return Promise.resolve({
        id: 7,
        question: (options.body as { question: string }).question,
        createdAt: '۱۴۰۵/۰۸/۰۵ ۱۳:۳۰',
        answer: state.reply,
      })
    }
    return Promise.resolve({ message: 'ok', deleted: 1 })
  }),
  ApiError: class extends Error {},
}))
vi.mock('@/shared/lib/alert', () => ({ alertError: vi.fn() }))

function show() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <AssistantDock />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  state.history = {
    context: 'مجتمع مسکونی آفتاب',
    suggestions: ['وضعیت صندوق چیست؟'],
    messages: [],
  }
  state.reply = answer()
  state.fail = null
  state.calls = []
})

describe('دستیارِ داخلی', () => {
  it('دکمه‌ی دایره‌ایِ شناورِ پایینِ چپ با نامِ دسترس‌پذیر؛ پنل فقط با کلیک', async () => {
    const user = userEvent.setup()
    show()

    const launcher = screen.getByRole('button', { name: 'دستیارِ هوشمندِ ساکنا' })
    expect(launcher.className).toMatch(/\bfixed\b/)
    expect(launcher.className).toMatch(/\bbottom-4\b/)
    expect(launcher.className).toMatch(/\bleft-4\b/)
    expect(launcher.className).toMatch(/\brounded-full\b/)
    expect(launcher).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(state.calls).toEqual([])

    await user.click(launcher)
    const dialog = await screen.findByRole('dialog', { name: 'دستیارِ هوشمندِ ساکنا' })
    expect(await within(dialog).findByText(/مجتمع مسکونی آفتاب/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'بستنِ دستیار', expanded: true })).toBeInTheDocument()
    expect(within(dialog).getByLabelText('پرسش از دستیار')).toHaveFocus()
  })

  it('پاسخِ یافته با عدد، جدول، بازه و مبنا؛ فقط متنِ پرسش فرستاده می‌شود', async () => {
    const user = userEvent.setup()
    show()
    await user.click(screen.getByRole('button', { name: 'دستیارِ هوشمندِ ساکنا' }))

    await user.type(
      await screen.findByLabelText('پرسش از دستیار'),
      'مجموع هزینه‌های ماه گذشته{Enter}',
    )

    const dialog = screen.getByRole('dialog')
    expect(await within(dialog).findByText('۴۲۰,۵۰۰ تومان')).toBeInTheDocument()
    expect(within(dialog).getByRole('table')).toHaveTextContent('آسانسور')
    expect(within(dialog).getByText(/بازه: مهر ۱۴۰۵/)).toBeInTheDocument()
    expect(within(dialog).getByText(/مبنا: هزینه‌های ثبت‌شده/)).toBeInTheDocument()
    expect(state.calls.find((c) => c.path === '/assistant/ask')?.body).toEqual({
      question: 'مجموع هزینه‌های ماه گذشته',
    })
    expect(screen.getByLabelText('پرسش از دستیار')).toHaveValue('')
  })

  it('«اجازه نیست» و «پیدا نشد» از پاسخِ یافته جدا و روشن‌اند', async () => {
    const user = userEvent.setup()
    show()
    await user.click(screen.getByRole('button', { name: 'دستیارِ هوشمندِ ساکنا' }))
    const field = await screen.findByLabelText('پرسش از دستیار')

    state.reply = answer({
      status: 'forbidden',
      headline: 'این اطلاعات فقط در داشبوردِ مدیرِ مجتمع دیده می‌شود.',
      facts: [],
      rows: [],
      columns: [],
      basis: null,
      period: null,
      note: null,
    })
    await user.type(field, 'وضعیت صندوق{Enter}')
    expect(await screen.findByText('اجازه‌ی دیدن ندارید')).toBeInTheDocument()

    state.reply = answer({
      status: 'not_found',
      headline: 'واحدِ ۹۹ در این مجتمع ثبت نشده است.',
      facts: [],
      rows: [],
      columns: [],
      basis: null,
      period: null,
      note: null,
    })
    await user.type(field, 'مشخصات واحد ۹۹{Enter}')
    expect(await screen.findByText('داده‌ای پیدا نشد')).toBeInTheDocument()
    expect(screen.getByText('واحدِ ۹۹ در این مجتمع ثبت نشده است.')).toBeInTheDocument()
  })

  it('پرسشِ خالی فرستاده نمی‌شود؛ خطای سرور کنارِ ورودی', async () => {
    const user = userEvent.setup()
    show()
    await user.click(screen.getByRole('button', { name: 'دستیارِ هوشمندِ ساکنا' }))
    const field = await screen.findByLabelText('پرسش از دستیار')

    await user.click(screen.getByRole('button', { name: 'فرستادنِ پرسش' }))
    expect(await screen.findByText('پرسش را بنویسید.')).toBeInTheDocument()
    expect(state.calls.some((c) => c.path === '/assistant/ask')).toBe(false)

    state.fail = new Error('دستیارِ هوشمند قابلیتِ پلنِ پیشرفته‌ی مجتمع است.')
    await user.type(field, 'وضعیت صندوق{Enter}')
    expect(await screen.findByRole('alert')).toHaveTextContent('پلنِ پیشرفته')
    expect(field).toHaveValue('وضعیت صندوق')
  })

  it('گفت‌وگوی قبلیِ همین زمینه با بستن و بازکردن می‌ماند؛ Escape می‌بندد و فوکوس برمی‌گردد', async () => {
    state.history = {
      context: 'مجتمع مسکونی آفتاب',
      suggestions: [],
      messages: [
        {
          id: 3,
          question: 'شماره تلفن مالک واحد ۳',
          status: 'found',
          text: 'پاسخ شاملِ نام یا اطلاعاتِ تماسِ اشخاص بود و ذخیره نشد.',
          redacted: true,
          createdAt: '۱۴۰۵/۰۸/۰۵',
        },
      ],
    }
    const user = userEvent.setup()
    show()
    const launcher = screen.getByRole('button', { name: 'دستیارِ هوشمندِ ساکنا' })
    await user.click(launcher)
    expect(await screen.findByText('شماره تلفن مالک واحد ۳')).toBeInTheDocument()

    await user.keyboard('{Escape}')
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(launcher).toHaveFocus()

    await user.click(launcher)
    expect(await screen.findByText(/ذخیره نشد/)).toBeInTheDocument()
  })

  it('پرسشِ همین نشست هم پس از بستن و بازکردن می‌ماند (پنل unmount می‌شود، گفت‌وگو نه)', async () => {
    const user = userEvent.setup()
    show()
    const launcher = screen.getByRole('button', { name: 'دستیارِ هوشمندِ ساکنا' })
    await user.click(launcher)
    await user.type(
      await screen.findByLabelText('پرسش از دستیار'),
      'مجموع هزینه‌های ماه گذشته{Enter}',
    )
    expect(await screen.findByText('۴۲۰,۵۰۰ تومان')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'بستنِ دستیار', expanded: true }))
    await user.click(launcher)
    expect(await screen.findByText('مجموع هزینه‌های ماه گذشته')).toBeInTheDocument()
    expect(screen.getByText('۴۲۰,۵۰۰ تومان')).toBeInTheDocument()
  })

  it('پاک‌کردنِ گفت‌وگو با تأییدِ دوم', async () => {
    const user = userEvent.setup()
    show()
    await user.click(screen.getByRole('button', { name: 'دستیارِ هوشمندِ ساکنا' }))

    await user.click(await screen.findByRole('button', { name: 'پاک‌کردنِ گفت‌وگو' }))
    expect(state.calls.some((c) => c.method === 'DELETE')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'تأییدِ پاک‌کردنِ گفت‌وگو' }))
    await waitFor(() =>
      expect(state.calls).toContainEqual({
        path: '/assistant/history',
        method: 'DELETE',
        body: undefined,
      }),
    )
  })
})
