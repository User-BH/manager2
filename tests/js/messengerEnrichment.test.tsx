import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { MessageBubble, type BubbleHandlers } from '@/features/messaging/messenger/MessageBubble'
import { isVoiceSupported } from '@/features/messaging/messenger/VoiceRecorder'
import { mergeMessages } from '@/features/messaging/messenger/threadState'
import type { ChatMessage } from '@/features/messaging/messenger/types'

/**
 * غنی‌سازیِ پیام‌رسان در فرانت (مرحلهٔ ۶ · فاز ۱۴).
 *
 * متن همیشه متن است (XSS)، حذف‌شده بی‌محتواست، منو فقط کارهای مجازِ سرور را
 * نشان می‌دهد و با کیبورد کار می‌کند، و تغییرِ پیام‌های از قبل رسیده (ویرایش،
 * حذف، واکنش) جای نسخه‌ی قدیم را می‌گیرد.
 */

const request = vi.fn()

vi.mock('@/shared/lib/http', () => ({
  http: { request: (...args: unknown[]) => request(...args) },
  refreshCsrfToken: () => Promise.resolve(),
  setCsrfToken: vi.fn(),
  TIMEOUT_MS: 20_000,
  UPLOAD_TIMEOUT_MS: 120_000,
}))

function message(id: number, extra: Partial<ChatMessage> = {}): ChatMessage {
  return {
    id,
    body: `پیام ${id}`,
    authorName: 'آلیس',
    unitLabel: '',
    isMine: false,
    isHidden: false,
    sentAt: '',
    ...extra,
  }
}

function handlers(): BubbleHandlers {
  return {
    onReply: vi.fn(),
    onReact: vi.fn(),
    onEdit: vi.fn(),
    onDelete: vi.fn(),
    onPin: vi.fn(),
    onReport: vi.fn(),
    onHide: vi.fn(),
    onCopy: vi.fn(),
    onForward: vi.fn(),
    onDownload: vi.fn(),
    onRetry: vi.fn(),
    onDiscard: vi.fn(),
    onJump: vi.fn(),
    onPollChange: vi.fn(),
  }
}

function bubble(
  m: ChatMessage,
  options: { isAdmin?: boolean; menuOpen?: boolean; on?: BubbleHandlers } = {},
) {
  const on = options.on ?? handlers()
  const toggle = vi.fn()
  const view = render(
    <MessageBubble
      message={m}
      isAdmin={options.isAdmin ?? false}
      isPinned={false}
      menuOpen={options.menuOpen ?? false}
      onToggleMenu={toggle}
      handlers={on}
    />,
  )

  return { ...view, on, toggle }
}

describe('حبابِ پیام', () => {
  /** 🔴 متنِ کاربر هرگز HTML نمی‌شود — نه در خودِ پیام، نه در تکه‌متنِ پاسخ. */
  it('نشانه‌گذاریِ HTML به‌صورتِ متن نمایش داده می‌شود', () => {
    const payload = '<img src=x onerror=alert(1)>'
    const { container } = bubble(
      message(1, {
        body: payload,
        replyTo: { id: 9, authorName: 'باب', excerpt: payload, isDeleted: false },
      }),
    )

    expect(container.querySelector('img')).toBeNull()
    expect(screen.getAllByText(payload)).toHaveLength(2)
  })

  it('پیامِ حذف‌شده بی‌متن و بی‌پیوست است', () => {
    bubble(
      message(2, {
        body: null,
        isDeleted: true,
        attachment: { name: 'leak.jpg', kind: 'image', url: '/x' },
      }),
    )

    expect(screen.getByText(/این پیام حذف شد/)).toBeTruthy()
    expect(screen.queryByText('leak.jpg')).toBeNull()
    expect(screen.queryByText(/پنهان شده/)).toBeNull()
  })

  it('پیامِ صوتی با پخش‌کننده، نه لینکِ دانلود', () => {
    const { container } = bubble(
      message(3, { body: '', attachment: { name: 'voice.webm', kind: 'audio', url: '/a/3' } }),
    )

    expect(container.querySelector('audio')?.getAttribute('src')).toBe('/a/3')
    expect(screen.queryByRole('link')).toBeNull()
  })

  it('واکنش‌ها: فقط شمار، و «مالِ من» با aria-pressed', () => {
    const { on } = bubble(
      message(4, {
        reactions: [
          { emoji: '👍', count: 3, mine: true },
          { emoji: '🙏', count: 1, mine: false },
        ],
      }),
    )

    const mine = screen.getByRole('button', { name: '👍 3' })
    expect(mine.getAttribute('aria-pressed')).toBe('true')
    expect(screen.getByRole('button', { name: '🙏 1' }).getAttribute('aria-pressed')).toBe('false')

    fireEvent.click(mine)
    expect(on.onReact).toHaveBeenCalledWith('👍')
  })

  it('پاسخ به پیامِ پنهان‌شده متنش را نشان نمی‌دهد و کلیکش به هدف می‌پرد', () => {
    const { on } = bubble(
      message(5, { replyTo: { id: 2, authorName: 'باب', excerpt: null, isDeleted: false } }),
    )

    fireEvent.click(screen.getByText('پیامِ در دسترس نیست'))
    expect(on.onJump).toHaveBeenCalledWith(2)
  })

  it('کلیکِ راست منو را باز می‌کند؛ پیامِ در راه منو ندارد', () => {
    const { container, toggle } = bubble(message(6))
    fireEvent.contextMenu(container.firstElementChild!)
    expect(toggle).toHaveBeenCalledWith(true)

    const pending = bubble(message(-1, { status: 'pending' }))
    expect(pending.container.querySelector('[aria-haspopup="menu"]')).toBeNull()
  })
})

describe('منوی پیام', () => {
  const labels = () => screen.queryAllByRole('menuitem').map((item) => item.textContent?.trim())

  it('ساکن روی پیامِ دیگران: پاسخ، واکنش و گزارش — نه ویرایش، حذف، سنجاق یا پنهان', () => {
    bubble(message(10), { menuOpen: true })

    expect(labels()).toContain('پاسخ')
    expect(labels()).toContain('گزارش به مدیریت')
    expect(labels()).not.toContain('ویرایش')
    expect(labels()).not.toContain('حذف')
    expect(labels()).not.toContain('سنجاق')
    expect(labels()).not.toContain('پنهان کردن')
    expect(screen.getAllByRole('menuitem', { name: /^واکنش/ })).toHaveLength(6)
  })

  it('ویرایش و حذف فقط وقتی سرور اجازه داده', () => {
    bubble(message(11, { isMine: true, canEdit: true, canDelete: false }), { menuOpen: true })

    expect(labels()).toContain('ویرایش')
    expect(labels()).not.toContain('حذف')
    expect(labels()).not.toContain('گزارش به مدیریت')
  })

  /** ⚠️ ۱۰/۱۰: «سنجاق» را سرور می‌گوید (`canPin`)، نه «مدیر بودن» — در گفت‌وگوی خصوصی عضو هم سنجاق می‌کند. */
  it('مدیر: سنجاق و پنهان کردن، بی‌گزارش', () => {
    bubble(message(12, { canPin: true }), { menuOpen: true, isAdmin: true })

    expect(labels()).toContain('سنجاق')
    expect(labels()).toContain('پنهان کردن')
    expect(labels()).not.toContain('گزارش به مدیریت')
  })

  it('سنجاق همان است که سرور اجازه داده، برای عضو هم', () => {
    bubble(message(15, { canPin: true }), { menuOpen: true })
    expect(labels()).toContain('سنجاق')
  })

  it('بی اجازه‌ی سرور «سنجاق» نیست، حتی برای مدیر', () => {
    bubble(message(16, { canPin: false }), { menuOpen: true, isAdmin: true })
    expect(labels()).not.toContain('سنجاق')
  })

  it('پیامِ حذف‌شده جز «پاسخ»ی هم ندارد — هیچ کاری', () => {
    bubble(message(13, { body: null, isDeleted: true }), { menuOpen: true })

    expect(labels()).toEqual([])
  })

  it('با کیبورد: فوکوس روی اولین گزینه، ↓ جابه‌جا می‌کند، Escape می‌بندد', () => {
    const { toggle } = bubble(message(14), { menuOpen: true })
    const items = screen.getAllByRole('menuitem')

    expect(document.activeElement).toBe(items[0])
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'ArrowDown' })
    expect(document.activeElement).toBe(items[1])

    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' })
    expect(toggle).toHaveBeenCalledWith(false)
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'کارهای پیام' }))
  })
})

describe('هم‌رسانیِ تغییرها', () => {
  it('نسخه‌ی تازه‌ی پیام (ویرایش، حذف، واکنش) جای قدیمی را می‌گیرد، نه کنارش', () => {
    const current = [message(1, { body: 'سلم' }), message(2)]
    const merged = mergeMessages(current, [
      message(1, { body: 'سلام', isEdited: true }),
      message(2, { body: null, isDeleted: true }),
    ])

    expect(merged).toHaveLength(2)
    expect(merged[0]).toMatchObject({ body: 'سلام', isEdited: true })
    expect(merged[1]).toMatchObject({ body: null, isDeleted: true })
  })

  it('مرورگری که MediaRecorder ندارد دکمه‌ی ضبط نمی‌بیند', () => {
    expect(isVoiceSupported()).toBe(false)
  })
})

describe('نوشتن', () => {
  let ConversationThread: typeof import('@/features/messaging/messenger/ConversationThread').ConversationThread

  beforeAll(async () => {
    ;({ ConversationThread } = await import('@/features/messaging/messenger/ConversationThread'))
  }, 60_000)

  // ⚠️ بدنه‌ی بلوکی: تابعی که از beforeEach برگردد، Vitest آن را «پاک‌سازی» می‌شمارد و بی‌آرگومان صدا می‌زند
  beforeEach(() => {
    request.mockReset()
  })

  const thread = (messages: ChatMessage[]) => ({
    messages,
    hasOlder: false,
    nextCursor: null,
    hiddenIds: [],
    readUpTo: 0,
    changed: [],
    serverTime: 1000,
    pinned: null,
    conversation: {
      id: 7,
      type: 'unit',
      title: 'مدیریتِ ساختمان',
      canPost: true,
      membersCanPost: true,
      unreadCount: 0,
      lastMessage: null,
    },
    canSend: true,
    isAdmin: false,
  })

  function mount() {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ConversationThread conversationId={7} />
      </QueryClientProvider>,
    )
  }

  it('پاسخ: شناسه‌ی هدف با پیام می‌رود و پیش‌نمایشِ پاسخ پاک می‌شود', async () => {
    const posts: Array<Record<string, unknown>> = []
    request.mockImplementation((config: { method?: string; data?: Record<string, unknown> }) => {
      if (config.method !== 'POST') {
        return Promise.resolve({ status: 200, data: thread([message(20, { body: 'آسانسور؟' })]) })
      }
      posts.push(config.data ?? {})
      return Promise.resolve({
        status: 201,
        data: { message: message(21, { body: 'فردا', isMine: true, status: 'sent' }) },
      })
    })

    mount()
    fireEvent.contextMenu((await screen.findByText('آسانسور؟')).closest('.relative')!)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'پاسخ' }))
    expect(screen.getByText(/پاسخ به آلیس/)).toBeTruthy()

    fireEvent.change(screen.getByLabelText('متنِ پیام'), { target: { value: 'فردا' } })
    fireEvent.click(screen.getByRole('button', { name: 'ارسال پیام' }))

    await waitFor(() => expect(posts).toHaveLength(1))
    expect(posts[0]).toMatchObject({ body: 'فردا', reply_to_id: '20' })
    await waitFor(() => expect(screen.queryByText(/پاسخ به آلیس/)).toBeNull())
  })

  /**
   * 🔴 ویرایش و حذفِ طرفِ مقابل با پرسشِ دوره‌ای جای نسخه‌ی قدیم را می‌گیرد.
   *
   * خرابکاری نشان داد اگر رشته `changed` را نادیده بگیرد هیچ تستی قرمز نمی‌شد:
   * فقط تابعِ خالصِ ادغام آزموده شده بود، نه اینکه رشته آن را به کار می‌برد.
   */
  it('تغییرِ پیام‌های رسیده با پرسشِ بعدی روی صفحه می‌نشیند', async () => {
    const urls: string[] = []
    request.mockImplementation((config: { url?: string }) => {
      urls.push(config.url ?? '')
      const polled = (config.url ?? '').includes('since=')
      return Promise.resolve({
        status: 200,
        data: polled
          ? {
              ...thread([]),
              changed: [
                message(50, { body: 'سلام', isEdited: true }),
                message(51, { body: null, isDeleted: true }),
              ],
              serverTime: 2000,
            }
          : thread([message(50, { body: 'سلم' }), message(51, { body: 'پیامی که حذف می‌شود' })]),
      })
    })

    mount()
    expect(await screen.findByText('سلم')).toBeTruthy()

    // بازگشت به تب همان لحظه می‌پرسد — بی‌انتظارِ ۸ ثانیه
    document.dispatchEvent(new Event('visibilitychange'))

    expect(await screen.findByText('سلام')).toBeTruthy()
    expect(screen.queryByText('سلم')).toBeNull()
    expect(screen.queryByText('پیامی که حذف می‌شود')).toBeNull()
    expect(screen.getByText(/این پیام حذف شد/)).toBeTruthy()
    expect(urls.some((url) => url.includes('changed_after=998'))).toBe(true)
  })

  it('ویرایش: متنِ همان پیام در کادر، و PATCH با متنِ تازه', async () => {
    const patches: Array<{ url?: string; data?: unknown }> = []
    request.mockImplementation((config: { method?: string; url?: string; data?: unknown }) => {
      if (config.method === 'PATCH') {
        patches.push(config)
        return Promise.resolve({
          status: 200,
          data: {
            message: message(30, { body: 'سلام', isMine: true, isEdited: true, canEdit: true }),
          },
        })
      }
      return Promise.resolve({
        status: 200,
        data: thread([message(30, { body: 'سلم', isMine: true, canEdit: true, status: 'sent' })]),
      })
    })

    mount()
    fireEvent.contextMenu((await screen.findByText('سلم')).closest('.relative')!)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'ویرایش' }))

    const field = screen.getByLabelText<HTMLTextAreaElement>('متنِ پیام')
    await waitFor(() => expect(field.value).toBe('سلم'))

    fireEvent.change(field, { target: { value: 'سلام' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره‌ی ویرایش' }))

    await waitFor(() => expect(patches).toHaveLength(1))
    expect(patches[0].url).toBe('/messenger/conversations/7/messages/30')
    expect(patches[0].data).toEqual({ body: 'سلام' })
    expect(await screen.findByText('ویرایش‌شده')).toBeTruthy()
  })
})
