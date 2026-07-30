import { describe, expect, it } from 'vitest'

import { ApiError } from '@/shared/lib/apiError'
import { createQueryClient, errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'

/**
 * پیکربندیِ کش.
 *
 * این‌ها «تستِ کتابخانه» نیستند؛ تصمیم‌هایی را قفل می‌کنند که اگر کسی بعداً
 * عوضشان کند، خرابی‌اش خاموش و گران است.
 */

describe('createQueryClient — پیش‌فرض‌ها', () => {
  const defaults = createQueryClient().getDefaultOptions()

  /*
   * حیاتی‌ترین تستِ این فایل. لایه‌ی حمل‌ونقل (R5) خودش تا سه بار تلاش می‌کند.
   * اگر اینجا هم retry روشن شود، ۳ × ۳ = ۹ درخواست برای یک خطای ساده می‌رود.
   */
  it('retry خاموش است تا روی retryِ لایه‌ی حمل‌ونقل سوار نشود', () => {
    expect(defaults.queries?.retry).toBe(false)
  })

  it('mutation هرگز تکرار نمی‌شود (تکرارِ POST پرداختِ دوم می‌سازد)', () => {
    expect(defaults.mutations?.retry).toBe(false)
  })

  it('با هر بار فوکوسِ پنجره دوباره نمی‌گیرد', () => {
    // مدیرِ ساختمان چند تب باز دارد؛ پیش‌فرضِ روشن یعنی رگبارِ درخواست
    expect(defaults.queries?.refetchOnWindowFocus).toBe(false)
  })

  it('با برگشتِ اینترنت دوباره می‌گیرد', () => {
    expect(defaults.queries?.refetchOnReconnect).toBe(true)
  })

  it('staleTime و gcTime مقدارِ صریح دارند، نه پیش‌فرضِ کتابخانه', () => {
    expect(defaults.queries?.staleTime).toBe(30_000)
    expect(defaults.queries?.gcTime).toBe(300_000)
  })
})

describe('errorMessage', () => {
  it('پیامِ ApiError را همان‌طور که هست نشان می‌دهد', () => {
    expect(errorMessage(new ApiError('شماره تکراری است', 422))).toBe('شماره تکراری است')
  })

  it('برای خطای ناشناس پیامِ عمومیِ فارسی می‌دهد', () => {
    expect(errorMessage(new Error('Network Error'))).toBe('ارتباط با سرور برقرار نشد.')
    expect(errorMessage(undefined)).toBe('ارتباط با سرور برقرار نشد.')
  })
})

describe('queryKeys — سلسله‌مراتب', () => {
  /*
   * ابطالِ گروهی به تطبیقِ پیشوندی تکیه دارد. اگر کلیدِ جزئی با کلیدِ کلی شروع
   * نشود، `invalidateQueries` بی‌صدا هیچ کاری نمی‌کند و UI تازه نمی‌شود —
   * باگی که خطا نمی‌دهد و پیدا کردنش سخت است.
   */
  it('کلیدِ جزئی با کلیدِ کلی شروع می‌شود تا ابطالِ گروهی کار کند', () => {
    expect(queryKeys.members.list({ q: 'علی' })[0]).toBe(queryKeys.members.all()[0])
    expect(queryKeys.units.list()[0]).toBe(queryKeys.units.all()[0])
    expect(queryKeys.bills.mine()[0]).toBe(queryKeys.bills.all()[0])
  })

  it('پارامترِ متفاوت کلیدِ متفاوت می‌سازد (کشِ جداگانه برای هر جست‌وجو)', () => {
    expect(queryKeys.members.list({ q: 'علی' })).not.toEqual(queryKeys.members.list({ q: 'رضا' }))
  })

  it('پارامترِ یکسان کلیدِ یکسان می‌سازد', () => {
    expect(queryKeys.members.list({ q: 'علی' })).toEqual(queryKeys.members.list({ q: 'علی' }))
  })
})
