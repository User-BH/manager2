import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type {
  ConversationSummary,
  GroupInfo,
  MessagesResponse,
} from '@/features/messaging/messenger/types'

/**
 * ساختارِ پیام‌رسان در رابط — مرحلهٔ ۱۰ · فاز ۹ (بندهای ۳۵، ۳۶، ۳۷ و ۷۰ ِ فایلِ معماری).
 *
 * قاعده‌ها در سرورند (`MessengerStructureTest`، `GroupInfoTest`). این‌جا آنچه فقط رابط خراب می‌کند: کلیک روی نامِ
 * گروه اطلاعاتش را باز می‌کند و شمار را همان‌طور که سرور گفت نشان می‌دهد، «اعضای بیشتر» صفحه‌ی بعد را می‌آورد،
 * مدیر «فقط مدیریت بنویسد» را می‌فرستد و ساکن آن را نمی‌بیند، گفت‌وگوی فقط‌خواندنی دلیلِ خودش را می‌گوید، و
 * گفت‌وگوی تازه با **یک عضو** است، نه با واحد.
 */

const apiMock = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/api')>('@/shared/lib/api')

  return { ...actual, api: apiMock }
})

const { GroupInfoPanel } = await import('@/features/messaging/messenger/GroupInfoPanel')
const { ConversationThread } = await import('@/features/messaging/messenger/ConversationThread')
const { NewConversationDialog } =
  await import('@/features/messaging/messenger/NewConversationDialog')

function wrap(ui: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

function info(page: number, extra: Partial<GroupInfo> = {}): GroupInfo {
  return {
    conversation: {
      id: 5,
      type: 'channel',
      title: 'گروهِ مجتمع آفتاب',
      membersCanPost: true,
      canManage: false,
    },
    counts: { members: 52, owners: 30, tenants: 21, managers: 1 },
    members: Array.from({ length: page === 1 ? 50 : 2 }, (_, i) => ({
      id: page * 100 + i,
      name: `عضو ${page}-${i}`,
      avatarUrl: null,
      role: 'owner',
      roleLabel: 'مالک',
      unit: `واحد ${i + 1}`,
      isModerator: false,
    })),
    page,
    hasMore: page === 1,
    ...extra,
  }
}

function conversation(extra: Partial<ConversationSummary> = {}): ConversationSummary {
  return {
    id: 5,
    type: 'channel',
    title: 'گروهِ مجتمع آفتاب',
    canPost: true,
    readOnlyReason: null,
    membersCanPost: true,
    unreadCount: 0,
    lastMessage: null,
    ...extra,
  }
}

function thread(summary: ConversationSummary): MessagesResponse {
  return {
    messages: [],
    hasOlder: false,
    nextCursor: null,
    hiddenIds: [],
    readUpTo: 0,
    conversation: summary,
    canSend: summary.canPost,
    isAdmin: false,
  }
}

beforeEach(() => {
  apiMock.mockReset()
})

describe('اطلاعاتِ گروه', () => {
  it('شمار و فهرست را از سرور نشان می‌دهد و «اعضای بیشتر» صفحه‌ی بعد را می‌آورد', async () => {
    apiMock.mockImplementation((url: string) =>
      Promise.resolve(info(Number(new URL(url, 'http://x').searchParams.get('page')))),
    )

    wrap(<GroupInfoPanel conversationId={5} onClose={() => undefined} />)

    const list = await screen.findByRole('list', { name: 'اعضای گروه' })
    expect(list.querySelectorAll('li')).toHaveLength(50)
    expect(screen.getByText('۵۲', { selector: 'dd' })).toBeInTheDocument()
    expect(screen.getByText('۳۰', { selector: 'dd' })).toBeInTheDocument()
    expect(screen.getByText('۲۱', { selector: 'dd' })).toBeInTheDocument()
    expect(screen.getByText('مالک · واحد 1')).toBeInTheDocument()
    // ساکن «فقط مدیریت» را نمی‌گذارد
    expect(screen.queryByRole('checkbox')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'اعضای بیشتر' }))
    await waitFor(() => expect(list.querySelectorAll('li')).toHaveLength(52))
    expect(screen.queryByRole('button', { name: 'اعضای بیشتر' })).toBeNull()
  })

  it('مدیر «فقط مدیریت بنویسد» را می‌فرستد', async () => {
    apiMock.mockImplementation((url: string) =>
      url.endsWith('/posting')
        ? Promise.resolve({})
        : Promise.resolve(
            info(1, {
              hasMore: false,
              conversation: { ...info(1).conversation, canManage: true },
            }),
          ),
    )

    wrap(<GroupInfoPanel conversationId={5} onClose={() => undefined} />)

    fireEvent.click(await screen.findByRole('checkbox', { name: 'فقط مدیریت در این گروه بنویسد' }))

    await waitFor(() =>
      expect(apiMock).toHaveBeenCalledWith('/messenger/conversations/5/posting', {
        method: 'POST',
        body: { members_can_post: false },
      }),
    )
  })
})

describe('گفت‌وگو', () => {
  it('کلیک روی نامِ گروه اطلاعاتِ گروه را باز می‌کند', async () => {
    apiMock.mockImplementation((url: string) =>
      url.includes('/info')
        ? Promise.resolve(info(1, { hasMore: false }))
        : Promise.resolve(thread(conversation())),
    )

    wrap(<ConversationThread conversationId={5} />)

    fireEvent.click(await screen.findByRole('button', { name: 'اطلاعاتِ گروهِ مجتمع آفتاب' }))
    expect(await screen.findByRole('list', { name: 'اعضای گروه' })).toBeInTheDocument()
  })

  it('گفت‌وگوی فقط‌خواندنی دلیلِ خودش را می‌گوید', async () => {
    const reason = 'طرفِ دیگرِ این گفت‌وگو دیگر ساکنِ این واحد نیست؛ تاریخچه فقط خواندنی است.'
    apiMock.mockImplementation(() =>
      Promise.resolve(
        thread(
          conversation({
            type: 'pair',
            title: 'مستأجر · مستأجر',
            canPost: false,
            readOnlyReason: reason,
          }),
        ),
      ),
    )

    wrap(<ConversationThread conversationId={9} />)

    expect(await screen.findByText(reason)).toBeInTheDocument()
    expect(screen.getByText('خصوصی')).toBeInTheDocument()
    // گفت‌وگوی دونفره «اطلاعاتِ گروه» ندارد
    expect(screen.queryByRole('button', { name: /اطلاعاتِ/ })).toBeNull()
  })
})

describe('گفت‌وگوی تازه', () => {
  it('خصوصی با یک عضو است، نه با واحد', async () => {
    apiMock.mockResolvedValue({
      conversation: conversation({ id: 12, type: 'user', title: 'سارا' }),
    })
    const created = vi.fn()

    wrap(
      <NewConversationDialog
        units={[{ id: 1, label: 'واحد 10' }]}
        members={[{ id: 7, label: 'سارا · مستاجر واحد 25' }]}
        onClose={() => undefined}
        onCreated={created}
      />,
    )

    fireEvent.change(screen.getByLabelText('عضو'), { target: { value: '7' } })
    fireEvent.click(screen.getByRole('button', { name: 'باز کن' }))

    await waitFor(() => expect(created).toHaveBeenCalled())
    expect(apiMock).toHaveBeenCalledWith('/messenger/conversations', {
      method: 'POST',
      body: { type: 'user', user_id: 7 },
    })
  })
})
