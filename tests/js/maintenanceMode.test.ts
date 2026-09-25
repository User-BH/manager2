import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * حالتِ تعمیر در فرانت (مرحلهٔ ۵ · فاز ۹ · بندِ ۷۹).
 *
 * سرور در حالتِ تعمیر ۵۰۳ با `maintenance: true` می‌دهد. داشبورد با
 * بارگذاریِ دوباره واکنش نشان می‌دهد تا سرور صفحه‌ی ۵۰۳ِ فارسی را بدهد.
 *
 * 🔴 مرزِ حیاتی: صفحه‌های عمومی و ورود در حالتِ تعمیر **باز** می‌مانند؛
 * اگر آن‌ها هم بار می‌شدند، همان صفحه دوباره می‌آمد و همان درخواست دوباره
 * ۵۰۳ می‌گرفت — حلقه‌ی بی‌پایان. پس فقط داشبورد ثبتش می‌کند.
 */

const request = vi.fn()

vi.mock('@/shared/lib/http', () => ({
  http: { request: (...args: unknown[]) => request(...args) },
  refreshCsrfToken: () => Promise.resolve(),
  setCsrfToken: vi.fn(),
  TIMEOUT_MS: 20_000,
  UPLOAD_TIMEOUT_MS: 120_000,
}))

const { api, onMaintenance } = await import('@/shared/lib/api')

function httpError(status: number, data: unknown) {
  return { response: { status, data, headers: {} }, isAxiosError: true }
}

const MESSAGE = 'سامانه در حالِ به‌روزرسانی است.'

// ⚠️ آکولاد لازم است: تابعی که beforeEach برگرداند، vitest پس از تست به‌عنوانِ پاک‌سازی صدا می‌زند
beforeEach(() => {
  request.mockReset()
})

describe('پاسخِ حالتِ تعمیر', () => {
  // POST، چون تلاشِ دوباره ندارد و تست منتظرِ backoff نمی‌ماند
  it('واکنشِ ثبت‌شده صدا زده می‌شود و پیامِ خودِ سرور به کاربر می‌رسد', async () => {
    const handler = vi.fn()
    onMaintenance(handler)
    request.mockRejectedValue(
      httpError(503, { message: MESSAGE, code: 'maintenance', maintenance: true }),
    )

    await expect(api('/things', { method: 'POST' })).rejects.toThrow(MESSAGE)
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('یک ۵۰۳ِ معمولی (سرورِ خسته) صفحه را بار نمی‌کند', async () => {
    const handler = vi.fn()
    onMaintenance(handler)
    request.mockRejectedValue(httpError(503, { message: 'خطای سرور' }))

    await expect(api('/things', { method: 'POST' })).rejects.toThrow()
    expect(handler).not.toHaveBeenCalled()
  })
})

describe('چه کسی واکنش را ثبت می‌کند', () => {
  const JS = join(__dirname, '../../resources/js')

  function walk(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
      const path = join(dir, name)
      return statSync(path).isDirectory() ? walk(path) : [path]
    })
  }

  it('فقط داشبورد — هیچ نقطه‌ی ورودِ عمومی بارگذاریِ دوباره نمی‌کند', () => {
    const callers = walk(JS)
      .filter((f) => /\.(ts|tsx)$/.test(f))
      .filter((f) =>
        /\bonMaintenance\(/.test(readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')),
      )
      .map((f) => relative(JS, f).replace(/\\/g, '/'))
      .filter((f) => f !== 'shared/lib/api.ts')

    expect(callers).toEqual(['app/main.tsx'])
  })
})
