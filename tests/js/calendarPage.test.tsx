import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CalendarPage } from '@/features/calendar/CalendarPage'
import type { ReminderDetail, ReminderOccurrence } from '@/features/calendar/types'

/**
 * تقویمِ شمسی و یادآورها در رابط (۱۳/۷؛ ایرادات ۱۰، بندهای ۷۰ تا ۹۶ و ۱۰۱).
 *
 * «اکنون» ثابت است: ۲۰۲۶-۱۰-۲۷، ۱۰:۰۰ ِ UTC = سه‌شنبه ۵ آبان ۱۴۰۵ ِ تهران. قاعده‌های سرور در `ReminderTest`؛ این‌جا
 * فقط آنچه رابط نشان می‌دهد و می‌فرستد.
 */

const occurrence = (date: string, extra: Partial<ReminderOccurrence> = {}): ReminderOccurrence => ({
  id: `1:${date}`,
  reminderId: 1,
  date,
  dateLabel: 'روز',
  remindDateLabel: 'روزِ یادآوری',
  remindTime: '09:00',
  title: 'جلسه هیئت‌مدیره',
  description: null,
  recurring: false,
  repeatLabel: 'یک‌باره',
  past: false,
  ...extra,
})

const detail = (extra: Partial<ReminderDetail> = {}): ReminderDetail => ({
  id: 1,
  title: 'جلسه هیئت‌مدیره',
  description: 'بررسی گزارش مالی',
  targetDate: '2026-11-01',
  targetDateLabel: '۱۰ آبان ۱۴۰۵',
  leadDays: 1,
  remindTime: '09:00',
  remindDateLabel: '۹ آبان ۱۴۰۵',
  repeatEveryDays: null,
  repeatEnd: null,
  repeatUntil: null,
  repeatCount: null,
  repeatLabel: 'یک‌باره',
  nextOccurrence: '2026-11-01',
  status: 'scheduled',
  statusLabel: 'زمان‌بندی‌شده',
  notifiedCount: 0,
  createdAt: '۵ آبان ۱۴۰۵',
  ...extra,
})

const state = vi.hoisted(() => ({
  occurrences: [] as ReminderOccurrence[],
  detail: null as ReminderDetail | null,
  history: [] as ReminderDetail[],
  calls: [] as { path: string; method: string; body?: unknown }[],
}))

vi.mock('@/shared/lib/api', () => ({
  api: vi.fn((path: string, options: { method?: string; body?: unknown } = {}) => {
    state.calls.push({ path, method: options.method ?? 'GET', body: options.body })
    if (path.startsWith('/reminders?')) return Promise.resolve({ data: state.occurrences })
    if (path.startsWith('/reminders/history')) {
      return Promise.resolve({
        data: state.history,
        meta: { currentPage: 1, lastPage: 1, total: state.history.length },
      })
    }
    if (/^\/reminders\/\d+$/.test(path) && !options.method)
      return Promise.resolve({ reminder: state.detail })
    return Promise.resolve({ message: 'ok' })
  }),
  ApiError: class extends Error {},
}))
vi.mock('@/shared/lib/alert', () => ({
  alertError: vi.fn(),
  toastSuccess: vi.fn(),
  confirmAction: vi.fn(() => Promise.resolve(true)),
}))

function show(path = '/calendar') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <CalendarPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const cell = (label: RegExp) => screen.getByRole('gridcell', { name: label })

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-27T10:00:00Z'))
  state.occurrences = []
  state.detail = null
  state.history = []
  state.calls = []
})
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('تقویمِ شمسی', () => {
  it('ماهِ جاری، روزهای هفته از شنبه و امروز مشخص', async () => {
    show()

    expect(await screen.findByRole('heading', { level: 2, name: 'آبان ۱۴۰۵' })).toBeInTheDocument()
    expect(screen.getAllByRole('columnheader').map((h) => h.getAttribute('aria-label'))).toEqual([
      'شنبه',
      'یکشنبه',
      'دوشنبه',
      'سه‌شنبه',
      'چهارشنبه',
      'پنجشنبه',
      'جمعه',
    ])
    expect(screen.getAllByRole('gridcell')).toHaveLength(30)
    expect(cell(/^۵ آبان ۱۴۰۵، امروز/)).toHaveAttribute('aria-current', 'date')
    expect(cell(/^۵ آبان ۱۴۰۵/)).toHaveAttribute('aria-selected', 'true')
  })

  it('ماه و سالِ قبل و بعد؛ گذشته و آینده‌ی دور؛ اسفندِ کبیسه ۳۰ روز', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    show()
    await screen.findByRole('heading', { level: 2, name: 'آبان ۱۴۰۵' })

    await user.click(screen.getByRole('button', { name: 'ماه بعد' }))
    expect(screen.getByRole('heading', { level: 2, name: 'آذر ۱۴۰۵' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'سال قبل' }))
    expect(screen.getByRole('heading', { level: 2, name: 'آذر ۱۴۰۴' })).toBeInTheDocument()
    for (let i = 0; i < 6; i++) await user.click(screen.getByRole('button', { name: 'سال بعد' }))
    expect(screen.getByRole('heading', { level: 2, name: 'آذر ۱۴۱۰' })).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('سال'), { target: { value: '1403' } })
    fireEvent.change(screen.getByLabelText('ماه'), { target: { value: '12' } })
    expect(screen.getByRole('heading', { level: 2, name: 'اسفند ۱۴۰۳' })).toBeInTheDocument()
    expect(screen.getAllByRole('gridcell')).toHaveLength(30)
    fireEvent.change(screen.getByLabelText('سال'), { target: { value: '1404' } })
    expect(screen.getAllByRole('gridcell')).toHaveLength(29)

    await user.click(screen.getByRole('button', { name: 'امروز' }))
    expect(screen.getByRole('heading', { level: 2, name: 'آبان ۱۴۰۵' })).toBeInTheDocument()
    // بازه‌ی هر ماه همان ماهِ میلادیِ درست است
    expect(state.calls.some((c) => c.path === '/reminders?from=2025-02-19&to=2025-03-20')).toBe(
      true,
    )
  })

  it('روزی که یادآور دارد نشانگر دارد و فهرستِ همان روز را نشان می‌دهد', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    state.occurrences = [occurrence('2026-11-01')]
    show()

    await waitFor(() => expect(cell(/^۱۰ آبان ۱۴۰۵، ۱ یادآور/)).toBeInTheDocument())
    await user.click(cell(/^۱۰ آبان ۱۴۰۵/))
    expect(screen.getByRole('button', { name: /جلسه هیئت‌مدیره/ })).toBeInTheDocument()
  })
})

describe('یادآور', () => {
  it('ساختن: موضوع، زمانِ یادآوری، پیش‌نمایشِ جدا از تاریخِ رویداد، سپس ثبت', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    show()
    await user.click(await screen.findByRole('gridcell', { name: /^۲۹ آبان ۱۴۰۵/ }))
    await user.click(screen.getByRole('button', { name: 'یادآورِ تازه برای این روز' }))

    await user.type(screen.getByLabelText('موضوع'), 'جلسه هیئت‌مدیره')
    await user.type(screen.getByLabelText('توضیحات (اختیاری)'), 'بررسی گزارش مالی')
    await user.selectOptions(screen.getByLabelText('زمانِ یادآوری'), '3')
    await user.click(screen.getByRole('button', { name: 'پیش‌نمایش' }))

    // بندِ ۸۰: خلاصه پیش از ثبت
    const summary = within(screen.getByText('تاریخِ رویداد').closest('dl') as HTMLElement)
    for (const text of [
      '۲۹ آبان ۱۴۰۵',
      '۳ روز قبل',
      '۲۶ آبان ۱۴۰۵ ساعتِ ۰۹:۰۰',
      'یک‌باره',
      'بررسی گزارش مالی',
    ]) {
      expect(summary.getByText(text)).toBeInTheDocument()
    }
    await user.click(screen.getByRole('button', { name: 'ثبت یادآور' }))

    await waitFor(() => expect(state.calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(state.calls.find((c) => c.method === 'POST')).toEqual({
      path: '/reminders',
      method: 'POST',
      body: {
        title: 'جلسه هیئت‌مدیره',
        description: 'بررسی گزارش مالی',
        target_date: '2026-11-20',
        lead_days: 3,
        remind_time: '09:00',
        repeat_every_days: null,
        repeat_end: null,
        repeat_until: null,
        repeat_count: null,
      },
    })
  })

  it('بی موضوع چیزی فرستاده نمی‌شود؛ تکرارشونده با فاصله و تعداد', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    show()
    await user.click(await screen.findByRole('button', { name: 'یادآورِ تازه برای این روز' }))

    await user.click(screen.getByRole('button', { name: 'پیش‌نمایش' }))
    expect(await screen.findByText('موضوعِ یادآور را بنویسید.')).toBeInTheDocument()

    await user.type(screen.getByLabelText('موضوع'), 'بازدیدِ آسانسور')
    await user.selectOptions(screen.getByLabelText('تکرار'), 'every')
    await user.clear(screen.getByLabelText('هر چند روز'))
    await user.type(screen.getByLabelText('هر چند روز'), '2')
    await user.selectOptions(screen.getByLabelText('پایانِ تکرار'), 'count')
    await user.type(screen.getByLabelText('چند بار'), '5')
    await user.click(screen.getByRole('button', { name: 'پیش‌نمایش' }))
    expect(screen.getByText('هر ۲ روز، ۵ بار')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'ثبت یادآور' }))

    await waitFor(() => expect(state.calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(state.calls.find((c) => c.method === 'POST')?.body).toMatchObject({
      repeat_every_days: 2,
      repeat_end: 'count',
      repeat_count: 5,
    })
  })

  it('یک‌باره‌ای که لحظه‌اش رفته به پیش‌نمایش نمی‌رسد (ساعتِ تهران)', async () => {
    // اکنون ۱۳:۳۰ ِ تهران، ۵ آبان؛ رویدادِ امروز
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    show()
    await user.click(await screen.findByRole('button', { name: 'یادآورِ تازه برای این روز' }))
    await user.type(screen.getByLabelText('موضوع'), 'قبضِ گاز')
    await user.selectOptions(screen.getByLabelText('زمانِ یادآوری'), '0')
    await user.click(screen.getByRole('button', { name: 'پیش‌نمایش' }))
    expect(await screen.findByText(/این زمانِ یادآوری گذشته است/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'ثبت یادآور' })).not.toBeInTheDocument()

    // ساعتِ دیرترِ همان روز پذیرفته است
    await user.clear(screen.getByLabelText('ساعتِ یادآوری'))
    await user.type(screen.getByLabelText('ساعتِ یادآوری'), '18:00')
    await user.click(screen.getByRole('button', { name: 'پیش‌نمایش' }))
    expect(await screen.findByRole('button', { name: 'ثبت یادآور' })).toBeInTheDocument()
  })

  it('روزِ گذشته: یادآورِ تازه ساخته نمی‌شود', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    show()
    await user.click(await screen.findByRole('gridcell', { name: /^۴ آبان ۱۴۰۵/ }))

    expect(screen.queryByRole('button', { name: 'یادآورِ تازه برای این روز' })).toBeNull()
    expect(screen.getByText(/روزِ گذشته است/)).toBeInTheDocument()
  })

  it('ویرایشِ تکرارشونده می‌پرسد «فقط همین / همین و بعدی‌ها / همه» و همان را می‌فرستد', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    state.occurrences = [
      occurrence('2026-11-01', { recurring: true, repeatLabel: 'هر ۷ روز، بی‌پایان' }),
    ]
    state.detail = detail({
      repeatEveryDays: 7,
      repeatEnd: 'none',
      repeatLabel: 'هر ۷ روز، بی‌پایان',
    })
    show()

    await waitFor(() => expect(cell(/^۱۰ آبان ۱۴۰۵، ۱ یادآور/)).toBeInTheDocument())
    await user.click(cell(/^۱۰ آبان ۱۴۰۵/))
    await user.click(screen.getByRole('button', { name: /جلسه هیئت‌مدیره/ }))
    expect(await screen.findByText('هر ۷ روز، بی‌پایان')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /حذفِ همین و بعدی‌ها/ })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'ویرایش' }))
    await user.click(screen.getByRole('button', { name: 'پیش‌نمایش' }))
    await user.click(screen.getByRole('radio', { name: 'همین و همه‌ی تکرارهای بعدی' }))
    await user.click(screen.getByRole('button', { name: 'ثبتِ تغییرات' }))

    await waitFor(() => expect(state.calls.find((c) => c.method === 'PUT')).toBeDefined())
    expect(state.calls.find((c) => c.method === 'PUT')).toMatchObject({
      path: '/reminders/1',
      body: { scope: 'following', occurrence: '2026-11-01', repeat_every_days: 7 },
    })
  })

  it('حذفِ فقط همین نمونه از سری', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    state.occurrences = [occurrence('2026-11-01', { recurring: true })]
    state.detail = detail({ repeatEveryDays: 7, repeatEnd: 'none' })
    show()

    await waitFor(() => expect(cell(/^۱۰ آبان ۱۴۰۵، ۱ یادآور/)).toBeInTheDocument())
    await user.click(cell(/^۱۰ آبان ۱۴۰۵/))
    await user.click(screen.getByRole('button', { name: /جلسه هیئت‌مدیره/ }))
    await user.click(await screen.findByRole('button', { name: /^حذفِ همین$/ }))

    await waitFor(() =>
      expect(state.calls).toContainEqual({
        path: '/reminders/1?scope=this&occurrence=2026-11-01',
        method: 'DELETE',
        body: undefined,
      }),
    )
  })
})

describe('تاریخچه', () => {
  it('همه‌ی فیلدها شمسی؛ حذفِ تکی و «حذفِ همه» با تأیید', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    state.history = [
      detail(),
      detail({ id: 2, title: 'قبضِ گاز', status: 'done', statusLabel: 'یادآوری شد' }),
    ]
    show('/calendar/history')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'تاریخچه یادآورها' }),
    ).toBeInTheDocument()
    expect(await screen.findAllByText('بررسی گزارش مالی')).toHaveLength(2)
    expect(
      screen.getAllByText(/رویداد: ۱۰ آبان ۱۴۰۵ · یادآوری: ۹ آبان ۱۴۰۵ ساعتِ ۰۹:۰۰/),
    ).toHaveLength(2)
    expect(screen.getByText('یادآوری شد')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'حذفِ «قبضِ گاز»' }))
    await waitFor(() =>
      expect(state.calls).toContainEqual({
        path: '/reminders/2',
        method: 'DELETE',
        body: undefined,
      }),
    )
    await user.click(screen.getByRole('button', { name: /حذفِ همه/ }))
    await waitFor(() =>
      expect(state.calls).toContainEqual({ path: '/reminders', method: 'DELETE', body: undefined }),
    )
  })
})
