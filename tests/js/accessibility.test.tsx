import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { CheckField, SelectField, TextField } from '@/shared/ui/Field'
import { FormField } from '@/features/auth/components/FormField'
import { useAutoFocus } from '@/shared/hooks/useAutoFocus'
import { Phone } from 'lucide-react'

/**
 * دسترس‌پذیریِ فرم‌ها و فوکوس (R37).
 *
 * ─── باگی که اندازه‌گیری پیدا کرد ──────────────────────────────────────────
 * ⚠️ در مرورگر روی `/auth` هر دو ورودیِ شماره و رمز از دیدِ صفحه‌خوان
 * **بی‌نام** بودند. برچسب روی صفحه دیده می‌شد ولی `<label>` کنارِ ورودی
 * رندر می‌شد نه متصل به آن: نه `htmlFor` داشت نه ورودی را در بر می‌گرفت.
 *
 * چیزی که «درست به‌نظر می‌رسد» ولی از دیدِ صفحه‌خوان وجود ندارد، بدونِ تست
 * دقیقاً به همان شکل برمی‌گردد.
 */

afterEach(() => {
  vi.restoreAllMocks()
})

/** ماوس/ترک‌پد دارد؟ — قلابِ فوکوس فقط آنجا کار می‌کند. */
function stubPointer(fine: boolean, reducedMotion = false) {
  vi.spyOn(window, 'matchMedia').mockImplementation(
    (query: string) =>
      ({
        matches: query.includes('pointer: fine')
          ? fine
          : query.includes('reduced-motion')
            ? reducedMotion
            : false,
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      }) as unknown as MediaQueryList,
  )
}

describe('برچسبِ ورودی‌ها', () => {
  it('TextField نامِ قابلِ‌دسترس دارد', () => {
    render(<TextField label="شماره موبایل" />)

    // اگر برچسب متصل نباشد، این کوئری اصلاً چیزی پیدا نمی‌کند
    expect(screen.getByLabelText('شماره موبایل')).toBeInTheDocument()
  })

  it('SelectField هم همان‌طور', () => {
    render(<SelectField label="نوع واحد" options={[{ value: 1, label: 'مسکونی' }]} />)

    expect(screen.getByLabelText('نوع واحد')).toBeInTheDocument()
  })

  it('CheckField برچسبِ دربرگیرنده دارد', () => {
    render(<CheckField label="مرا به‌خاطر بسپار" />)

    expect(screen.getByLabelText('مرا به‌خاطر بسپار')).toBeInTheDocument()
  })

  it('فرمِ ورود هم نامِ قابلِ‌دسترس دارد', () => {
    render(<FormField label="رمز عبور" icon={Phone} type="password" />)

    expect(screen.getByLabelText('رمز عبور')).toBeInTheDocument()
  })

  /**
   * ⚠️ دو نمونه از یک فرم روی یک صفحه شناسه‌ی تکراری نگیرند.
   *
   * صفحه‌ی ورود **هر دو** فرمِ ورود و ثبت‌نام را هم‌زمان mount می‌کند. با
   * `id`ِ دستی، برچسبِ دومی به ورودیِ اولی می‌چسبید و کلیکِ کاربر روی
   * برچسبِ فرمِ ثبت‌نام، فوکوس را به فرمِ ورود می‌برد.
   */
  it('دو نمونه از یک فیلد شناسه‌ی یکتا می‌گیرند', () => {
    const { container } = render(
      <>
        <TextField label="شماره موبایل" />
        <TextField label="شماره موبایل" />
      </>,
    )

    const ids = [...container.querySelectorAll('input')].map((i) => i.id)

    expect(ids.filter(Boolean)).toHaveLength(2)
    expect(new Set(ids).size).toBe(2)
  })
})

describe('اعلامِ خطا به صفحه‌خوان', () => {
  it('خطا با aria-invalid و aria-describedby به ورودی وصل می‌شود', () => {
    render(<TextField label="شماره موبایل" error="شماره باید ۱۱ رقم باشد" />)

    const input = screen.getByLabelText('شماره موبایل')

    expect(input).toHaveAttribute('aria-invalid', 'true')

    const describedBy = input.getAttribute('aria-describedby')

    expect(describedBy).toBeTruthy()
    expect(document.getElementById(describedBy!)).toHaveTextContent('شماره باید ۱۱ رقم باشد')
  })

  it('پیامِ خطا role=alert دارد تا همان لحظه خوانده شود', () => {
    render(<TextField label="شماره موبایل" error="شماره نادرست است" />)

    expect(screen.getByRole('alert')).toHaveTextContent('شماره نادرست است')
  })

  it('بدونِ خطا، ورودی aria-invalid نمی‌گیرد', () => {
    render(<TextField label="شماره موبایل" />)

    // `aria-invalid="false"` هم درست است ولی نبودنش تمیزتر است
    expect(screen.getByLabelText('شماره موبایل')).not.toHaveAttribute('aria-invalid')
  })
})

describe('دکمه‌ی نمایشِ رمز', () => {
  it('از مسیرِ کیبورد بیرون نیست', async () => {
    render(<FormField label="رمز عبور" icon={Phone} type="password" />)

    const toggle = screen.getByRole('button', { name: /نمایش رمز/ })

    /*
     * ⚠️ پیش از این `tabIndex={-1}` داشت. کاربری که با کیبورد کار می‌کند هم
     * حق دارد رمزش را ببیند — و برای او این تنها راهِ بررسیِ چیزی است که
     * تایپ کرده.
     */
    expect(toggle).not.toHaveAttribute('tabindex', '-1')

    await userEvent.click(toggle)

    expect(screen.getByLabelText('رمز عبور')).toHaveAttribute('type', 'text')
  })
})

describe('فوکوسِ خودکار', () => {
  function Form({ enabled = true }: { enabled?: boolean }) {
    const ref = useAutoFocus<HTMLFormElement>(enabled)

    return (
      <form ref={ref}>
        <input type="checkbox" aria-label="مرا به‌خاطر بسپار" />
        <input type="text" aria-label="شماره موبایل" />
        <input type="text" aria-label="رمز عبور" />
      </form>
    )
  }

  it('روی دستگاهِ با ماوس، اولین ورودیِ متنی فوکوس می‌گیرد', () => {
    stubPointer(true)
    render(<Form />)

    expect(screen.getByLabelText('شماره موبایل')).toHaveFocus()
  })

  /**
   * ⚠️ چک‌باکس نباید اولین فوکوس باشد.
   *
   * «مرا به‌خاطر بسپار» از نظرِ ترتیبِ DOM ممکن است اول بیاید، ولی فوکوسِ
   * خودکار روی آن یعنی کاربر تایپ می‌کند و هیچ‌جا نوشته نمی‌شود.
   */
  it('چک‌باکس را رد می‌کند', () => {
    stubPointer(true)
    render(<Form />)

    expect(screen.getByLabelText('مرا به‌خاطر بسپار')).not.toHaveFocus()
  })

  /**
   * ⚠️ روی موبایل فوکوسِ خودکار **نباید** رخ دهد.
   *
   * صفحه‌کلیدِ لمسی بالا می‌آید و نصفِ صفحه را می‌پوشاند پیش از آنکه کاربر
   * فرم را دیده باشد؛ اولین کارش بستنِ صفحه‌کلید می‌شود.
   */
  it('روی دستگاهِ لمسی فوکوس نمی‌دهد', () => {
    stubPointer(false)
    render(<Form />)

    expect(screen.getByLabelText('شماره موبایل')).not.toHaveFocus()
  })

  it('با enabled=false کاری نمی‌کند', () => {
    stubPointer(true)
    render(<Form enabled={false} />)

    expect(screen.getByLabelText('شماره موبایل')).not.toHaveFocus()
  })

  /**
   * با «کاهشِ حرکت»، فوکوس بدونِ اسکرول انجام می‌شود.
   *
   * پرشِ ناگهانیِ صفحه به‌سمتِ فیلد دقیقاً همان چیزی است که آن تنظیم برای
   * نبودنش روشن می‌شود.
   */
  it('با کاهشِ حرکت، اسکرول نمی‌کند', () => {
    stubPointer(true, true)

    const focus = vi.spyOn(HTMLElement.prototype, 'focus')

    render(<Form />)

    expect(focus).toHaveBeenCalledWith({ preventScroll: true })
  })
})
