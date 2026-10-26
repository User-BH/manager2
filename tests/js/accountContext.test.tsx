import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import type { CurrentUser, MembershipSummary } from '@/shared/types'

/**
 * زمینه‌ی مجتمع در کلاینت — مرحلهٔ ۱۰ · فاز ۴ (بندهای ۱۴، ۸۳، ۸۵ و ۱۲۲ ِ فایلِ معماری).
 *
 * ① تعویض: سرور اول، بعد پاک‌کردنِ کشِ API، خبر به تب‌های دیگر و ناوبریِ کامل — و اگر
 *    سرور رد کند، هیچ‌کدام.
 * ② کش: همان کلید در دو مجتمع دو ورودیِ جدا است؛ کشِ الف در ب پاسخ داده نمی‌شود.
 * ③ رابط: «مجتمع‌های من» فقط برای حسابِ چندمجتمعی؛ حسابِ تک‌مجتمعی همان منوی قبلی.
 */

const membership = (
  complexId: number,
  current: boolean,
  role: 'owner' | 'tenant' | 'complex_admin' = 'owner',
): MembershipSummary => ({
  complexId,
  complexName: `مجتمع ${complexId}`,
  role,
  roleLabel: role === 'complex_admin' ? 'مدیر' : 'مالک',
  status: 'active',
  current,
})

const user = (memberships: MembershipSummary[], extra: Partial<CurrentUser> = {}): CurrentUser => ({
  id: 7,
  name: 'علی',
  firstName: 'علی',
  lastName: 'دوعضوی',
  needsName: false,
  phone: '09120000000',
  role: 'owner',
  roleLabel: 'مالک',
  isAdmin: false,
  isSuperAdmin: false,
  complex: {
    id: memberships.find((m) => m.current)?.complexId ?? 1,
    name: 'x',
    type: 'residential',
  } as CurrentUser['complex'],
  accountState: 'member',
  canWrite: true,
  pendingInvitations: 0,
  memberships,
  ...extra,
})

describe('تعویضِ مجتمع', () => {
  const replace = vi.fn()
  const posted: Array<{ path: string; body: unknown }> = []
  const announced: unknown[] = []
  let cleared = 0

  beforeEach(() => {
    vi.resetModules()
    replace.mockReset()
    posted.length = 0
    announced.length = 0
    cleared = 0
    vi.stubGlobal('location', { ...window.location, replace })
    vi.doMock('@/shared/pwa/registerServiceWorker', () => ({
      clearApiCache: () => {
        cleared++
        return Promise.resolve()
      },
    }))
    vi.doMock('@/shared/lib/authChannel', () => ({
      announceAuth: (message: unknown) => announced.push(message),
    }))
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.doUnmock('@/shared/lib/api')
  })

  it('اول سرور، بعد پاک‌کردنِ کش، خبر به تب‌ها و ناوبریِ کامل', async () => {
    vi.doMock('@/shared/lib/api', () => ({
      api: (path: string, options: { body?: unknown }) => {
        posted.push({ path, body: options.body })
        return Promise.resolve({})
      },
    }))
    const { switchContext } = await import('@/shared/lib/accountContext')

    await switchContext(42)

    expect(posted).toEqual([{ path: '/account/context', body: { complex_id: 42 } }])
    expect(cleared).toBe(1)
    expect(announced).toEqual([{ type: 'context' }])
    expect(replace).toHaveBeenCalledWith('/dashboard')
  })

  it('اگر سرور رد کند: نه پاک‌کردن، نه خبر، نه ناوبری', async () => {
    vi.doMock('@/shared/lib/api', () => ({
      api: () => Promise.reject(new Error('عضویتِ فعالی در این مجتمع ندارید.')),
    }))
    const { switchContext } = await import('@/shared/lib/accountContext')

    await expect(switchContext(99)).rejects.toThrow()

    expect([cleared, announced.length, replace.mock.calls.length]).toEqual([0, 0, 0])
  })

  it('«ایجادِ حسابِ جدید»: اول سرور (زمینه‌ی بی‌مجتمع)، بعد همان پاک‌سازی و ناوبریِ کامل', async () => {
    vi.doMock('@/shared/lib/api', () => ({
      api: (path: string, options: { body?: unknown }) => {
        posted.push({ path, body: options.body })
        return Promise.resolve({})
      },
    }))
    const { enterInitialContext } = await import('@/shared/lib/accountContext')

    await enterInitialContext()

    expect(posted).toEqual([{ path: '/account/context/initial', body: undefined }])
    expect([cleared, announced]).toEqual([1, [{ type: 'context' }]])
    expect(replace).toHaveBeenCalledWith('/dashboard')
  })

  it('سربرگِ زمینه: دو حالتِ بی‌مجتمع نامِ خودشان را دارند', async () => {
    const { contextMismatch } = await import('@/shared/lib/accountContext')
    const base = { isSuperAdmin: false, role: 'owner', complex: null }

    expect(contextMismatch('0:choose', { ...base, dashboard: 'select' })).toBe(false)
    expect(contextMismatch('0:initial', { ...base, dashboard: 'initial' })).toBe(false)
    // تبی که هنوز صفحه‌ی انتخاب است و سرور می‌گوید مجتمعی برگزیده شد ← بارِ دوباره
    expect(contextMismatch('5:owner', { ...base, dashboard: 'select' })).toBe(true)
    // داشبوردِ مجتمع و سرور می‌گوید «اولیه» (ایجادِ حسابِ جدید در تبِ دیگر) ← بارِ دوباره
    expect(contextMismatch('0:initial', { ...base, dashboard: 'unit', complex: { id: 5 } })).toBe(
      true,
    )
    expect(contextMismatch('5:owner', { ...base, dashboard: 'unit', complex: { id: 5 } })).toBe(
      false,
    )
  })

  it('تب‌های دیگر پیامِ «context» را می‌شناسند', async () => {
    vi.doUnmock('@/shared/lib/authChannel')
    const { isAuthMessage } = await import('@/shared/lib/authChannel')

    expect(isAuthMessage({ type: 'context' })).toBe(true)
    expect(isAuthMessage({ type: 'contexts' })).toBe(false)
  })
})

describe('کشِ وابسته به زمینه', () => {
  beforeEach(() => {
    vi.resetModules()
  })

  it('همان کلید در دو مجتمع دو ورودیِ جدا است', async () => {
    const { contextualHash, createQueryClient } = await import('@/shared/lib/queryClient')
    const { useAuthStore } = await import('@/shared/stores/authStore')
    const client = createQueryClient()

    useAuthStore.setState({
      user: user([membership(1, true), membership(2, false)]),
      isLoading: false,
    })
    const inA = contextualHash(['units', 'list'])
    client.setQueryData(['units', 'list'], ['واحدِ الف'])

    useAuthStore.setState({
      user: user([membership(1, false), membership(2, true)]),
      isLoading: false,
    })
    const inB = contextualHash(['units', 'list'])

    expect(inA).not.toBe(inB)
    expect(client.getQueryData(['units', 'list'])).toBeUndefined()

    // برگشت به الف: همان ورودیِ الف، نه چیزِ دیگری
    useAuthStore.setState({
      user: user([membership(1, true), membership(2, false)]),
      isLoading: false,
    })
    expect(client.getQueryData(['units', 'list'])).toEqual(['واحدِ الف'])
  })

  it('حسابِ دیگر هم کلیدِ جدا دارد', async () => {
    const { contextualHash } = await import('@/shared/lib/queryClient')
    const { useAuthStore } = await import('@/shared/stores/authStore')

    useAuthStore.setState({ user: user([membership(1, true)]), isLoading: false })
    const mine = contextualHash(['wallet'])
    useAuthStore.setState({ user: user([membership(1, true)], { id: 8 }), isLoading: false })

    expect(contextualHash(['wallet'])).not.toBe(mine)
  })
})

/*
 * ③ از ۱۳/۳ (ایرادات ۱۰، بندهای ۳۷ و ۵۰–۵۱؛ ایرادات ۱۱، بندهای ۵.۳، ۵.۴ و ۵.۷؛ «داشبورد های آزمایشی» ۵.۲) — الزام عوض
 * شد، نه ضعیف: پیش از این «مجتمع‌های من» همه را با ✓ برای فعلی نشان می‌داد و تک‌مجتمعی فهرستی نداشت. حالا مجتمعِ
 * همین زمینه هرگز در فهرست نیست، «ایجادِ حسابِ جدید» در هر داشبوردِ مجتمع هست و در داشبوردِ اولیه نه، و سوپر ادمین
 * فقط دو گزینه دارد.
 */
describe('منوی آدمک', () => {
  const switched: number[] = []
  let entered = 0
  const enteredAt: string[] = []
  let menu: {
    useAuthStore: (typeof import('@/shared/stores/authStore'))['useAuthStore']
    createQueryClient: (typeof import('@/shared/lib/queryClient'))['createQueryClient']
    UserMenu: (typeof import('@/shared/layout/UserMenu'))['UserMenu']
  }

  /*
   * ایمپورتِ سردِ درختِ ماژول‌ها یک بار و با بودجه‌ی زمانیِ خودش، نه در بدنه‌ی نخستین آزمون: زیرِ بارِ موازیِ کلِ Vitest
   * (۱۶ کارگر؛ هوکِ پیش از کامیت) همین ایمپورت آزمون را از ۵ ثانیه گذراند (۵٫۴ ثانیه سنجیده شد) — شکستی که به رفتار
   * ربطی نداشت و آزمونِ بعدی را هم با رندرِ نیمه‌کاره آلوده می‌کرد.
   */
  beforeAll(async () => {
    vi.resetModules()
    vi.doMock('@/shared/lib/accountContext', () => ({
      switchContext: (id: number) => {
        switched.push(id)
        return Promise.resolve()
      },
      enterInitialContext: (path = '/dashboard') => {
        entered++
        enteredAt.push(path)
        return Promise.resolve()
      },
      INVITATIONS_PATH: '/invitations',
      // همان قاعده‌ی واقعی: دعوت‌ها از داشبوردِ مجتمع با زمینه‌ی اولیه
      openTarget: (path: string, who: { dashboard?: string } | null, go: (p: string) => void) => {
        if (path === '/invitations' && who?.dashboard !== 'initial') {
          entered++
          enteredAt.push(path)
        } else go(path)
      },
    }))
    const [{ useAuthStore }, { createQueryClient }, { UserMenu }] = await Promise.all([
      import('@/shared/stores/authStore'),
      import('@/shared/lib/queryClient'),
      import('@/shared/layout/UserMenu'),
    ])
    menu = { useAuthStore, createQueryClient, UserMenu }
  }, 30_000)

  beforeEach(() => {
    switched.length = 0
    entered = 0
    enteredAt.length = 0
  })

  function renderMenu(current: CurrentUser) {
    const { useAuthStore, createQueryClient, UserMenu } = menu
    useAuthStore.setState({ user: current, isLoading: false })

    render(
      <QueryClientProvider client={createQueryClient()}>
        <MemoryRouter>
          <UserMenu />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    fireEvent.click(screen.getAllByRole('button')[0])
  }

  const labels = () =>
    screen
      .getAllByRole('button')
      .slice(1)
      .map((b) => b.textContent?.trim())

  it('تک‌مجتمعی: اطلاعاتِ کاربری، «ایجادِ حسابِ جدید» و خروج — بی فهرستِ تعویض', async () => {
    renderMenu(user([membership(1, true)]))

    await screen.findByText('اطلاعات کاربری')
    expect(labels()).toEqual(['اطلاعات کاربری', 'ایجاد حساب جدید', 'خروج از حساب'])
    expect(screen.queryByRole('group')).toBeNull()
  })

  it('چندمجتمعی: مجتمعِ همین زمینه در «مجتمع‌های دیگر» نیست و کلیک روی دیگری تعویض است', async () => {
    renderMenu(
      user([membership(1, true), membership(2, false, 'complex_admin'), membership(3, false)]),
    )

    const group = await screen.findByRole('group', { name: 'مجتمع‌های دیگر' })
    expect(group.textContent).not.toContain('مجتمع 1')
    expect(group.textContent).toContain('مجتمع 2')
    expect(group.textContent).toContain('مجتمع 3')
    expect(screen.queryByText('مجتمع 1')).toBeNull()

    fireEvent.click(screen.getByText('مجتمع 2'))
    await waitFor(() => expect(switched).toEqual([2]))
  })

  it('مجتمعِ فعال حتی بی پرچمِ `current` (فقط از `complex`) کنار گذاشته می‌شود', async () => {
    renderMenu(
      user([{ ...membership(1, false), complexName: 'مجتمعِ همین‌جا' }, membership(2, false)]),
    )

    const group = await screen.findByRole('group', { name: 'مجتمع‌های دیگر' })
    expect(group.textContent).not.toContain('مجتمعِ همین‌جا')
  })

  it('عضویتِ معلق در فهرستِ تعویض نیست — فقط دسترسیِ معتبر (بندِ ۵.۳)', async () => {
    renderMenu(user([membership(1, true), { ...membership(2, false), status: 'suspended' }]))

    await screen.findByText('اطلاعات کاربری')
    expect(screen.queryByText('مجتمع 2')).toBeNull()
    expect(screen.queryByRole('group')).toBeNull()
  })

  it('«ایجادِ حسابِ جدید» همان زمینه‌ی بی‌مجتمع است و بعد از استفاده هم سرِ جایش', async () => {
    renderMenu(user([membership(1, true)]))

    fireEvent.click(await screen.findByText('ایجاد حساب جدید'))
    await waitFor(() => expect(entered).toBe(1))
    expect(switched).toEqual([])
  })

  it('داشبوردِ اولیه: بی «ایجادِ حسابِ جدید»، با مجتمع‌های قبلی برای بازگشتِ بی خروج', async () => {
    renderMenu(
      user([membership(1, false), membership(2, false)], {
        accountState: 'initial',
        dashboard: 'initial',
        complex: null,
        canWrite: false,
      }),
    )

    const group = await screen.findByRole('group', { name: 'مجتمع‌های من' })
    expect(group.textContent).toContain('مجتمع 1')
    expect(group.textContent).toContain('مجتمع 2')
    expect(screen.queryByText('ایجاد حساب جدید')).toBeNull()

    fireEvent.click(screen.getByText('مجتمع 1'))
    await waitFor(() => expect(switched).toEqual([1]))
  })

  it('حسابِ اولیه‌ی بی‌عضویت: فقط اطلاعاتِ کاربری و خروج', async () => {
    renderMenu(
      user([], { accountState: 'initial', dashboard: 'initial', complex: null, canWrite: false }),
    )

    await screen.findByText('اطلاعات کاربری')
    expect(labels()).toEqual(['اطلاعات کاربری', 'خروج از حساب'])
  })

  it('سوپر ادمین فقط «اطلاعات کاربری» و «خروج از حساب»', async () => {
    renderMenu(
      user([], {
        role: 'super_admin',
        isSuperAdmin: true,
        isAdmin: true,
        accountState: 'platform',
        dashboard: 'platform',
        complex: null,
      }),
    )

    await screen.findByText('اطلاعات کاربری')
    expect(labels()).toEqual(['اطلاعات کاربری', 'خروج از حساب'])
    expect(screen.queryByText('تنظیمات حساب')).toBeNull()
  })

  it('«دعوت‌ها» از داشبوردِ مجتمع به صفحه‌ی دعوت‌های داشبوردِ اولیه می‌برد (۱۳/۵)', async () => {
    renderMenu(user([membership(1, true)], { pendingInvitations: 2 }))

    fireEvent.click(await screen.findByText('دعوت‌ها (2)'))
    expect(enteredAt).toEqual(['/invitations'])
  })
})
