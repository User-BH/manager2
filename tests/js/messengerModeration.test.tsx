import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type {
  ChatMessage,
  ConversationSummary,
  GroupInfo,
  MessagesResponse,
} from '@/features/messaging/messenger/types'

/**
 * اقدام‌های پیام و ناظرِ گروه در رابط — مرحلهٔ ۱۰ · فاز ۱۰ (بندهای ۵۰، ۵۲، ۵۵، ۵۶ و ۵۸).
 *
 * ماتریس در سرور است (`GroupModerationTest`). این‌جا: منو کپی، هدایت و دانلود دارد؛ هدایت فقط به گفت‌وگوهای نوشتنی
 * و با `client_id`؛ پیامِ هدایت‌شده و حذف‌شده به دستِ مدیریت خودشان را می‌گویند؛ چند سنجاق یکی‌یکی؛ مدیر ناظر تعیین
 * می‌کند و عضو نه.
 */

const apiMock = vi.hoisted(() => vi.fn())
const copyMock = vi.hoisted(() => vi.fn(() => Promise.resolve(true)))

vi.mock('@/shared/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/api')>('@/shared/lib/api')
  return { ...actual, api: apiMock }
})
vi.mock('@/shared/lib/clipboard', () => ({ copyText: copyMock }))

const { ConversationThread } = await import('@/features/messaging/messenger/ConversationThread')
const { GroupInfoPanel } = await import('@/features/messaging/messenger/GroupInfoPanel')

function wrap(ui: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

function message(id: number, extra: Partial<ChatMessage> = {}): ChatMessage {
  return {
    id,
    body: `پیام ${id}`,
    authorName: 'سارا',
    unitLabel: '',
    isMine: false,
    isHidden: false,
    sentAt: '',
    canForward: true,
    canPin: true,
    ...extra,
  }
}

function summary(id: number, extra: Partial<ConversationSummary> = {}): ConversationSummary {
  return {
    id,
    type: 'channel',
    title: `گفت‌وگو ${id}`,
    canPost: true,
    readOnlyReason: null,
    membersCanPost: true,
    unreadCount: 0,
    lastMessage: null,
    ...extra,
  }
}

function thread(messages: ChatMessage[], pins: ChatMessage[] = []): MessagesResponse {
  return {
    messages,
    hasOlder: false,
    nextCursor: null,
    hiddenIds: [],
    readUpTo: 0,
    pins,
    conversation: summary(5),
    canSend: true,
    isAdmin: false,
  }
}

beforeEach(() => {
  apiMock.mockReset()
  // jsdom پرش به پیام را ندارد
  Element.prototype.scrollIntoView = vi.fn()
  copyMock.mockClear()
})

describe('منوی پیام', () => {
  it('کپی، هدایت و دانلود — و هدایت فقط به گفت‌وگوهای نوشتنی، با client_id', async () => {
    const withFile = message(7, {
      attachment: { name: 'plan.pdf', kind: 'file', url: '/api/v1/messenger/7/attachment' },
    })
    apiMock.mockImplementation((url: string) => {
      if (url.startsWith('/messenger/conversations?')) {
        return Promise.resolve({
          data: [
            summary(5, { title: 'همین گفت‌وگو' }),
            summary(8, { title: 'ذخیره‌شده', type: 'saved' }),
            summary(9, { title: 'بایگانی', type: 'unit', canPost: false }),
          ],
          hasMore: false,
          nextCursor: null,
          canSend: true,
          reason: null,
          isAdmin: false,
          unreadTotal: 0,
          units: [],
          members: [],
        })
      }
      if (url.endsWith('/forward')) return Promise.resolve({ message: message(70) })
      return Promise.resolve(thread([withFile]))
    })

    wrap(<ConversationThread conversationId={5} />)

    fireEvent.contextMenu(await screen.findByText('پیام 7'))
    expect(screen.getByRole('menuitem', { name: 'دانلود' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('menuitem', { name: 'کپی' }))
    await waitFor(() => expect(copyMock).toHaveBeenCalledWith('پیام 7'))

    fireEvent.contextMenu(screen.getByText('پیام 7'))
    fireEvent.click(screen.getByRole('menuitem', { name: 'هدایت' }))
    const targets = await screen.findByRole('list', { name: 'مقصدِ هدایت' })
    expect(targets.textContent).toContain('ذخیره‌شده')
    expect(targets.textContent).not.toContain('همین گفت‌وگو')
    expect(targets.textContent).not.toContain('بایگانی')

    fireEvent.click(screen.getByRole('button', { name: 'ذخیره‌شده' }))
    await waitFor(() =>
      expect(apiMock).toHaveBeenCalledWith('/messenger/conversations/8/forward', {
        method: 'POST',
        body: { message_id: 7, client_id: expect.stringMatching(/^fw-7-/) },
      }),
    )
  })

  it('پیامِ هدایت‌شده و حذف‌شده به دستِ مدیریت خودشان را می‌گویند', async () => {
    apiMock.mockResolvedValue(
      thread([
        message(1, { forwardedFrom: { authorName: 'مالکِ ده' } }),
        message(2, { body: null, isDeleted: true, removedByModeration: true }),
      ]),
    )

    wrap(<ConversationThread conversationId={5} />)

    expect(await screen.findByText('هدایت‌شده از مالکِ ده')).toBeInTheDocument()
    expect(
      screen.getByText('این پیام به دستِ مدیریتِ گروه حذف شد.', { exact: false }),
    ).toBeInTheDocument()
  })

  it('چند سنجاق یکی‌یکی', async () => {
    apiMock.mockResolvedValue(thread([message(1), message(2)], [message(2), message(1)]))

    wrap(<ConversationThread conversationId={5} />)

    expect(await screen.findByText('۱ از ۲')).toBeInTheDocument()
    fireEvent.click(screen.getByText('۱ از ۲'))
    expect(await screen.findByText('۲ از ۲')).toBeInTheDocument()
  })
})

describe('ناظرِ گروه', () => {
  function info(canManage: boolean): GroupInfo {
    return {
      conversation: { id: 5, type: 'channel', title: 'گروه', membersCanPost: true, canManage },
      counts: { members: 2, owners: 1, tenants: 0, managers: 1 },
      members: [
        {
          id: 1,
          name: 'مدیر',
          avatarUrl: null,
          role: 'complex_admin',
          roleLabel: 'مدیر',
          unit: null,
          isModerator: false,
        },
        {
          id: 2,
          name: 'سارا',
          avatarUrl: null,
          role: 'owner',
          roleLabel: 'مالک',
          unit: 'واحد 10',
          isModerator: false,
        },
      ],
      page: 1,
      hasMore: false,
    }
  }

  it('مدیر برای مالک و مستأجر «ناظر کن» دارد، نه برای مدیر', async () => {
    apiMock.mockImplementation((url: string) =>
      url.endsWith('/moderators')
        ? Promise.resolve({ moderators: [2] })
        : Promise.resolve(info(true)),
    )

    wrap(<GroupInfoPanel conversationId={5} onClose={() => undefined} />)

    const buttons = await screen.findAllByRole('button', { name: 'ناظر کن' })
    expect(buttons).toHaveLength(1)
    fireEvent.click(buttons[0])
    await waitFor(() =>
      expect(apiMock).toHaveBeenCalledWith('/messenger/conversations/5/moderators', {
        method: 'POST',
        body: { user_id: 2, moderator: true },
      }),
    )
  })

  it('عضو ناظر تعیین نمی‌کند', async () => {
    apiMock.mockResolvedValue(info(false))

    wrap(<GroupInfoPanel conversationId={5} onClose={() => undefined} />)

    await screen.findByRole('list', { name: 'اعضای گروه' })
    expect(screen.queryByRole('button', { name: 'ناظر کن' })).toBeNull()
  })
})
