import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

describe('«مجتمع‌های من» در منوی کاربر', () => {
  const switched: number[] = []

  beforeEach(() => {
    vi.resetModules()
    switched.length = 0
    vi.doMock('@/shared/lib/accountContext', () => ({
      switchContext: (id: number) => {
        switched.push(id)
        return Promise.resolve()
      },
    }))
  })

  async function renderMenu(current: CurrentUser) {
    const { useAuthStore } = await import('@/shared/stores/authStore')
    const { createQueryClient } = await import('@/shared/lib/queryClient')
    const { UserMenu } = await import('@/shared/layout/UserMenu')
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

  it('حسابِ تک‌مجتمعی همان منوی قبلی را می‌بیند', async () => {
    await renderMenu(user([membership(1, true)]))

    expect(await screen.findByText('پروفایل من')).toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'مجتمع‌های من' })).toBeNull()
  })

  it('حسابِ چندمجتمعی فهرست و مجتمعِ فعال را می‌بیند و با کلیک می‌رود', async () => {
    await renderMenu(user([membership(1, true), membership(2, false, 'complex_admin')]))

    const group = await screen.findByRole('group', { name: 'مجتمع‌های من' })
    const current = group.querySelector('[aria-current="true"]')
    expect(current?.textContent).toContain('مجتمع 1')

    fireEvent.click(screen.getByText('مجتمع 2'))
    await waitFor(() => expect(switched).toEqual([2]))

    // کلیک روی مجتمعِ فعال درخواستی نمی‌فرستد
    fireEvent.click(screen.getAllByRole('button')[0])
    fireEvent.click(await screen.findByText('مجتمع 1'))
    expect(switched).toEqual([2])
  })

  it('عضوِ دعوت‌شده به پروفایل هدایت می‌شود', async () => {
    await renderMenu(user([membership(1, true)], { pendingInvitations: 2 }))

    expect(await screen.findByText('دعوت‌ها (2)')).toBeInTheDocument()
  })
})
