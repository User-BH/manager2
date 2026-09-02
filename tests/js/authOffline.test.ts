import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/shared/lib/apiError'

/**
 * وضعیتِ ورود وقتی شبکه نیست (R60).
 *
 * ─── چرا این تست تازه لازم شد ──────────────────────────────────────────────
 * تا R60 پاسخِ `/me` توسط service worker کش می‌شد، پس در قطعیِ شبکه از کش
 * می‌آمد و کاربر «وارد» می‌ماند.
 *
 * ⚠️ ولی آن کش خودش نشت بود: می‌گفت کاربر وارد است **حتی وقتی نشستِ سرور
 * بسته شده بود** — روی دستگاهِ مشترک یعنی نفرِ بعدی هویتِ نفرِ قبلی را
 * می‌دید.
 *
 * حالا `/me` هرگز کش نمی‌شود. اگر `refresh()` هر خطایی را «خارج شده»
 * بخواند، اولین قطعیِ لحظه‌ایِ شبکه کاربر را از برنامه می‌اندازد بیرون.
 * پس تفکیک لازم شد و اینجا سنجیده می‌شود.
 */

const apiMock = vi.hoisted(() => vi.fn())

vi.mock('@/shared/lib/api', () => ({ api: apiMock }))
vi.mock('@/shared/pwa/registerServiceWorker', () => ({ clearApiCache: vi.fn() }))

const { useAuthStore } = await import('@/shared/stores/authStore')

const someone = { id: 1, name: 'ساکن' } as never

describe('تازه‌کردنِ وضعیتِ ورود', () => {
  beforeEach(() => {
    apiMock.mockReset()
    useAuthStore.setState({ user: null, isLoading: true })
  })

  it('پاسخِ سالمِ سرور را می‌نشاند', async () => {
    apiMock.mockResolvedValue({ user: someone })

    await useAuthStore.getState().refresh()

    expect(useAuthStore.getState().user).toBe(someone)
    expect(useAuthStore.getState().isLoading).toBe(false)
  })

  /**
   * 🔴 ادعای اصلیِ این فایل.
   *
   * `status === 0` یعنی درخواست اصلاً به سرور نرسید. سرور چیزی درباره‌ی
   * این کاربر نگفته، پس نباید طوری رفتار کنیم که انگار گفته.
   */
  it('قطعیِ شبکه کاربرِ واردشده را بیرون نمی‌اندازد', async () => {
    useAuthStore.setState({ user: someone, isLoading: false })
    apiMock.mockRejectedValue(new ApiError('ارتباط با سرور برقرار نشد.', 0))

    await useAuthStore.getState().refresh()

    expect(useAuthStore.getState().user).toBe(someone)
    expect(useAuthStore.getState().isLoading).toBe(false)
  })

  /**
   * ⚠️ متقارنِ تستِ بالا، و بدونِ آن آن یکی خطرناک است.
   *
   * اگر «هر خطا یعنی هنوز واردی» شود، نشستِ باطل‌شده هم کاربر را در
   * برنامه نگه می‌دارد — یعنی همان نشتی که R60 برای بستنش آمده، از در
   * دیگری برمی‌گردد.
   */
  it('پاسخِ ۴۰۱ سرور کاربر را بیرون می‌اندازد', async () => {
    useAuthStore.setState({ user: someone, isLoading: false })
    apiMock.mockRejectedValue(new ApiError('احراز هویت لازم است.', 401))

    await useAuthStore.getState().refresh()

    expect(useAuthStore.getState().user).toBeNull()
  })

  it('خطای ناشناخته هم محافظه‌کارانه یعنی خارج‌شده', async () => {
    useAuthStore.setState({ user: someone, isLoading: false })
    apiMock.mockRejectedValue(new Error('چیزی که ApiError نیست'))

    await useAuthStore.getState().refresh()

    expect(useAuthStore.getState().user).toBeNull()
  })
})
