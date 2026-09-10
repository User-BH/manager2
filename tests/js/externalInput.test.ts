import { beforeEach, describe, expect, it } from 'vitest'
import { isHistory, isMemory } from '@/features/tools/calculator/types'
import { isRecent } from '@/shared/stores/searchStore'

/**
 * ورودیِ خارجی پیش از ورود به رندر سنجیده می‌شود (مرحلهٔ ۵، فاز ۳ — بند ۱۶).
 *
 * ─── 🔴 چه چیزی باز بود ────────────────────────────────────────────────────
 * سه جا `JSON.parse(...) as T` می‌کردند — یعنی **ادعا** به‌جای **بررسی**:
 *
 *   • `searchStore.readRecent`      → تاریخچهٔ جست‌وجو
 *   • `useLocalStorage`             → تاریخچه و حافظهٔ ماشین‌حساب
 *
 * ⚠️ `localStorage` دادهٔ خارجی است: کاربر دستی عوضش می‌کند، نسخهٔ قدیمیِ
 * برنامه شکلِ دیگری نوشته، یا افزونهٔ مرورگر همان کلید را گرفته.
 *
 * 🔴 و نکتهٔ گویا این بود که `rememberMe.ts` و `themeStore.ts` و `viewer.ts`
 * **بررسی می‌کردند**. الگوی درست در پروژه وجود داشت و این سه جا از قلم
 * افتاده بودند.
 */

describe('ورودیِ خارجی', () => {
  beforeEach(() => {
    window.localStorage.clear()
  })

  describe('تاریخچهٔ ماشین‌حساب', () => {
    it('ردیفِ سالم را می‌پذیرد', () => {
      expect(
        isHistory([{ id: 'a', expression: '۱+۱', result: '۲', angleMode: 'deg', at: 1 }]),
      ).toBe(true)
    })

    it('آرایهٔ خالی هم معتبر است', () => {
      expect(isHistory([])).toBe(true)
    })

    /** 🔴 همان چیزی که صفحه را سفید می‌کرد: مقداری که اصلاً آرایه نیست. */
    it('چیزی که آرایه نیست را رد می‌کند', () => {
      const invalid: Array<[string, unknown]> = [
        ['null', null],
        ['undefined', undefined],
        ['عدد', 42],
        ['رشته', 'رشته'],
        ['شیء', {}],
        ['بولی', true],
      ]

      for (const [label, bad] of invalid) {
        expect(isHistory(bad), `«${label}» نباید معتبر باشد.`).toBe(false)
      }
    })

    /** ⚠️ آرایه‌ای که ردیفش شکلِ درستی ندارد هم رد می‌شود. */
    it('ردیفِ ناقص را رد می‌کند', () => {
      expect(isHistory([{ id: 'a' }])).toBe(false)
      expect(isHistory([{ id: 1, expression: 'x', result: 'y', at: 2 }])).toBe(false)
      expect(isHistory([null])).toBe(false)
      expect(isHistory(['رشته'])).toBe(false)
    })
  })

  describe('حافظهٔ ماشین‌حساب', () => {
    it('عددِ متناهی را می‌پذیرد', () => {
      expect(isMemory(0)).toBe(true)
      expect(isMemory(-12.5)).toBe(true)
    })

    /**
     * 🔴 `NaN` و `Infinity` عمداً رد می‌شوند.
     *
     * ⚠️ هر دو `typeof === 'number'` هستند، پس بررسیِ ساده‌ی نوع از کنارشان
     * رد می‌شد — و بعد در محاسبه پخش می‌شدند و همه‌چیز `NaN` می‌شد.
     */
    it('عددِ نامتناهی و NaN را رد می‌کند', () => {
      expect(isMemory(Number.NaN)).toBe(false)
      expect(isMemory(Number.POSITIVE_INFINITY)).toBe(false)
      expect(isMemory('12')).toBe(false)
      expect(isMemory(null)).toBe(false)
    })
  })

  describe('تاریخچهٔ جست‌وجو', () => {
    it('ردیفِ سالم را می‌پذیرد', () => {
      expect(isRecent([{ query: 'مالک', total: 3, at: 1 }])).toBe(true)
      expect(isRecent([])).toBe(true)
    })

    /** 🔴 دادهٔ خراب یعنی «تاریخچه‌ای نداریم»، نه «هرچه بود را رندر کن». */
    it('شکلِ نامعتبر را رد می‌کند', () => {
      expect(isRecent({ not: 'an array' })).toBe(false)
      expect(isRecent([{ query: 123, total: 1, at: 1 }])).toBe(false)
      expect(isRecent([{ query: 'x' }])).toBe(false)
      expect(isRecent([null])).toBe(false)
      expect(isRecent(null)).toBe(false)
    })
  })
})

/**
 * 🔴 و اینکه هوک واقعاً از نگهبان **استفاده** می‌کند.
 *
 * ─── چرا این تست جدا لازم بود ──────────────────────────────────────────────
 * ⚠️ تست‌های بالا خودِ نگهبان‌ها را می‌سنجند. ولی اگر کسی `isValid(parsed)`
 * را از داخلِ `useLocalStorage` بردارد، هیچ‌کدامشان قرمز نمی‌شوند —
 * نگهبان‌ها سالم می‌مانند و فقط دیگر صدا زده نمی‌شوند.
 *
 * 🔴 همان الگوی همیشگی: محافظِ درست، در جای نادرست صدا زده نشده.
 */
describe('هوکِ ذخیره‌سازی نگهبان را صدا می‌زند', () => {
  it('مقدارِ نامعتبر به مقدارِ اولیه برمی‌گردد', async () => {
    const { renderHook } = await import('@testing-library/react')
    const { useLocalStorage } = await import('@/shared/hooks/useLocalStorage')

    window.localStorage.setItem('probe', JSON.stringify({ shape: 'wrong' }))

    const { result } = renderHook(() =>
      useLocalStorage<number[]>('probe', [7], (v): v is number[] => Array.isArray(v)),
    )

    expect(result.current[0], 'دادهٔ نامعتبر وارد رندر شد.').toEqual([7])
  })

  it('مقدارِ معتبر خوانده می‌شود', async () => {
    const { renderHook } = await import('@testing-library/react')
    const { useLocalStorage } = await import('@/shared/hooks/useLocalStorage')

    window.localStorage.setItem('probe2', JSON.stringify([1, 2, 3]))

    const { result } = renderHook(() =>
      useLocalStorage<number[]>('probe2', [], (v): v is number[] => Array.isArray(v)),
    )

    expect(result.current[0]).toEqual([1, 2, 3])
  })
})
