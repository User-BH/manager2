import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Composer } from '@/features/messaging/messenger/Composer'
import { MessageBubble, type BubbleHandlers } from '@/features/messaging/messenger/MessageBubble'
import {
  dayLabel,
  firstUnreadId,
  layoutRows,
  nameTone,
  splitSentAt,
} from '@/features/messaging/messenger/messageLayout'
import type { ChatMessage } from '@/features/messaging/messenger/types'
import { formatJalaliDate } from '@/shared/lib/format'

/**
 * ظاهرِ تازه‌ی پیام‌رسان — مرحلهٔ ۱۲ · فاز ۷ (بندهای ۴۱، ۴۳ و ۴۵ ِ سندِ هسته؛ نمونه‌ی تأییدشده‌ی کارفرما).
 *
 * منطقِ چیدمان جدا از رندر سنجیده می‌شود: دسته‌ی پیام‌های پشتِ‌سرِهمِ یک نفر، جداکننده‌ی روز، مرزِ نخوانده و رنگِ
 * ثابتِ نام. و کادرِ نوشتن: دکمه‌ی اصلی تا کادر خالی است ضبطِ صداست و با نخستین نویسه «ارسال».
 */

const today = formatJalaliDate(new Date()).replace(/[‎‏]/g, '')

function message(id: number, overrides: Partial<ChatMessage> = {}): ChatMessage {
  return {
    id,
    body: `پیام ${id}`,
    authorName: 'آلیس',
    unitLabel: 'واحد ۱',
    isMine: false,
    isHidden: false,
    sentAt: `${today} ۱۰:۰${id % 10}`,
    ...overrides,
  }
}

describe('چیدمانِ رشته‌ی پیام', () => {
  it('زمانِ سرور به روز و ساعت جدا می‌شود', () => {
    expect(splitSentAt('۱۴۰۵/۰۷/۲۵ ۱۵:۳۸')).toEqual({ day: '۱۴۰۵/۰۷/۲۵', time: '۱۵:۳۸' })
    expect(splitSentAt('')).toEqual({ day: '', time: '' })
  })

  it('«امروز» و «دیروز» از ساعتِ دستگاه، بقیه همان تاریخِ شمسی', () => {
    const now = new Date()
    const yesterday = new Date(now)
    yesterday.setDate(now.getDate() - 1)
    expect(dayLabel(today, now)).toBe('امروز')
    expect(dayLabel(formatJalaliDate(yesterday).replace(/[‎‏]/g, ''), now)).toBe('دیروز')
    expect(dayLabel('۱۳۹۹/۰۱/۰۱', now)).toBe('۱۳۹۹/۰۱/۰۱')
  })

  it('پیام‌های پشتِ‌سرِهمِ یک نفر یک دسته‌اند: نام روی اولی، دُم روی آخری', () => {
    const rows = layoutRows([
      message(1),
      message(2),
      message(3, { authorName: 'باب', unitLabel: 'واحد ۲' }),
      message(4, { isMine: true, authorName: 'من' }),
      message(5, { isMine: true, authorName: 'من' }),
    ])

    expect(rows.map((r) => [r.groupStart, r.groupEnd])).toEqual([
      [true, false],
      [false, true],
      [true, true],
      [true, false],
      [false, true],
    ])
    // روزِ تازه فقط روی نخستین پیامِ روز
    expect(rows.map((r) => r.day)).toEqual(['امروز', null, null, null, null])
  })

  it('روزِ تازه و مرزِ نخوانده دسته را می‌شکنند', () => {
    const rows = layoutRows([message(1, { sentAt: '۱۳۹۹/۰۱/۰۱ ۰۹:۰۰' }), message(2), message(3)], 3)

    expect(rows.map((r) => r.day)).toEqual(['۱۳۹۹/۰۱/۰۱', 'امروز', null])
    expect(rows.map((r) => r.groupStart)).toEqual([true, true, true])
    expect(rows.map((r) => r.unreadStart)).toEqual([false, false, true])
    expect(rows[1].groupEnd).toBe(true)
  })

  it('مرزِ نخوانده: n پیامِ آخرِ دیگران، نه پیامِ خودم', () => {
    const list = [message(1), message(2, { isMine: true }), message(3), message(4)]
    expect(firstUnreadId(list, 0)).toBeNull()
    expect(firstUnreadId(list, 2)).toBe(3)
    expect(firstUnreadId(list, 99)).toBe(1)
  })

  it('رنگِ نام برای یک نفر ثابت است و در چهار رنگ', () => {
    expect(nameTone('مدیر ساختمان آفتاب')).toBe(nameTone('مدیر ساختمان آفتاب'))
    for (const name of ['آلیس', 'باب', 'مالک واحد ۷', 'مستأجر واحد ۳']) {
      expect([0, 1, 2, 3]).toContain(nameTone(name))
    }
  })
})

describe('کادرِ نوشتن در یک ردیف', () => {
  const props = {
    isAdmin: false,
    target: null,
    onCancelTarget: vi.fn(),
    onSend: vi.fn(() => Promise.resolve()),
    onEdit: vi.fn(() => Promise.resolve()),
    onNotice: vi.fn(),
  }

  afterEach(() => vi.unstubAllGlobals())

  it('کادرِ خالی: ضبطِ صدا؛ با نخستین نویسه: «ارسال پیام»', () => {
    class FakeRecorder {
      static isTypeSupported = () => true
    }
    vi.stubGlobal('MediaRecorder', FakeRecorder)
    Object.defineProperty(navigator, 'mediaDevices', {
      value: { getUserMedia: vi.fn() },
      configurable: true,
    })

    render(<Composer {...props} />)
    expect(
      screen.getByRole('button', { name: 'برای ضبطِ پیامِ صوتی نگه دارید' }),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'ارسال پیام' })).toBeNull()

    fireEvent.change(screen.getByLabelText('متنِ پیام'), { target: { value: 'سلام' } })
    expect(screen.getByRole('button', { name: 'ارسال پیام' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'برای ضبطِ پیامِ صوتی نگه دارید' })).toBeNull()

    fireEvent.change(screen.getByLabelText('متنِ پیام'), { target: { value: '   ' } })
    expect(
      screen.getByRole('button', { name: 'برای ضبطِ پیامِ صوتی نگه دارید' }),
    ).toBeInTheDocument()
  })

  it('مرورگرِ بی ضبط همیشه «ارسال پیام» دارد', () => {
    vi.stubGlobal('MediaRecorder', undefined)
    render(<Composer {...props} />)
    expect(screen.getByRole('button', { name: 'ارسال پیام' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /برای ضبط/ })).toBeNull()
  })
})

describe('جهتِ منوی پیام', () => {
  /*
   * 🔴 ۱۲/۷ (سفرِ مرورگرِ `messenger-journey`): پیامِ بالای قاب با جای کمِ پایین منو را رو به بالا، بیرون از قابِ اسکرول و
   * زیرِ سربرگ باز می‌کرد. رو به بالا فقط وقتی پایین جا نیست و بالا جای بیشتری هست.
   */
  function openAt(rowTop: number, rowBottom: number) {
    const handlers = new Proxy({}, { get: () => vi.fn() }) as BubbleHandlers
    const { container, rerender } = render(
      <div className="overflow-y-auto">
        <ul>
          <li className="chat-row">
            <MessageBubble
              message={message(7)}
              isAdmin={false}
              isPinned={false}
              menuOpen={false}
              onToggleMenu={() => undefined}
              handlers={handlers}
            />
          </li>
        </ul>
      </div>,
    )
    const rect = (top: number, bottom: number) => ({
      top,
      bottom,
      left: 0,
      right: 0,
      width: 0,
      height: bottom - top,
      x: 0,
      y: top,
      toJSON: () => ({}),
    })
    vi.spyOn(container.querySelector('.overflow-y-auto')!, 'getBoundingClientRect').mockReturnValue(
      rect(200, 600),
    )
    vi.spyOn(container.querySelector('.chat-row')!, 'getBoundingClientRect').mockReturnValue(
      rect(rowTop, rowBottom),
    )

    let open = false
    const toggle = (value: boolean) => (open = value)
    rerender(
      <div className="overflow-y-auto">
        <ul>
          <li className="chat-row">
            <MessageBubble
              message={message(7)}
              isAdmin={false}
              isPinned={false}
              menuOpen={false}
              onToggleMenu={toggle}
              handlers={handlers}
            />
          </li>
        </ul>
      </div>,
    )
    fireEvent.click(screen.getByRole('button', { name: 'کارهای پیام' }))
    expect(open).toBe(true)
    rerender(
      <div className="overflow-y-auto">
        <ul>
          <li className="chat-row">
            <MessageBubble
              message={message(7)}
              isAdmin={false}
              isPinned={false}
              menuOpen
              onToggleMenu={toggle}
              handlers={handlers}
            />
          </li>
        </ul>
      </div>,
    )

    return screen.getByRole('menu').className
  }

  it('پیامِ بالای قاب رو به پایین باز می‌شود، حتی با جای کمِ پایین', () => {
    // بالا ۲۲، پایین ۲۶۰: هیچ‌کدام کافی نیست، ولی بالا کمتر است
    expect(openAt(222, 340)).toContain('top-full')
  })

  it('پیامِ پایینِ قاب رو به بالا باز می‌شود', () => {
    expect(openAt(500, 580)).toContain('bottom-full')
  })
})
