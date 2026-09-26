import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * چرخه‌ی نشست در فرانت (مرحلهٔ ۵ · فاز ۱۰).
 *
 * - `auth.reauth_required` پنجره‌ی رمز را باز می‌کند و **همان** درخواست پس از
 *   تأیید یک بار دوباره می‌رود — نه بیشتر.
 * - ۴۰۱ یعنی سرور نشست را بسته؛ داشبورد کاربر را به ورود می‌برد.
 * - پیامِ کانالِ بینِ تب‌ها ورودیِ بیرونی است و شکلش سنجیده می‌شود.
 */

const request = vi.fn()

vi.mock('@/shared/lib/http', () => ({
  http: { request: (...args: unknown[]) => request(...args) },
  refreshCsrfToken: () => Promise.resolve(),
  setCsrfToken: vi.fn(),
  TIMEOUT_MS: 20_000,
  UPLOAD_TIMEOUT_MS: 120_000,
}))

const { api, onReauthRequired, onSessionEnded } = await import('@/shared/lib/api')
const { requestReauth, useReauthStore } = await import('@/shared/stores/reauthStore')
const { isAuthMessage } = await import('@/shared/lib/authChannel')
const { ReauthDialog } = await import('@/shared/ui/ReauthDialog')

function httpError(status: number, data: unknown) {
  return { response: { status, data, headers: {} }, isAxiosError: true }
}

const REAUTH = httpError(403, { message: 'رمز', code: 'auth.reauth_required' })

beforeEach(() => {
  request.mockReset()
})

describe('تأییدِ دوباره‌ی رمز', () => {
  it('پس از تأیید، همان درخواست یک بار دوباره می‌رود', async () => {
    const handler = vi.fn(() => Promise.resolve(true))
    onReauthRequired(handler)
    request.mockRejectedValueOnce(REAUTH).mockResolvedValueOnce({ status: 200, data: { ok: 1 } })

    await expect(api('/system/features/polls', { method: 'PUT', body: {} })).resolves.toEqual({
      ok: 1,
    })
    expect(handler).toHaveBeenCalledTimes(1)
    expect(request).toHaveBeenCalledTimes(2)
  })

  it('انصراف یعنی خطا به کاربر می‌رسد و چیزی دوباره فرستاده نمی‌شود', async () => {
    onReauthRequired(() => Promise.resolve(false))
    request.mockRejectedValueOnce(REAUTH)

    await expect(api('/managers', { method: 'POST' })).rejects.toMatchObject({
      code: 'auth.reauth_required',
    })
    expect(request).toHaveBeenCalledTimes(1)
  })

  /** ⚠️ اگر سرور پس از تأیید هم رد کرد، حلقه نمی‌سازد. */
  it('فقط یک بار دوباره تلاش می‌کند', async () => {
    const handler = vi.fn(() => Promise.resolve(true))
    onReauthRequired(handler)
    request.mockRejectedValue(REAUTH)

    await expect(api('/managers', { method: 'POST' })).rejects.toMatchObject({
      code: 'auth.reauth_required',
    })
    expect(handler).toHaveBeenCalledTimes(1)
    expect(request).toHaveBeenCalledTimes(2)
  })

  it('دو درخواستِ هم‌زمان یک پنجره می‌بینند', async () => {
    const first = requestReauth()
    const second = requestReauth()

    expect(first).toBe(second)
    expect(useReauthStore.getState().open).toBe(true)

    useReauthStore.getState().settle(true)

    await expect(first).resolves.toBe(true)
    expect(useReauthStore.getState().open).toBe(false)
  })

  it('پنجره رمز را به سرور می‌فرستد و درخواستِ منتظر را آزاد می‌کند', async () => {
    request.mockResolvedValueOnce({ status: 200, data: { message: 'تأیید شد.' } })
    render(<ReauthDialog />)

    const waiting = requestReauth()

    fireEvent.change(await screen.findByLabelText('رمز عبور'), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: 'تأیید' }))

    await expect(waiting).resolves.toBe(true)
    expect(request.mock.calls[0][0]).toMatchObject({
      url: '/profile/confirm-password',
      method: 'POST',
      data: { password: 'secret123' },
    })
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  })
})

describe('نشستی که سرور بسته است', () => {
  it('۴۰۱ واکنشِ «نشست تمام شد» را صدا می‌زند', async () => {
    const ended = vi.fn()
    onSessionEnded(ended)
    request.mockRejectedValueOnce(httpError(401, { message: 'x', code: 'unauthenticated' }))

    await expect(api('/units')).rejects.toBeDefined()
    expect(ended).toHaveBeenCalledWith('session_ended')
  })

  it('سقفِ عمرِ نشست دلیلِ خودش را دارد', async () => {
    const ended = vi.fn()
    onSessionEnded(ended)
    request.mockRejectedValueOnce(httpError(401, { message: 'x', code: 'auth.session_expired' }))

    await expect(api('/units')).rejects.toBeDefined()
    expect(ended).toHaveBeenCalledWith('session_expired')
  })
})

describe('پیامِ کانالِ بینِ تب‌ها', () => {
  it('فقط دو شکلِ شناخته پذیرفته می‌شوند', () => {
    expect(isAuthMessage({ type: 'logout' })).toBe(true)
    expect(isAuthMessage({ type: 'login', userId: 7 })).toBe(true)

    expect(isAuthMessage({ type: 'login', userId: '7' })).toBe(false)
    expect(isAuthMessage({ type: 'login' })).toBe(false)
    expect(isAuthMessage({ type: 'grant-admin' })).toBe(false)
    expect(isAuthMessage('logout')).toBe(false)
    expect(isAuthMessage(null)).toBe(false)
  })
})

describe('چه کسی واکنش‌ها را ثبت می‌کند', () => {
  const JS = join(__dirname, '../../resources/js')

  function walk(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
      const path = join(dir, name)
      return statSync(path).isDirectory() ? walk(path) : [path]
    })
  }

  /**
   * فقط داشبورد: صفحه‌ی ورود نشستی ندارد که تمام شود، و اگر آن‌جا ۴۰۱ به
   * «برو به ورود» وصل می‌شد، خودِ صفحه‌ی ورود را دوباره بار می‌کرد.
   */
  it.each(['onSessionEnded', 'onReauthRequired', 'listenForAuthChanges'])('%s', (name) => {
    const callers = walk(JS)
      .filter((f) => /\.(ts|tsx)$/.test(f))
      .filter((f) =>
        new RegExp(`\\b${name}\\(`).test(readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')),
      )
      .map((f) => relative(JS, f).replace(/\\/g, '/'))
      .filter((f) => !['shared/lib/api.ts', 'shared/lib/authChannel.ts'].includes(f))

    expect(callers).toEqual(['app/main.tsx'])
  })
})
