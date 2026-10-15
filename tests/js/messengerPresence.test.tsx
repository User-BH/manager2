import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type {
  ConversationSummary,
  GroupInfo,
  MessagesResponse,
} from '@/features/messaging/messenger/types'

/**
 * حضورِ آنلاین در رابط — «چند نکته»، بندِ ۳.
 *
 * تعریف، پنجره و مرزِ دیدن سمتِ سرورند (`PresenceTest`). این‌جا آنچه فقط رابط خراب می‌کند: نشانِ «آنلاین» سرِ
 * «آنلاین تا» **بی هیچ درخواستِ تازه** خاموش می‌شود (پاسخِ کهنه آنلاینِ کهنه نشان نمی‌دهد)، اختلافِ ساعتِ دستگاه
 * با سرور جبران می‌شود، شمارِ گروه همان عددِ سرور است و کهنه‌اش نمی‌ماند، و بستنِ صفحه یک «آفلاین شدم» ِ
 * keepalive می‌فرستد.
 */

const apiMock = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/api')>('@/shared/lib/api')

  return { ...actual, api: apiMock }
})

const { OnlineDot, OnlineCount, COUNT_FRESH_SECONDS, clockOffset } =
  await import('@/features/messaging/messenger/presence')
const { GroupInfoPanel } = await import('@/features/messaging/messenger/GroupInfoPanel')
const { ConversationThread } = await import('@/features/messaging/messenger/ConversationThread')
const { registerPresenceBeacon } = await import('@/shared/lib/presenceBeacon')

function wrap(ui: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

const nowSeconds = () => Math.floor(Date.now() / 1000)
const online = () => document.querySelectorAll('[data-presence="online"]')

function summary(extra: Partial<ConversationSummary> = {}): ConversationSummary {
  return {
    id: 7,
    type: 'pair',
    title: 'مستأجر ده · مستأجر',
    canPost: true,
    readOnlyReason: null,
    membersCanPost: true,
    unreadCount: 0,
    lastMessage: null,
    ...extra,
  }
}

function thread(conversation: ConversationSummary, extra: Partial<MessagesResponse> = {}) {
  return {
    messages: [],
    hasOlder: false,
    nextCursor: null,
    hiddenIds: [],
    readUpTo: 0,
    conversation,
    canSend: true,
    isAdmin: false,
    serverTime: nowSeconds(),
    ...extra,
  } satisfies MessagesResponse
}

beforeEach(() => apiMock.mockReset())
afterEach(() => vi.useRealTimers())

describe('نشانِ آنلاین', () => {
  it('سرِ «آنلاین تا» بی درخواستِ تازه خاموش می‌شود', () => {
    vi.useFakeTimers()
    render(<OnlineDot until={nowSeconds() + 5} offsetMs={0} />)
    expect(screen.getByRole('img', { name: 'آنلاین' })).toBeInTheDocument()

    act(() => {
      vi.advanceTimersByTime(4_000)
    })
    expect(online()).toHaveLength(1)

    act(() => {
      vi.advanceTimersByTime(1_100)
    })
    expect(online()).toHaveLength(0)
  })

  it('«آنلاین تا» ی گذشته هیچ وقت آنلاین نیست، و null هم نه', () => {
    vi.useFakeTimers()
    render(
      <>
        <OnlineDot until={nowSeconds() - 1} offsetMs={0} />
        <OnlineDot until={null} offsetMs={0} />
      </>,
    )
    act(() => {
      vi.advanceTimersByTime(0)
    })
    expect(online()).toHaveLength(0)
  })

  it('اختلافِ ساعتِ دستگاه با سرور را جبران می‌کند', () => {
    vi.useFakeTimers()
    // ثانیه‌ی کامل، تا گردکردن عدد را جابه‌جا نکند
    vi.setSystemTime(1_800_000_000_000)
    // ساعتِ دستگاه یک دقیقه جلوست: سرور «آنلاین تا ۳۰ ثانیه‌ی دیگر» گفته
    const serverNow = nowSeconds() - 60
    const offset = clockOffset(serverNow)
    expect(offset).toBe(-60_000)

    render(<OnlineDot until={serverNow + 30} offsetMs={offset} />)
    act(() => {
      vi.advanceTimersByTime(0)
    })
    expect(online()).toHaveLength(1)

    act(() => {
      vi.advanceTimersByTime(31_000)
    })
    expect(online()).toHaveLength(0)
  })

  it('شمارِ گروه همان عددِ سرور است و اگر پرسش‌ها بایستند کهنه‌اش نمی‌ماند', () => {
    vi.useFakeTimers()
    render(<OnlineCount count={3} serverTime={nowSeconds()} offsetMs={0} />)
    expect(screen.getByText('۳ آنلاین')).toBeInTheDocument()

    act(() => {
      vi.advanceTimersByTime((COUNT_FRESH_SECONDS + 1) * 1000)
    })
    expect(screen.queryByText('۳ آنلاین')).toBeNull()
  })
})

describe('پیام‌رسان', () => {
  it('سربرگِ گفت‌وگوی یک‌به‌یک «آنلاین» را فقط وقتی سرور گفته نشان می‌دهد', async () => {
    apiMock.mockResolvedValue(thread(summary({ peerOnlineUntil: nowSeconds() + 90 })))
    const { unmount } = wrap(<ConversationThread conversationId={7} />)
    expect(await screen.findByText('آنلاین')).toBeInTheDocument()
    unmount()

    apiMock.mockResolvedValue(thread(summary({ peerOnlineUntil: null })))
    wrap(<ConversationThread conversationId={7} />)
    expect(await screen.findByRole('heading', { name: 'مستأجر ده · مستأجر' })).toBeInTheDocument()
    expect(screen.queryByText('آنلاین')).toBeNull()
  })

  it('سربرگِ گروه شمارِ آنلاینِ سرور را نشان می‌دهد', async () => {
    apiMock.mockResolvedValue(
      thread(summary({ id: 5, type: 'channel', title: 'گروهِ مجتمع' }), { onlineCount: 4 }),
    )
    wrap(<ConversationThread conversationId={5} />)
    expect(await screen.findByText('۴ آنلاین')).toBeInTheDocument()
  })

  it('اطلاعاتِ گروه کنارِ هر عضوِ آنلاین نشان و بالای فهرست شمار دارد', async () => {
    const serverTime = nowSeconds()
    const panel: GroupInfo = {
      conversation: {
        id: 5,
        type: 'channel',
        title: 'گروه',
        membersCanPost: true,
        canManage: false,
      },
      counts: { members: 3, owners: 2, tenants: 0, managers: 1, online: 1 },
      serverTime,
      members: ['الف', 'ب', 'پ'].map((name, i) => ({
        id: i + 1,
        name,
        avatarUrl: null,
        role: 'owner',
        roleLabel: 'مالک',
        unit: null,
        isModerator: false,
        onlineUntil: i === 1 ? serverTime + 90 : null,
      })),
      page: 1,
      hasMore: false,
    }
    apiMock.mockResolvedValue(panel)

    wrap(<GroupInfoPanel conversationId={5} onClose={() => undefined} />)
    expect(await screen.findByText('۱ آنلاین')).toBeInTheDocument()
    expect(online()).toHaveLength(1)
    expect(online()[0]?.closest('li')).toHaveTextContent('ب')
  })
})

describe('بستنِ صفحه', () => {
  it('یک «آفلاین شدم» ِ keepalive با توکنِ CSRF می‌فرستد — و فقط یک بار ثبت می‌شود', () => {
    const fetchMock = vi.fn(() => Promise.resolve(new Response('{}')))
    vi.stubGlobal('fetch', fetchMock)

    registerPresenceBeacon()
    registerPresenceBeacon()
    window.dispatchEvent(new Event('pagehide'))

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/presence/offline')
    expect(init.method).toBe('POST')
    expect(init.keepalive).toBe(true)
    expect(init.credentials).toBe('include')
    expect(Object.keys(init.headers as Record<string, string>)).toContain('X-CSRF-TOKEN')

    vi.unstubAllGlobals()
  })

  it('پوسته‌ی داشبورد همان را ثبت می‌کند — هر صفحه‌ی داشبورد، نه فقط پیام‌رسان', () => {
    const layout = readFileSync(
      join(__dirname, '../../resources/js/shared/layout/DashboardLayout.tsx'),
      'utf8',
    )
    expect(layout).toMatch(/useEffect\(\(\) => registerPresenceBeacon\(\), \[\]\)/)
  })
})
