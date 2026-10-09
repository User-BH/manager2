import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ResidentForm } from '@/features/residents/ResidentForm'
import { JoinRequestInbox } from '@/features/residents/JoinRequestInbox'
import { AcceptInvitationForm } from '@/features/registrant/AcceptInvitationForm'
import { InvitePage } from '@/features/auth/InvitePage'
import type { Resident } from '@/features/residents/types'

/**
 * دعوتِ امن و مسیرِ عضویت در رابط (مرحلهٔ ۹ · فاز ۶).
 *
 * سرور قاعده‌ی قطعی را دارد (`SecureInvitationTest`)؛ اینجا سنجیده می‌شود که
 * رابط همان چیزی را می‌فرستد که باید — و چیزی را که نباید (نام، رمز، شماره در
 * ویرایش، توکن در نشانی) هرگز نمی‌فرستد.
 */

const calls = vi.hoisted(() => ({
  list: [] as { path: string; options?: { method?: string; body?: unknown } }[],
}))
const reply = vi.hoisted((): { value: unknown; error: Error | null } => ({
  value: {},
  error: null,
}))
const toastError = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', () => {
  class ApiError extends Error {
    errors: Record<string, string[]>
    constructor(message: string, errors: Record<string, string[]> = {}) {
      super(message)
      this.errors = errors
    }
  }
  return {
    ApiError,
    api: vi.fn((path: string, options?: { method?: string; body?: unknown }) => {
      calls.list.push({ path, options })
      if (reply.error instanceof Error) return Promise.reject(reply.error)
      return Promise.resolve(reply.value)
    }),
  }
})
vi.mock('@/shared/lib/alert', () => ({
  toastError,
  toastSuccess: vi.fn(),
  alertError: vi.fn(),
  confirmAction: vi.fn(() => Promise.resolve(true)),
}))

const filters = {
  units: [
    { id: 5, unit_number: '5' },
    { id: 6, unit_number: '6' },
  ],
  roleOptions: [
    { value: 'owner', label: 'مالک' },
    { value: 'tenant', label: 'مستاجر' },
  ],
}

function wrap(node: React.ReactNode, path = '/') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>{node}</MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  calls.list = []
  reply.value = {}
  reply.error = null
  toastError.mockReset()
})
afterEach(() => vi.restoreAllMocks())

describe('«ساکن جدید» = دعوت', () => {
  it('فقط شماره، نقش و واحد می‌رود — نه نام، نه رمز', async () => {
    const onInvited = vi.fn()
    reply.value = {
      link: 'https://x.test/invite#tok',
      invitation: { phone: '09127770001', expiresAt: '۱۴۰۵/۰۷/۲۴' },
    }
    wrap(
      <ResidentForm
        resident={null}
        filters={filters}
        onSaved={vi.fn()}
        onInvited={onInvited}
        onCancel={vi.fn()}
      />,
    )

    expect(screen.queryByLabelText(/رمز/)).toBeNull()
    expect(screen.queryByLabelText('نام و نام خانوادگی')).toBeNull()

    await userEvent.type(screen.getByLabelText('شماره موبایل'), '09127770001')
    await userEvent.selectOptions(screen.getByLabelText('واحد'), '5')
    await userEvent.click(screen.getByRole('button', { name: /ساختِ دعوت/ }))

    await waitFor(() => expect(onInvited).toHaveBeenCalled())
    expect(calls.list[0]).toMatchObject({ path: '/residents', options: { method: 'POST' } })
    expect(calls.list[0].options?.body).toEqual({
      phone: '09127770001',
      role: 'tenant',
      unit_id: 5,
    })
    expect(onInvited).toHaveBeenCalledWith({
      link: 'https://x.test/invite#tok',
      phone: '09127770001',
      expiresAt: '۱۴۰۵/۰۷/۲۴',
    })
  })

  it('بی واحد چیزی فرستاده نمی‌شود (F6)', async () => {
    wrap(
      <ResidentForm
        resident={null}
        filters={filters}
        onSaved={vi.fn()}
        onInvited={vi.fn()}
        onCancel={vi.fn()}
      />,
    )

    await userEvent.type(screen.getByLabelText('شماره موبایل'), '09127770001')
    await userEvent.click(screen.getByRole('button', { name: /ساختِ دعوت/ }))

    expect(await screen.findByText('واحد را انتخاب کنید')).toBeTruthy()
    expect(calls.list).toEqual([])
  })
})

describe('ویرایشِ ساکن', () => {
  const resident: Resident = {
    id: 9,
    name: 'سارا امینی',
    phone: '09125551111',
    email: null,
    nationalId: null,
    role: 'owner',
    roleLabel: 'مالک',
    isActive: true,
    canMessage: true,
    units: [{ id: 6, label: 'واحد 6' }],
  }

  it('شماره فقط‌خواندنی است و نه شماره و نه رمز فرستاده می‌شود', async () => {
    const onSaved = vi.fn()
    wrap(
      <ResidentForm
        resident={resident}
        filters={filters}
        onSaved={onSaved}
        onInvited={vi.fn()}
        onCancel={vi.fn()}
      />,
    )

    expect(screen.queryByLabelText(/رمز/)).toBeNull()
    expect(screen.getByLabelText('شماره موبایل')).toBeDisabled()

    await userEvent.selectOptions(screen.getByLabelText('نقش'), 'tenant')
    await userEvent.click(screen.getByRole('button', { name: /ذخیره تغییرات/ }))

    await waitFor(() => expect(onSaved).toHaveBeenCalled())
    const body = calls.list[0].options?.body as Record<string, unknown>
    expect(calls.list[0]).toMatchObject({ path: '/residents/9', options: { method: 'PUT' } })
    expect(body).not.toHaveProperty('phone')
    expect(body).not.toHaveProperty('password')
    expect(body).toMatchObject({ role: 'tenant', unit_id: 6 })
  })
})

describe('تأییدِ درخواستِ پیوستن (F6)', () => {
  it('بی واحد درخواستی نمی‌رود؛ با واحد، `unit_id` می‌رود', async () => {
    reply.value = { data: [{ id: 3, name: 'زهرا', phone: '09125556666', createdAt: null }] }
    wrap(<JoinRequestInbox units={filters.units} />)

    const approve = await screen.findByRole('button', { name: /تایید به‌عنوان مالک/ })
    calls.list = []
    await userEvent.click(approve)

    expect(toastError).toHaveBeenCalled()
    expect(calls.list).toEqual([])

    await userEvent.selectOptions(screen.getByLabelText(/واحدِ زهرا/), '6')
    await userEvent.click(approve)

    await waitFor(() =>
      expect(calls.list.some((c) => c.path === '/join-requests/3/approve')).toBe(true),
    )
    const sent = calls.list.find((c) => c.path === '/join-requests/3/approve')
    expect(sent?.options?.body).toEqual({ role: 'owner', unit_id: 6 })
  })
})

describe('پذیرشِ دعوت با کدِ تازه', () => {
  it('کدِ نادرست زیرِ همان فیلد می‌نشیند و رقمِ فارسی لاتین می‌رود', async () => {
    const { ApiError } = await import('@/shared/lib/api')
    reply.error = new (
      ApiError as unknown as new (m: string, e: Record<string, string[]>) => Error
    )('x', {
      code: ['کدِ تأیید نادرست یا منقضی است.'],
    })
    wrap(
      <AcceptInvitationForm
        invitationId={4}
        message="کد فرستاده شد."
        devCode={null}
        onCancel={vi.fn()}
      />,
    )

    await userEvent.type(screen.getByLabelText('کدِ تأیید'), '۱۲۳۴۵۶')
    await userEvent.click(screen.getByRole('button', { name: /تأیید و پیوستن/ }))

    expect(await screen.findByText('کدِ تأیید نادرست یا منقضی است.')).toBeTruthy()
    expect(calls.list[0]).toMatchObject({
      path: '/invitations/4/accept',
      options: { body: { code: '123456' } },
    })
  })

  it('کدِ ناقص اصلاً فرستاده نمی‌شود', async () => {
    wrap(
      <AcceptInvitationForm
        invitationId={4}
        message="کد فرستاده شد."
        devCode={null}
        onCancel={vi.fn()}
      />,
    )

    await userEvent.type(screen.getByLabelText('کدِ تأیید'), '123')
    await userEvent.click(screen.getByRole('button', { name: /تأیید و پیوستن/ }))

    expect(await screen.findByText('کدِ شش‌رقمیِ پیامک را وارد کنید.')).toBeTruthy()
    expect(calls.list).toEqual([])
  })
})

describe('پیوندِ دعوت', () => {
  it('توکن از # خوانده و در بدنه‌ی POST فرستاده می‌شود، و از نشانی پاک می‌شود', async () => {
    window.history.replaceState(null, '', '/invite#secret-token-123')
    reply.value = {
      invitation: {
        complexName: 'مجتمع امن',
        roleLabel: 'مالک',
        unitLabel: 'واحد 7',
        phoneHint: '09•••••••01',
        expiresAt: null,
      },
    }

    wrap(<InvitePage />, '/invite')

    expect(await screen.findByText('مجتمع امن')).toBeTruthy()
    expect(calls.list[0].path).toBe('/invitations/preview')
    expect(calls.list[0].path).not.toContain('secret-token-123')
    expect(calls.list[0].options).toMatchObject({
      method: 'POST',
      body: { token: 'secret-token-123' },
    })
    expect(window.location.hash).toBe('')
  })

  it('پیوندِ نامعتبر پیامِ روشن می‌دهد', async () => {
    window.history.replaceState(null, '', '/invite#nope')
    reply.error = new Error('404')

    wrap(<InvitePage />, '/invite')

    expect(await screen.findByText('این پیوند معتبر نیست')).toBeTruthy()
  })
})
