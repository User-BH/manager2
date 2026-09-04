import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * راهنمای خطا واقعاً به دیالوگ می‌رسد (R69).
 *
 * ─── 🔴 چرا این فایل جدا از `errorUx.test.ts` است ───────────────────────────
 * آن فایل **نگاشت** را می‌سنجد: کدِ ۴۰۹ چه راهنمایی می‌دهد. ولی نگاشتی که
 * هیچ‌کس صدایش نزند، هیچ کاری نمی‌کند.
 *
 * ⚠️ همان درسِ تکرارشونده‌ی این پروژه (R59، R61، R62، R63، R64): تستِ
 * واحدِ یک قطعه ثابت نمی‌کند آن قطعه **سیم‌کشی** شده. اینجا `alertError`
 * واقعاً صدا زده می‌شود و محتوای دیالوگ سنجیده می‌شود.
 */

const fireMock = vi.fn().mockResolvedValue({ isConfirmed: false })

vi.mock('sweetalert2', () => ({
  default: { mixin: () => ({ fire: (options: unknown) => fireMock(options) }) },
}))

const { alertError } = await import('@/shared/lib/alert')
const { ApiError } = await import('@/shared/lib/apiError')

/** ⚠️ ماژولِ SweetAlert تنبل بار می‌شود؛ باید منتظرِ رسیدنش ماند. */
async function settle(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
}

beforeEach(() => {
  fireMock.mockClear()
})

describe('نمایشِ راهنما در دیالوگِ خطا', () => {
  it('راهنمای کدِ وضعیت داخلِ دیالوگ می‌آید', async () => {
    alertError(new ApiError('وضعیت تغییر کرده', 409))
    await settle()

    expect(fireMock).toHaveBeenCalledTimes(1)

    const options = fireMock.mock.calls[0][0] as { title: string; html?: string; icon: string }

    expect(options.title).toBe('وضعیت تغییر کرده')
    expect(options.html).toContain('صفحه را تازه کنید')
    expect(options.icon).toBe('warning')
  })

  /** ⚠️ زمانِ واقعیِ انتظار باید در متنِ دیالوگ دیده شود، نه فقط محاسبه. */
  it('زمانِ انتظارِ ۴۲۹ در دیالوگ نوشته می‌شود', async () => {
    alertError(new ApiError('زیاد تلاش شد', 429, {}, 45_000))
    await settle()

    const options = fireMock.mock.calls[0][0] as { html?: string }

    expect(options.html).toContain('45 ثانیه')
  })

  it('نشستِ تمام‌شده آیکونِ اطلاع می‌گیرد، نه خطا', async () => {
    alertError(new ApiError('برای این کار باید وارد شوید.', 401))
    await settle()

    const options = fireMock.mock.calls[0][0] as { icon: string; html?: string }

    expect(options.icon).toBe('info')
    expect(options.html).toContain('دوباره تلاش کنید')
  })

  /**
   * ⚠️ ۴۲۲ عمداً راهنمای عمومی ندارد و خطاهای فیلد جایش را می‌گیرند.
   *
   * افزودنِ جمله‌ی عمومی به دیالوگی که همین حالا فهرستِ خطاها را دارد،
   * فقط شلوغی است.
   */
  it('خطای اعتبارسنجی راهنمای عمومی نمی‌گیرد', async () => {
    alertError(
      new ApiError('اعتبارسنجی', 422, { name: ['نام لازم است'], phone: ['شماره لازم است'] }),
    )
    await settle()

    const options = fireMock.mock.calls[0][0] as { html?: string }

    expect(options.html).toContain('نام لازم است')
    expect(options.html).not.toContain('swal-app-hint')
  })
})
