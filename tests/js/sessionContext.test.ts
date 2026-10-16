import { describe, expect, it, vi } from 'vitest'
import type { AxiosAdapter, AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import axios from 'axios'
import { watchServerContext } from '@/shared/lib/serverContext'
import { contextMismatch } from '@/shared/lib/accountContext'

/**
 * زمینه‌ی نشست در رابط — مرحلهٔ ۱۲ · فاز ۱.
 *
 * سرور روی هر پاسخِ عضو «مجتمع:نقش» ِ همین نشست را می‌گوید (`X-Sakena-Context`). اگر با آن‌چه رابط نشان می‌دهد
 * نخواند — عضویت پایان یافت یا معلق شد، درخواستِ حسابِ اولیه تأیید شد — رابط کامل به زمینه‌ی تازه می‌رود و داده‌ی
 * مجتمعِ تازه زیرِ کلیدهای قبلی نمی‌نشیند. قاعده‌ی سمتِ سرور: `SessionContextTest`.
 */

const member = { isSuperAdmin: false, role: 'owner', complex: { id: 7 } }

function respondWith(status: number, context?: string): AxiosAdapter {
  return (config: InternalAxiosRequestConfig) => {
    const response: AxiosResponse = {
      data: {},
      status,
      statusText: '',
      headers: context ? { 'x-sakena-context': context } : {},
      config,
    }
    return status < 400
      ? Promise.resolve(response)
      : Promise.reject(Object.assign(new Error('x'), { isAxiosError: true, response, config }))
  }
}

describe('زمینه‌ی نشست', () => {
  it('ناهم‌خوانی فقط وقتی مجتمع یا نقش با رابط فرق دارد', () => {
    expect(contextMismatch('7:owner', member)).toBe(false)
    expect(contextMismatch('8:owner', member)).toBe(true)
    expect(contextMismatch('7:complex_admin', member)).toBe(true)
    // حسابِ اولیه‌ای که درخواستش تأیید شد
    expect(contextMismatch('7:tenant', { isSuperAdmin: false, role: 'owner', complex: null })).toBe(
      true,
    )
    // ادمینِ کل و مهمان زمینه‌ی عضویتی ندارند
    expect(
      contextMismatch('7:owner', { isSuperAdmin: true, role: 'super_admin', complex: null }),
    ).toBe(false)
    expect(contextMismatch('7:owner', null)).toBe(false)
  })

  it('سربرگ از پاسخِ موفق و پاسخِ خطا هر دو خوانده می‌شود', async () => {
    const seen = vi.fn()
    const http = axios.create()
    watchServerContext(http, seen)

    await http.get('/x', { adapter: respondWith(200, '7:owner') })
    await http.get('/x', { adapter: respondWith(403, '9:tenant') }).catch(() => undefined)
    await http.get('/x', { adapter: respondWith(200) })

    // پاسخِ بی سربرگ (مهمان، ادمینِ کل) چیزی نمی‌گوید
    expect(seen.mock.calls).toEqual([['7:owner'], ['9:tenant']])
  })
})
