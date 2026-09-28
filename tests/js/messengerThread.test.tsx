import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  applyReadUpTo,
  markFailed,
  mergeMessages,
  newClientId,
  newestId,
  removeByClientId,
} from '@/features/messaging/messenger/threadState'
import type { ChatMessage } from '@/features/messaging/messenger/types'

/**
 * رشته‌ی گفت‌وگو در فرانت (مرحلهٔ ۶ · فاز ۱۲).
 *
 * سه ادعای اصلی: هیچ پیامی دو بار نیست (حتی وقتی پاسخِ ارسال و پرسشِ دوره‌ای
 * هم‌زمان برسند)، ترتیب همان شناسه‌ی سرور است، و ارسالِ ناموفق با متنش
 * می‌ماند و «دوباره» **همان** `client_id` را می‌فرستد.
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
    authorName: 'x',
    unitLabel: '',
    isMine: false,
    isHidden: false,
    sentAt: '',
    ...extra,
  }
}

describe('ادغامِ پیام‌ها', () => {
  it('هیچ پیامی دو بار نیست و ترتیب شناسه‌ی سرور است', () => {
    const merged = mergeMessages([message(3), message(1)], [message(2), message(3)])

    expect(merged.map((m) => m.id)).toEqual([1, 2, 3])
  })

  it('پاسخِ سرور جای نسخه‌ی خوش‌بینانه را می‌گیرد — نه کنارش', () => {
    const optimistic = message(-5, { clientId: 'c1', isMine: true, status: 'pending' })
    const confirmed = message(10, { clientId: 'c1', isMine: true, status: 'sent' })

    const merged = mergeMessages([message(9), optimistic], [confirmed])

    expect(merged.map((m) => m.id)).toEqual([9, 10])
  })

  it('پیامِ در راه همیشه ته است، حتی وقتی پیامِ تازه‌ای از سرور برسد', () => {
    const optimistic = message(-5, { clientId: 'c1', status: 'pending' })

    expect(mergeMessages([message(1), optimistic], [message(2)]).map((m) => m.id)).toEqual([
      1, 2, -5,
    ])
  })

  it('پیامی که پس از رسیدن پنهان شده، متنش برای ساکن پاک می‌شود', () => {
    const [resident] = mergeMessages([message(1)], [], [1], false)
    const [admin] = mergeMessages([message(1)], [], [1], true)

    expect(resident.body).toBeNull()
    expect(admin.body).toBe('پیام 1')
  })

  /** 🔴 پیامِ قبلاً‌رسیده هم «خوانده شد» می‌شود — پرسشِ since فقط پیامِ تازه می‌آورد. */
  it('پیام‌های من تا readUpTo خوانده‌شده‌اند، نه بیشتر و نه پیامِ دیگران', () => {
    const list = [
      message(1, { isMine: true, status: 'sent' }),
      message(2),
      message(3, { isMine: true, status: 'sent' }),
    ]

    expect(applyReadUpTo(list, 2).map((m) => m.status)).toEqual(['read', undefined, 'sent'])
    expect(applyReadUpTo(list, 0)).toBe(list)
  })

  it('شکست، حذف و نقطه‌ی شروعِ since', () => {
    const list = [message(1), message(-2, { clientId: 'c', status: 'pending' })]

    expect(markFailed(list, 'c')[1].status).toBe('failed')
    expect(removeByClientId(list, 'c').map((m) => m.id)).toEqual([1])
    expect(newestId(list)).toBe(1)
    expect(newClientId()).not.toBe(newClientId())
  })
})

describe('ارسالِ ناموفق و «دوباره»', () => {
  let ConversationThread: typeof import('@/features/messaging/messenger/ConversationThread').ConversationThread

  beforeAll(async () => {
    ;({ ConversationThread } = await import('@/features/messaging/messenger/ConversationThread'))
  }, 60_000)

  beforeEach(() => {
    request.mockReset()
  })

  const thread = {
    messages: [],
    hasOlder: false,
    nextCursor: null,
    hiddenIds: [],
    readUpTo: 0,
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
  }

  it('پیام با متنش می‌ماند و دوباره همان client_id را می‌فرستد', async () => {
    const posts: Array<{ client_id?: string }> = []

    request.mockImplementation((config: { method?: string; data?: { client_id?: string } }) => {
      if (config.method !== 'POST') return Promise.resolve({ status: 200, data: thread })

      posts.push(config.data ?? {})
      if (posts.length === 1) {
        return Promise.reject(
          Object.assign(new Error('خطای سرور'), {
            response: { status: 500, data: { message: 'خطای سرور' }, headers: {} },
            isAxiosError: true,
          }),
        )
      }
      return Promise.resolve({
        status: 201,
        data: {
          message: message(40, {
            body: 'سلام مدیریت',
            isMine: true,
            clientId: config.data?.client_id,
            status: 'sent',
          }),
        },
      })
    })

    render(
      <QueryClientProvider client={new QueryClient()}>
        <ConversationThread conversationId={7} />
      </QueryClientProvider>,
    )

    fireEvent.change(await screen.findByLabelText('متنِ پیام'), {
      target: { value: 'سلام مدیریت' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'ارسال پیام' }))

    // ⚠️ شکست بی‌صدا نیست: متن می‌ماند، وضعیت «ارسال نشد» و دکمه‌ی «دوباره»
    const retry = await screen.findByRole('button', { name: /دوباره/ }, { timeout: 15_000 })
    expect(screen.getByText('سلام مدیریت')).toBeTruthy()
    expect(screen.getByText('ارسال نشد')).toBeTruthy()

    fireEvent.click(retry)

    await waitFor(() => expect(screen.getByText('ارسال شد')).toBeTruthy())
    expect(posts).toHaveLength(2)
    expect(posts[1].client_id).toBe(posts[0].client_id)
    expect(screen.getAllByText('سلام مدیریت')).toHaveLength(1)
  }, 30_000)
})
