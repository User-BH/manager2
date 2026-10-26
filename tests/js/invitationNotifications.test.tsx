import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CurrentUser, NotificationItem } from '@/shared/types'

/**
 * اعلانِ دعوت در رابط — مرحلهٔ ۱۳ · فاز ۵ (ایرادات ۱۱، بندهای ۳.۴، ۳.۶، ۳.۹ و ۵.۱۳).
 *
 * نشانِ آدمک از عددِ سرور (`/me`، و پس از آن از پاسخِ زنگوله)، «دعوت‌ها» و اعلانِ دعوت از داشبوردِ مجتمع با زمینه‌ی اولیه
 * به صفحه‌ی دعوت‌ها می‌برند و در داشبوردِ اولیه مستقیم، و صفحه‌ی دعوت‌ها جزئیاتِ واقعی یا حالتِ خالی را دارد. قاعده‌ی
 * قطعی در سرور است (`InvitationNotificationTest`).
 */

const apiMock = vi.hoisted(() => vi.fn())
const replace = vi.hoisted(() => vi.fn())
vi.mock('@/shared/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/api')>('@/shared/lib/api')
  return { ...actual, api: apiMock }
})
vi.mock('@/shared/pwa/registerServiceWorker', () => ({ clearApiCache: () => Promise.resolve() }))
vi.mock('@/shared/lib/authChannel', async () => {
  const actual = await vi.importActual<typeof import('@/shared/lib/authChannel')>(
    '@/shared/lib/authChannel',
  )
  return { ...actual, announceAuth: vi.fn() }
})

type Modules = {
  UserMenu: (typeof import('@/shared/layout/UserMenu'))['UserMenu']
  NotificationBell: (typeof import('@/shared/layout/NotificationBell'))['NotificationBell']
  RegistrantInvitationsPage: (typeof import('@/features/registrant/RegistrantInvitationsPage'))['RegistrantInvitationsPage']
  useAuthStore: (typeof import('@/shared/stores/authStore'))['useAuthStore']
  useNotificationStore: (typeof import('@/shared/stores/notificationStore'))['useNotificationStore']
}
let m: Modules

beforeAll(async () => {
  vi.stubGlobal('location', { ...window.location, replace })
  const [
    { UserMenu },
    { NotificationBell },
    { RegistrantInvitationsPage },
    { useAuthStore },
    { useNotificationStore },
  ] = await Promise.all([
    import('@/shared/layout/UserMenu'),
    import('@/shared/layout/NotificationBell'),
    import('@/features/registrant/RegistrantInvitationsPage'),
    import('@/shared/stores/authStore'),
    import('@/shared/stores/notificationStore'),
  ])
  m = { UserMenu, NotificationBell, RegistrantInvitationsPage, useAuthStore, useNotificationStore }
}, 30_000)

const user = (extra: Partial<CurrentUser> = {}): CurrentUser => ({
  id: 9,
  name: 'سارا مستأجر',
  firstName: 'سارا',
  lastName: 'مستأجر',
  needsName: false,
  phone: '09127775501',
  role: 'tenant',
  roleLabel: 'مستاجر',
  isAdmin: false,
  isSuperAdmin: false,
  complex: { id: 1, name: 'مجتمع مسکونی آفتاب', type: 'residential' } as CurrentUser['complex'],
  accountState: 'member',
  dashboard: 'unit',
  canWrite: true,
  pendingInvitations: 1,
  unreadInvitations: 1,
  memberships: [],
  ...extra,
})

const initial = (extra: Partial<CurrentUser> = {}) =>
  user({ accountState: 'initial', dashboard: 'initial', complex: null, canWrite: false, ...extra })

const invitationItem: NotificationItem = {
  id: 'n:abc',
  kind: 'personal',
  title: 'دعوت به «مجتمع تجاری نفیس»',
  excerpt: 'کاوه مدیری، مدیرِ «مجتمع تجاری نفیس»، شما را دعوت کرده است.',
  isPinned: false,
  isRead: false,
  publishedAt: '۱۴۰۵/۰۸/۰۴',
  link: '/invitations',
}

let where = ''
function Where() {
  const location = useLocation()
  where = location.pathname
  return null
}

function show(node: React.ReactNode, who: CurrentUser) {
  m.useAuthStore.setState({ user: who, isLoading: false })
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route path="*" element={node} />
        </Routes>
        <Where />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiMock.mockReset().mockImplementation((path: string) => {
    if (path.startsWith('/notifications'))
      return Promise.resolve({ unreadCount: 1, unreadInvitations: 1, items: [invitationItem] })
    return Promise.resolve({})
  })
  replace.mockReset()
  m.useNotificationStore.setState({ unreadInvitations: null, unreadCount: 0, items: [] })
})

describe('نشانِ آدمک', () => {
  it('عددِ سرور روی آدمک و در نامِ دسترس‌پذیرِ دکمه', () => {
    show(<m.UserMenu />, user({ unreadInvitations: 2 }))

    expect(screen.getByTestId('invitation-badge').textContent).toBe('۲')
    expect(
      screen.getByRole('button', { name: /^حساب کاربری: سارا مستأجر، ۲ دعوتِ تازه$/ }),
    ).toBeInTheDocument()
  })

  it('بی دعوتِ تازه نشانی نیست؛ سوپر ادمین هرگز', () => {
    const { unmount } = show(<m.UserMenu />, user({ unreadInvitations: 0 }))
    expect(screen.queryByTestId('invitation-badge')).toBeNull()
    unmount()

    show(
      <m.UserMenu />,
      user({
        isSuperAdmin: true,
        role: 'super_admin',
        dashboard: 'platform',
        unreadInvitations: 3,
      }),
    )
    expect(screen.queryByTestId('invitation-badge')).toBeNull()
  })

  it('پاسخِ زنگوله نشان را بی بارگذاریِ دوباره به‌روز می‌کند (خواندنِ اعلان)', () => {
    show(<m.UserMenu />, user({ unreadInvitations: 1 }))
    expect(screen.getByTestId('invitation-badge')).toBeInTheDocument()

    act(() => m.useNotificationStore.setState({ unreadInvitations: 0 }))
    expect(screen.queryByTestId('invitation-badge')).toBeNull()
  })

  it('در داشبوردِ اولیه هم', () => {
    show(<m.UserMenu />, initial())
    expect(screen.getByTestId('invitation-badge').textContent).toBe('۱')
  })
})

describe('رفتن به دعوت‌ها', () => {
  it('از داشبوردِ مجتمع: «دعوت‌ها» با زمینه‌ی اولیه به صفحه‌ی دعوت‌ها', async () => {
    show(<m.UserMenu />, user())
    fireEvent.click(screen.getAllByRole('button')[0])
    fireEvent.click(await screen.findByText('دعوت‌ها (1)'))

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/invitations'))
    expect(apiMock).toHaveBeenCalledWith('/account/context/initial', { method: 'POST' })
  })

  it('در داشبوردِ اولیه: مستقیم، بی تعویضِ زمینه', async () => {
    show(<m.UserMenu />, initial())
    fireEvent.click(screen.getAllByRole('button')[0])
    fireEvent.click(await screen.findByText('دعوت‌ها (1)'))

    expect(where).toBe('/invitations')
    expect(apiMock).not.toHaveBeenCalledWith('/account/context/initial', expect.anything())
  })

  it('اعلانِ دعوت در زنگوله‌ی داشبوردِ مجتمع: خوانده و با زمینه‌ی اولیه باز می‌شود', async () => {
    show(<m.NotificationBell />, user())
    // زنگوله یک بار برای کلِ برنامه راه می‌افتد؛ فهرست مستقیم، مستقل از آزمونِ قبلی
    act(() => m.useNotificationStore.setState({ items: [invitationItem], unreadCount: 1 }))
    fireEvent.click(screen.getByRole('button', { name: /اعلان/ }))
    fireEvent.click(await screen.findByText('دعوت به «مجتمع تجاری نفیس»'))

    // ورود به زمینه‌ی اولیه از سرور (ناوبریِ کاملِ پس از آن را ccountContext.test.tsx می‌سنجد)
    await waitFor(() =>
      expect(apiMock).toHaveBeenCalledWith('/account/context/initial', { method: 'POST' }),
    )
    expect(apiMock).toHaveBeenCalledWith('/notifications/personal/abc/read', { method: 'POST' })
  })

  it('همان اعلان در داشبوردِ اولیه: ناوبریِ درونی', async () => {
    show(<m.NotificationBell />, initial())
    act(() => m.useNotificationStore.setState({ items: [invitationItem], unreadCount: 1 }))
    fireEvent.click(screen.getByRole('button', { name: /اعلان/ }))
    fireEvent.click(await screen.findByText('دعوت به «مجتمع تجاری نفیس»'))

    await waitFor(() => expect(where).toBe('/invitations'))
    expect(apiMock).not.toHaveBeenCalledWith('/account/context/initial', expect.anything())
  })
})

/*
 * 🔴 یافته‌ی ۱۳/۵: زنگوله با hover و focus باز می‌شد و کلیک فقط `toggle` بود. کلیکِ پس از hover (دسکتاپ) و ضربه‌ی لمسی
 * (focus پیش از click) همان باکسی را که لحظه‌ای پیش باز شده بود می‌بست — روی موبایل زنگوله با ضربه باز نمی‌ماند.
 */
describe('زنگوله با کلیک و ضربه باز می‌ماند', () => {
  // خودِ دکمه‌ی زنگوله — پس از باز شدن، دکمه‌های درونِ باکس هم «اعلان» در نامشان دارند
  const bell = () => screen.getAllByRole('button', { name: /اعلان/ })[0]

  it('ضربه‌ی لمسی (focus سپس click) باز می‌کند، نه باز-و-بسته', () => {
    show(<m.NotificationBell />, user())
    act(() => m.useNotificationStore.setState({ items: [invitationItem], unreadCount: 1 }))

    fireEvent.focus(bell())
    fireEvent.click(bell())
    expect(bell().getAttribute('aria-expanded')).toBe('true')
  })

  it('کلیک پس از hover هم باز می‌ماند؛ کلیکِ بعدی می‌بندد', () => {
    show(<m.NotificationBell />, user())

    fireEvent.mouseEnter(bell().parentElement!)
    fireEvent.click(bell())
    expect(bell().getAttribute('aria-expanded')).toBe('true')

    // بسته‌شدن با کلیکِ دوم (پس از مهلتِ کوتاهِ «تازه باز شده»)
    vi.useFakeTimers()
    try {
      vi.advanceTimersByTime(1000)
      fireEvent.click(bell())
      expect(bell().getAttribute('aria-expanded')).toBe('false')
    } finally {
      vi.useRealTimers()
    }
  })
})

describe('صفحه‌ی دعوت‌های داشبوردِ اولیه', () => {
  it('جزئیاتِ واقعیِ هر دعوت با پیوستن و رد', async () => {
    apiMock.mockImplementation((path: string) =>
      Promise.resolve(
        path === '/invitations'
          ? {
              data: [
                {
                  id: 7,
                  complexName: 'مجتمع تجاری نفیس',
                  complexAddress: null,
                  unitLabel: 'واحد ن-۴',
                  roleLabel: 'مستاجر',
                  invitedBy: 'کاوه مدیری',
                  createdAt: null,
                },
              ],
            }
          : {},
      ),
    )
    show(<m.RegistrantInvitationsPage />, initial())

    const card = (await screen.findByText('مجتمع تجاری نفیس')).closest('div')!.parentElement!
    expect(card.textContent).toContain('واحد ن-۴')
    expect(card.textContent).toContain('به عنوان مستاجر')
    expect(card.textContent).toContain('کاوه مدیری')
    expect(within(card.parentElement!).getByRole('button', { name: /پیوستن/ })).toBeInTheDocument()
    expect(within(card.parentElement!).getByRole('button', { name: /رد/ })).toBeInTheDocument()
  })

  it('بی دعوت: حالتِ خالیِ روشن، نه صفحه‌ی سفید', async () => {
    apiMock.mockImplementation(() => Promise.resolve({ data: [] }))
    show(<m.RegistrantInvitationsPage />, initial({ pendingInvitations: 0 }))

    expect(await screen.findByText('دعوتِ در انتظاری ندارید.')).toBeInTheDocument()
  })
})
