import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { PollCard, type MessagePoll } from '@/features/messaging/messenger/PollCard'
import { EmojiPicker } from '@/features/messaging/messenger/EmojiPicker'

/**
 * نظرسنجیِ درون‌چت و انتخابگرِ اموجی (R23b).
 *
 * حساس‌ترین رفتارِ نظرسنجی **تعویضِ رأی** است: اگر شمارشِ خوش‌بینانه گزینه‌ی
 * قبلی را کم نکند، کاربر جمعی می‌بیند که از تعدادِ رأی‌دهنده‌ها بیشتر است و
 * تا واکشیِ بعدی فکر می‌کند نتیجه دستکاری شده.
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
  totalVotes: 2,
  myOptionId: null,
  options: [
    { id: 1, label: 'آبی', votes: 1 },
    { id: 2, label: 'خاکستری', votes: 1 },
  ],
}

beforeEach(() => {
  post.mockReset()
  post.mockResolvedValue({})
})

afterEach(() => {
  vi.clearAllMocks()
})

describe('PollCard', () => {
  it('نتیجه را پیش از رأی‌دادن هم نشان می‌دهد', () => {
    render(<PollCard poll={poll} isMine={false} onVoted={vi.fn()} />)

    expect(screen.getByText('2 رأی')).toBeInTheDocument()
    expect(screen.getAllByText('50٪')).toHaveLength(2)
  })

  it('با رأی‌دادن، شمارش و مجموع خوش‌بینانه بالا می‌رود', async () => {
    const onVoted = vi.fn()
    render(<PollCard poll={poll} isMine={false} onVoted={onVoted} />)

    await userEvent.click(screen.getByRole('button', { name: /آبی/ }))

    expect(onVoted).toHaveBeenCalledWith(
      expect.objectContaining({
        myOptionId: 1,
        totalVotes: 3,
        options: [
          expect.objectContaining({ id: 1, votes: 2 }),
          expect.objectContaining({ id: 2, votes: 1 }),
        ],
      }),
    )

    expect(post).toHaveBeenCalledWith('/messenger/polls/7/vote', {
      method: 'POST',
      body: { option_id: 1 },
    })
  })

  it('تعویضِ رأی مجموع را بالا نمی‌برد و گزینه‌ی قبلی را کم می‌کند', async () => {
    const onVoted = vi.fn()
    const voted: MessagePoll = {
      ...poll,
      myOptionId: 1,
      options: [
        { id: 1, label: 'آبی', votes: 1 },
        { id: 2, label: 'خاکستری', votes: 1 },
      ],
    }

    render(<PollCard poll={voted} isMine={false} onVoted={onVoted} />)
    await userEvent.click(screen.getByRole('button', { name: /خاکستری/ }))

    expect(onVoted).toHaveBeenCalledWith(
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

  it('نظرسنجیِ بسته رأی نمی‌گیرد', async () => {
    render(<PollCard poll={{ ...poll, isClosed: true }} isMine={false} onVoted={vi.fn()} />)

    expect(screen.getByRole('button', { name: /آبی/ })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: /آبی/ }))

    expect(post).not.toHaveBeenCalled()
  })

  it('نظرسنجیِ بی‌رأی صفر درصد نشان می‌دهد و تقسیم بر صفر نمی‌کند', () => {
    render(
      <PollCard
        poll={{ ...poll, totalVotes: 0, options: poll.options.map((o) => ({ ...o, votes: 0 })) }}
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
