import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { PollCard, type MessagePoll } from '@/features/messaging/messenger/PollCard'
import { EmojiPicker } from '@/features/messaging/messenger/EmojiPicker'

/**
 * نظرسنجیِ درون‌چت (R23b) با نتیجه‌گیریِ حرفه‌ای (R24) و انتخابگرِ اموجی.
 *
 * حساس‌ترین رفتارِ نظرسنجی **تعویضِ رأی** است: اگر شمارشِ خوش‌بینانه گزینه‌ی
 * قبلی را کم نکند، کاربر جمعی می‌بیند که از تعدادِ رأی‌دهنده‌ها بیشتر است.
 *
 * دومی، **دلیلِ نبودِ حقِ رأی** است: دکمه‌ی خاموشِ بی‌دلیل کاربر را به
 * پشتیبانی می‌فرستد، پس متنِ دلیل باید روی کارت دیده شود.
 */
const post = vi.fn()

vi.mock('@/shared/lib/api', () => ({
  api: (...args: unknown[]) => post(...args),
  ApiError: class extends Error {},
}))

vi.mock('@/shared/lib/alert', () => ({ alertError: vi.fn() }))

const poll: MessagePoll = {
  id: 7,
  question: 'رنگ نمای ساختمان؟',
  isClosed: false,
  closesAt: null,
  voterScope: 'residents',
  voterScopeLabel: 'همه‌ی ساکنین',
  weightMode: 'per_person',
  weightModeLabel: 'هر نفر یک رأی',
  weightUnit: 'رأی',
  allowChange: true,
  options: [
    { id: 1, label: 'آبی', votes: 1, weight: 1, share: 50 },
    { id: 2, label: 'خاکستری', votes: 1, weight: 1, share: 50 },
  ],
  totalVotes: 2,
  myOptionId: null,
  blockReason: null,
  eligibleWeight: 4,
  castWeight: 2,
  turnoutPercent: 50,
  quorumPercent: null,
  quorumMet: true,
  weightUnavailable: false,
  leaderId: null,
  isTie: false,
}

beforeEach(() => {
  post.mockReset()
  post.mockResolvedValue({ poll })
})

afterEach(() => {
  vi.clearAllMocks()
})

describe('PollCard', () => {
  it('نتیجه و درصد مشارکت را پیش از رأی‌دادن هم نشان می‌دهد', () => {
    render(<PollCard poll={poll} isMine={false} onVoted={vi.fn()} />)

    expect(screen.getAllByText('50٪')).toHaveLength(2)
    expect(screen.getByText(/مشارکت 50٪/)).toBeInTheDocument()
    expect(screen.getByText('هر نفر یک رأی')).toBeInTheDocument()
  })

  it('با رأی‌دادن، شمارش خوش‌بینانه بالا می‌رود و نتیجه‌ی سرور جایگزین می‌شود', async () => {
    const onVoted = vi.fn()
    render(<PollCard poll={poll} isMine={false} onVoted={onVoted} />)

    await userEvent.click(screen.getByRole('button', { name: /آبی/ }))

    expect(onVoted).toHaveBeenNthCalledWith(
      1,
      expect.objectContaining({ myOptionId: 1, totalVotes: 3 }),
    )

    expect(post).toHaveBeenCalledWith('/messenger/polls/7/vote', {
      method: 'POST',
      body: { option_id: 1 },
    })

    // پاسخِ سرور حرفِ آخر است، چون وزن را کلاینت نمی‌تواند حساب کند
    expect(onVoted).toHaveBeenLastCalledWith(poll)
  })

  it('تعویضِ رأی مجموع را بالا نمی‌برد و گزینه‌ی قبلی را کم می‌کند', async () => {
    const onVoted = vi.fn()
    render(<PollCard poll={{ ...poll, myOptionId: 1 }} isMine={false} onVoted={onVoted} />)

    await userEvent.click(screen.getByRole('button', { name: /خاکستری/ }))

    expect(onVoted).toHaveBeenNthCalledWith(
      1,
      expect.objectContaining({
        myOptionId: 2,
        totalVotes: 2, // ← نه ۳
        options: [
          expect.objectContaining({ id: 1, votes: 0 }),
          expect.objectContaining({ id: 2, votes: 2 }),
        ],
      }),
    )
  })

  it('وقتی حق رأی ندارد، دکمه خاموش است و دلیلش نوشته می‌شود', async () => {
    render(
      <PollCard
        poll={{ ...poll, blockReason: 'این نظرسنجی فقط برای مالکان است.' }}
        isMine={false}
        onVoted={vi.fn()}
      />,
    )

    expect(screen.getByText('این نظرسنجی فقط برای مالکان است.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /آبی/ }))

    expect(post).not.toHaveBeenCalled()
  })

  it('حد نصابِ نرسیده را با علامتِ منفی نشان می‌دهد', () => {
    render(
      <PollCard
        poll={{ ...poll, quorumPercent: 75, quorumMet: false }}
        isMine={false}
        onVoted={vi.fn()}
      />,
    )

    expect(screen.getByText(/حد نصاب 75٪/)).toBeInTheDocument()
    expect(screen.getByText('✗')).toBeInTheDocument()
  })

  it('نظرسنجیِ وزنی بدونِ متراژ هشدار می‌دهد', () => {
    render(
      <PollCard
        poll={{ ...poll, weightMode: 'by_area', weightUnavailable: true }}
        isMine={false}
        onVoted={vi.fn()}
      />,
    )

    expect(screen.getByText(/متراژ واحدها ثبت نشده/)).toBeInTheDocument()
  })

  it('دکمه‌ی بستن فقط به مدیر نشان داده می‌شود', () => {
    const { rerender } = render(<PollCard poll={poll} isMine onVoted={vi.fn()} />)
    expect(screen.queryByText(/بستن نظرسنجی/)).not.toBeInTheDocument()

    rerender(<PollCard poll={poll} isMine isAdmin onVoted={vi.fn()} />)
    expect(screen.getByText(/بستن نظرسنجی/)).toBeInTheDocument()
  })

  it('نظرسنجیِ بسته رأی نمی‌گیرد و دکمه‌ی بستن هم ندارد', async () => {
    render(
      <PollCard
        poll={{ ...poll, isClosed: true, blockReason: 'این نظرسنجی بسته شده است.' }}
        isMine={false}
        isAdmin
        onVoted={vi.fn()}
      />,
    )

    expect(screen.getByRole('button', { name: /آبی/ })).toBeDisabled()
    expect(screen.queryByText(/بستن نظرسنجی/)).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /آبی/ }))
    expect(post).not.toHaveBeenCalled()
  })

  it('نظرسنجیِ بی‌رأی صفر درصد نشان می‌دهد و تقسیم بر صفر نمی‌کند', () => {
    render(
      <PollCard
        poll={{
          ...poll,
          totalVotes: 0,
          castWeight: 0,
          turnoutPercent: 0,
          options: poll.options.map((o) => ({ ...o, votes: 0, weight: 0, share: 0 })),
        }}
        isMine={false}
        onVoted={vi.fn()}
      />,
    )

    expect(screen.getAllByText('0٪')).toHaveLength(2)
  })
})

describe('EmojiPicker', () => {
  it('پنل بسته است تا وقتی روی دکمه کلیک شود', async () => {
    render(<EmojiPicker onPick={vi.fn()} />)

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'افزودن اموجی' }))
    expect(screen.getByRole('listbox')).toBeInTheDocument()
  })

  it('با انتخابِ اموجی، آن را برمی‌گرداند و پنل را می‌بندد', async () => {
    const onPick = vi.fn()
    render(<EmojiPicker onPick={onPick} />)

    await userEvent.click(screen.getByRole('button', { name: 'افزودن اموجی' }))
    await userEvent.click(screen.getByRole('option', { name: '👍' }))

    expect(onPick).toHaveBeenCalledWith('👍')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('با Escape بسته می‌شود تا روی فرمِ ارسال نماند', async () => {
    render(<EmojiPicker onPick={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: 'افزودن اموجی' }))
    await userEvent.keyboard('{Escape}')

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })
})
