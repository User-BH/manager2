import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PollCard, type MessagePoll } from '@/shared/ui/PollCard'
import { PollComposer, emptyPoll } from '@/features/messaging/messenger/PollComposer'
import { Composer } from '@/features/messaging/messenger/Composer'

/**
 * نظرسنجی در رابط — مرحلهٔ ۱۰ · فاز ۱۱ (بندهای ۶۱، ۶۴، ۶۵ و ۶۶ ِ فایلِ معماری).
 *
 * قاعده‌ها در سرورند (`PollRulesTest`). این‌جا: چندگزینه‌ای انتخاب‌ها را تا سقف جمع می‌کند و یک‌جا می‌فرستد؛
 * «مجاز نیستید» روشن گفته می‌شود؛ «فقط مستأجران» و سقفِ انتخاب در سازنده هست؛ و گفت‌وگوی خصوصی دکمه‌ی نظرسنجی ندارد.
 */

const post = vi.fn()

vi.mock('@/shared/lib/api', () => ({
  api: (...args: unknown[]) => post(...args),
  ApiError: class extends Error {},
}))
vi.mock('@/shared/lib/alert', () => ({ alertError: vi.fn() }))

function poll(extra: Partial<MessagePoll> = {}): MessagePoll {
  return {
    id: 9,
    question: 'کدام روزها؟',
    isClosed: false,
    closesAt: null,
    voterScope: 'residents',
    voterScopeLabel: 'همه‌ی ساکنین',
    weightMode: 'per_person',
    weightModeLabel: 'هر نفر یک رأی',
    weightUnit: 'رأی',
    allowChange: true,
    options: ['شنبه', 'دوشنبه', 'چهارشنبه'].map((label, i) => ({
      id: i + 1,
      label,
      votes: 0,
      weight: 0,
      share: 0,
    })),
    totalVotes: 0,
    myOptionId: null,
    myOptionIds: [],
    maxSelections: 1,
    blockReason: null,
    eligible: true,
    eligibleWeight: 4,
    castWeight: 0,
    turnoutPercent: 0,
    quorumPercent: null,
    quorumMet: true,
    weightUnavailable: false,
    leaderId: null,
    isTie: false,
    ...extra,
  }
}

beforeEach(() => {
  post.mockReset()
  post.mockResolvedValue({ poll: poll() })
})

describe('کارتِ نظرسنجی', () => {
  it('چندگزینه‌ای تا سقف جمع می‌کند و با «ثبتِ رأی» یک‌جا می‌فرستد', () => {
    render(<PollCard poll={poll({ maxSelections: 2 })} onVoted={vi.fn()} isMine={false} />)

    expect(screen.getByText('تا 2 گزینه', { exact: false })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /^شنبه/ }))
    fireEvent.click(screen.getByRole('button', { name: /^دوشنبه/ }))
    // سومی از سقف می‌گذرد و انتخاب نمی‌شود
    fireEvent.click(screen.getByRole('button', { name: /^چهارشنبه/ }))
    expect(screen.getByRole('button', { name: /^چهارشنبه/ })).toHaveAttribute(
      'aria-pressed',
      'false',
    )
    expect(post).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'ثبتِ رأی' }))
    expect(post).toHaveBeenCalledWith('/messenger/polls/9/vote', {
      method: 'POST',
      body: { option_ids: [1, 2] },
    })
  })

  it('تک‌گزینه‌ای همان یک کلیک است', () => {
    render(<PollCard poll={poll()} onVoted={vi.fn()} isMine={false} />)

    expect(screen.queryByRole('button', { name: 'ثبتِ رأی' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: /^دوشنبه/ }))
    expect(post).toHaveBeenCalledWith('/messenger/polls/9/vote', {
      method: 'POST',
      body: { option_id: 2 },
    })
  })

  it('بندِ ۶۶: می‌بیند، ولی روشن می‌گوید مجاز نیست — و گزینه‌ها خاموش‌اند', () => {
    render(
      <PollCard
        poll={poll({
          voterScope: 'tenants',
          voterScopeLabel: 'فقط مستأجران',
          eligible: false,
          blockReason: 'این نظرسنجی فقط برای مستأجران است.',
        })}
        onVoted={vi.fn()}
        isMine={false}
      />,
    )

    expect(screen.getByText('شما مجاز به رأی دادن در این نظرسنجی نیستید.')).toBeInTheDocument()
    expect(screen.getByText('این نظرسنجی فقط برای مستأجران است.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^شنبه/ })).toBeDisabled()
  })

  it('رأیِ ثبت‌شده‌ی غیرقابلِ تغییر «مجاز نیستید» نیست', () => {
    render(
      <PollCard
        poll={poll({ eligible: true, blockReason: 'رأی شما ثبت شده و قابل تغییر نیست.' })}
        onVoted={vi.fn()}
        isMine={false}
      />,
    )

    expect(screen.queryByText('شما مجاز به رأی دادن در این نظرسنجی نیستید.')).toBeNull()
  })
})

describe('سازنده‌ی نظرسنجی', () => {
  it('«فقط مستأجران» و سقفِ انتخاب دارد', () => {
    const onChange = vi.fn()
    render(<PollComposer draft={emptyPoll()} onChange={onChange} onClose={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: /تنظیمات رأی‌گیری/ }))
    expect(screen.getByRole('option', { name: 'فقط مستأجران' })).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('حداکثر گزینه‌ی قابلِ انتخاب'), {
      target: { value: '2' },
    })
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ maxSelections: '2' }))
  })

  it('بندِ ۶۱: گفت‌وگوی خصوصی دکمه‌ی نظرسنجی ندارد', () => {
    const props = {
      target: null,
      onCancelTarget: vi.fn(),
      onSend: vi.fn(() => Promise.resolve()),
      onEdit: vi.fn(() => Promise.resolve()),
      onNotice: vi.fn(),
    }
    const { rerender } = render(<Composer isAdmin canPoll={false} {...props} />)
    // ۱۲/۷: نظرسنجی زیرِ «بیشتر» است؛ بی نظرسنجی (و بی ضبط در jsdom) خودِ «بیشتر» هم نیست
    expect(screen.queryByRole('button', { name: /^بیشتر/ })).toBeNull()
    expect(screen.queryByRole('button', { name: 'ساخت نظرسنجی' })).toBeNull()

    rerender(<Composer isAdmin canPoll {...props} />)
    fireEvent.click(screen.getByRole('button', { name: /^بیشتر/ }))
    expect(screen.getByRole('button', { name: 'ساخت نظرسنجی' })).toBeInTheDocument()
  })
})
