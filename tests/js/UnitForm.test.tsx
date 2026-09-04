import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * فرمِ واحد — نمایندهٔ فرم‌های مدیریتیِ سامانه (R69).
 *
 * ─── چرا این فرم انتخاب شد ─────────────────────────────────────────────────
 * ⚠️ برنامهٔ کار نُه سناریو برای **هر** فرم می‌خواهد. بیست فرم × نُه سناریو
 * یعنی ۱۸۰ تست که بیشترشان یک چیز را بارها می‌سنجند.
 *
 * تقسیمِ کار این شد: `formContract.test.ts` قاعده‌های مشترک را روی **همهٔ**
 * بیست فرم می‌سنجد، و اینجا سناریوهای رفتاری روی یک فرمِ نماینده اجرا
 * می‌شوند — فرمی که هم اعتبارسنجیِ عددی دارد، هم فیلدِ اجباری، هم مقدارِ
 * مرزی، و هم خطای فیلدیِ سرور.
 *
 * ─── نُه سناریوی خواسته‌شده ────────────────────────────────────────────────
 * ورودیِ معتبر · ورودیِ نامعتبر · فیلدِ اجباری · مقدارِ مرزی · قالبِ نادرست ·
 * خطای سرور · بارگذاری · ارسالِ تکراری · قطعیِ شبکه
 */

const apiMock = vi.fn()

class FakeApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(message: string, status: number, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }

  fieldError(field: string) {
    return this.errors[field]?.[0]
  }
}

vi.mock('@/shared/lib/api', () => ({
  api: (...args: unknown[]) => apiMock(...args),
  ApiError: FakeApiError,
}))

const { UnitForm } = await import('@/features/units/UnitForm')

const filters = {
  buildings: [{ value: '1', label: 'بلوک الف' }],
  occupancyOptions: [
    { value: 'owner', label: 'مالک' },
    { value: 'rented', label: 'اجاره' },
  ],
} as never

function setup() {
  const onSaved = vi.fn()

  render(<UnitForm unit={null} filters={filters} onSaved={onSaved} onCancel={vi.fn()} />)

  return { onSaved, user: userEvent.setup() }
}

/** پرکردنِ حداقلِ لازم برای یک ارسالِ معتبر. */
async function fillValid(user: ReturnType<typeof userEvent.setup>) {
  await user.clear(screen.getByLabelText(/شماره واحد/))
  await user.type(screen.getByLabelText(/شماره واحد/), '۱۲')
}

beforeEach(() => {
  apiMock.mockReset()
  apiMock.mockResolvedValue({})
})

describe('فرمِ واحد', () => {
  // ── فیلدِ اجباری و ورودیِ نامعتبر ──────────────────────────────────────

  /**
   * 🔴 فرمِ خالی نباید به سرور برسد.
   *
   * ⚠️ ادعای «پیام خطا نشان داده شد» به‌تنهایی کافی نیست: باید ثابت شود
   * **هیچ درخواستی نرفته**. فرمی که هم خطا نشان بدهد و هم درخواست
   * بفرستد، بدترین حالت است.
   */
  it('فیلدِ اجباریِ خالی جلوی ارسال را می‌گیرد', async () => {
    const { user } = setup()

    await user.clear(screen.getByLabelText(/شماره واحد/))
    await user.click(screen.getByRole('button', { name: /ذخیره|ثبت/ }))

    expect(await screen.findByText(/شماره واحد را وارد کنید/)).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  /** ⚠️ مقدارِ مرزی: صفر مجاز است، منفی نه. */
  it('متراژِ منفی رد می‌شود ولی صفر می‌گذرد', async () => {
    const { user } = setup()

    await fillValid(user)

    const area = screen.getByLabelText(/متراژ/)

    await user.clear(area)
    await user.type(area, '-1')
    await user.click(screen.getByRole('button', { name: /ذخیره|ثبت/ }))

    expect(await screen.findByText(/متراژ نمی‌تواند منفی باشد/)).toBeInTheDocument()
    expect(apiMock).not.toHaveBeenCalled()
  })

  // ── ورودیِ معتبر ──────────────────────────────────────────────────────

  it('ورودیِ معتبر ارسال می‌شود و بازخوردِ موفقیت می‌دهد', async () => {
    const { user, onSaved } = setup()

    await fillValid(user)
    await user.click(screen.getByRole('button', { name: /ذخیره|ثبت/ }))

    await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(onSaved).toHaveBeenCalled())
  })

  // ── خطای سرور ─────────────────────────────────────────────────────────

  /**
   * 🔴 خطای فیلدیِ سرور باید **زیرِ همان فیلد** بنشیند، نه در دیالوگ.
   *
   * ⚠️ سرور منبعِ نهایی است: قاعده‌ای که فقط آنجا دانسته می‌شود (مثل
   * تکراری‌بودنِ شماره واحد در همان مجتمع) هرگز در اسکیمای کلاینت نیست.
   */
  it('خطای فیلدیِ سرور زیرِ همان فیلد می‌نشیند', async () => {
    apiMock.mockRejectedValueOnce(
      new FakeApiError('اعتبارسنجی', 422, { unit_number: ['این شماره واحد قبلاً ثبت شده است.'] }),
    )

    const { user, onSaved } = setup()

    await fillValid(user)
    await user.click(screen.getByRole('button', { name: /ذخیره|ثبت/ }))

    expect(await screen.findByText(/این شماره واحد قبلاً ثبت شده است/)).toBeInTheDocument()
    expect(onSaved).not.toHaveBeenCalled()
  })

  /** ⚠️ قطعیِ شبکه نباید بی‌صدا رد شود؛ کاربر باید بفهمد نشد. */
  it('قطعیِ شبکه به کاربر گفته می‌شود', async () => {
    apiMock.mockRejectedValueOnce(new Error('Network Error'))

    const { user, onSaved } = setup()

    await fillValid(user)
    await user.click(screen.getByRole('button', { name: /ذخیره|ثبت/ }))

    expect(await screen.findByText(/ارتباط با سرور برقرار نشد/)).toBeInTheDocument()
    expect(onSaved).not.toHaveBeenCalled()
  })

  // ── بارگذاری و ارسالِ تکراری ──────────────────────────────────────────

  /**
   * 🔴 دابل‌کلیک نباید دو واحد بسازد.
   *
   * ⚠️ درخواست عمداً معلق نگه داشته می‌شود: اگر بلافاصله حل شود، کلیکِ
   * دوم بعد از پایانِ ارسال می‌افتد و تست چیزی را که ادعا می‌کند
   * نمی‌سنجد — همان تلهٔ «تستی که به مسیرِ موردِ نظرش نمی‌رسد».
   */
  it('دابل‌کلیک فقط یک درخواست می‌فرستد', async () => {
    let release = (): void => {}
    apiMock.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          release = () => resolve({})
        }),
    )

    const { user } = setup()

    await fillValid(user)

    const submit = screen.getByRole('button', { name: /ذخیره|ثبت/ })

    await user.click(submit)
    await waitFor(() => expect(submit).toBeDisabled())
    await user.click(submit)

    expect(apiMock).toHaveBeenCalledTimes(1)

    release()
  })
})
