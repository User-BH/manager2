import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithQuery } from './helpers/renderWithQuery'

/**
 * صفحه‌ی اعضا پس از مهاجرت به TanStack Query.
 *
 * این صفحه عمداً اولین مهاجرت بود چون هر دو سمت را دارد: خواندنِ کش‌شده و
 * mutationهایی که باید کش را باطل کنند. تستِ اصلی همان قیدی است که کارفرما
 * گزارش کرده بود — پس از تغییرِ نقش، فهرست باید تازه شود.
 */

const apiMock = vi.fn()
const confirmMock = vi.fn()

vi.mock('@/shared/lib/api', () => ({
  api: (...args: unknown[]) => apiMock(...args),
  ApiError: class extends Error {},
  setCsrfToken: vi.fn(),
}))

vi.mock('@/shared/lib/alert', () => ({
  confirmAction: (...args: unknown[]) => confirmMock(...args),
  toastSuccess: vi.fn(),
  alertError: vi.fn(),
  toastTopSuccess: vi.fn(),
  toastTopError: vi.fn(),
  toastError: vi.fn(),
}))

const { MembersPage } = await import('@/features/system/MembersPage')

const membersResponse = {
  data: [
    {
      id: 7,
      name: 'علی محمدی',
      phone: '09123456789',
      role: 'resident',
      roleLabel: 'ساکن',
      isActive: true,
      complex: null,
      registeredAt: '۱۴۰۵/۰۱/۰۱',
    },
  ],
  meta: { total: 1, page: 1, lastPage: 1 },
  roles: [
    { value: 'resident', label: 'ساکن' },
    { value: 'manager', label: 'مدیر' },
  ],
}

beforeEach(() => {
  apiMock.mockReset()
  confirmMock.mockReset()
  apiMock.mockResolvedValue(membersResponse)
})

describe('MembersPage با TanStack Query', () => {
  it('فهرست را می‌گیرد و نمایش می‌دهد', async () => {
    renderWithQuery(<MembersPage />)

    expect(await screen.findByText('علی محمدی')).toBeInTheDocument()
    expect(apiMock).toHaveBeenCalledTimes(1)
  })

  it('درخواست با AbortSignal فرستاده می‌شود تا با ترکِ صفحه لغو شود', async () => {
    renderWithQuery(<MembersPage />)
    await screen.findByText('علی محمدی')

    // TanStack سیگنال می‌دهد، ولی فقط اگر خودمان به api پاسش داده باشیم
    const options = apiMock.mock.calls[0][1] as { signal?: AbortSignal }
    expect(options.signal).toBeInstanceOf(AbortSignal)
  })

  it('حذفِ کاربر پس از تایید، فهرست را دوباره می‌گیرد (ابطالِ کش)', async () => {
    const user = userEvent.setup()
    confirmMock.mockResolvedValue(true)
    renderWithQuery(<MembersPage />)
    await screen.findByText('علی محمدی')

    apiMock.mockClear()
    apiMock.mockResolvedValueOnce(undefined).mockResolvedValue(membersResponse)

    await user.click(screen.getByRole('button', { name: 'حذف کاربر' }))

    await waitFor(() => {
      expect(apiMock.mock.calls[0][0]).toBe('/system/members/7')
      expect((apiMock.mock.calls[0][1] as { method?: string }).method).toBe('DELETE')
    })

    /*
     * قیدِ اصلی: پس از حذف باید درخواستِ تازه‌ای برای فهرست برود. اگر ابطالِ کش
     * فراموش شود، کاربرِ حذف‌شده روی صفحه می‌ماند تا وقتی صفحه رفرش شود.
     */
    await waitFor(() => {
      const listCalls = apiMock.mock.calls.filter((c) => String(c[0]).includes('?q='))
      expect(listCalls.length).toBeGreaterThan(0)
    })
  })

  it('اگر کاربر تایید را لغو کند، هیچ درخواستی نمی‌رود', async () => {
    const user = userEvent.setup()
    confirmMock.mockResolvedValue(false)
    renderWithQuery(<MembersPage />)
    await screen.findByText('علی محمدی')

    apiMock.mockClear()
    await user.click(screen.getByRole('button', { name: 'حذف کاربر' }))

    await waitFor(() => expect(confirmMock).toHaveBeenCalled())
    expect(apiMock).not.toHaveBeenCalled()
  })
})
