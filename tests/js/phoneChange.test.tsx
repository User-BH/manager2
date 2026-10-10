import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PhoneCard } from '@/features/profile/PhoneCard'

/**
 * تغییرِ شماره توسطِ خودِ کاربر (مرحلهٔ ۹): دو گام، فقط شماره و فقط کد — هیچ
 * شناسه‌ی حسابی از کلاینت نمی‌رود؛ شماره پیش از تأییدِ کد «عوض‌شده» نمایش داده نمی‌شود.
 */

const calls = vi.hoisted(() => ({ list: [] as { path: string; body: unknown }[] }))

vi.mock('@/shared/lib/api', async () => {
  const { ApiError } = await import('@/shared/lib/apiError')
  return {
    ApiError,
    api: vi.fn((path: string, options?: { body?: unknown }) => {
      calls.list.push({ path, body: options?.body })
      if (path === '/profile/phone') {
        return Promise.resolve({ message: 'کد فرستاده شد.', devCode: '123456' })
      }
      return Promise.resolve({ message: 'شماره‌ی حساب تغییر کرد.' })
    }),
  }
})
vi.mock('@/shared/lib/alert', () => ({
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  alertError: vi.fn(),
}))

beforeEach(() => {
  calls.list = []
})

describe('تغییرِ شماره‌ی حساب', () => {
  it('شماره‌ی تازه و سپس کد می‌رود؛ رقمِ فارسی لاتین می‌شود و هیچ شناسه‌ای نه', async () => {
    const onChanged = vi.fn()
    render(<PhoneCard currentPhone="09125550001" onChanged={onChanged} />)

    await userEvent.type(screen.getByLabelText('شماره‌ی تازه'), '۰۹۱۲۵۵۵۰۰۹۹')
    await userEvent.click(screen.getByRole('button', { name: /دریافتِ کد/ }))

    const codeInput = await screen.findByLabelText(/کدِ فرستاده‌شده به 09125550099/)
    expect(onChanged).not.toHaveBeenCalled()
    await userEvent.type(codeInput, '۱۲۳۴۵۶')
    await userEvent.click(screen.getByRole('button', { name: /تأییدِ شماره‌ی تازه/ }))

    await waitFor(() => expect(calls.list).toHaveLength(2))
    await waitFor(() => expect(onChanged).toHaveBeenCalledOnce())
    expect(calls.list).toEqual([
      { path: '/profile/phone', body: { phone: '09125550099' } },
      { path: '/profile/phone/verify', body: { code: '123456' } },
    ])
  })

  it('شماره‌ی نامعتبر و کدِ ناقص اصلاً فرستاده نمی‌شوند', async () => {
    render(<PhoneCard currentPhone="09125550001" onChanged={vi.fn()} />)

    await userEvent.type(screen.getByLabelText('شماره‌ی تازه'), '12345')
    await userEvent.click(screen.getByRole('button', { name: /دریافتِ کد/ }))
    expect(await screen.findByText('شماره باید به شکل ۰۹xxxxxxxxx باشد.')).toBeTruthy()
    expect(calls.list).toHaveLength(0)
  })
})
