import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { Modal } from '@/shared/ui/Modal'

/**
 * مدیریتِ Focus در مودال (مرحلهٔ ۵، فاز ۵ — بندهای ۳۴ و ۳۵).
 *
 * ─── 🔴 چه چیزی باز بود ────────────────────────────────────────────────────
 * `Modal` مشترک `role="dialog"` و `aria-modal` داشت و Escape را می‌گرفت. ولی:
 *
 *   • Focus هنگام بازشدن روی دکمهٔ **پشتِ** روکش می‌ماند
 *   • Tab از مودال بیرون می‌رفت
 *   • پس از بستن، Focus به هیچ‌جا برنمی‌گشت
 *
 * ⚠️ `aria-modal="true"` به صفحه‌خوان می‌گوید «بقیه را نادیده بگیر»، ولی
 * کیبورد را نمی‌بندد. برای کاربرِ Tab، مودال «باز شده» بود و نمی‌شد واردش شد.
 *
 * ─── چرا رفتاری و نه متنی ──────────────────────────────────────────────────
 * 🔴 تستِ متنی فقط می‌گوید «useFocusTrap صدا زده شده». این تست کلید می‌زند و
 * می‌بیند Focus **واقعاً** کجا می‌رود.
 */

function Harness() {
  const [open, setOpen] = useState(false)

  return (
    <div>
      <button type="button" onClick={() => setOpen(true)}>
        بازکردن
      </button>
      <button type="button">دکمهٔ پشتی</button>

      <Modal open={open} title="آزمون" onClose={() => setOpen(false)}>
        <input aria-label="اول" />
        <input aria-label="دوم" />
        <button type="button">آخر</button>
      </Modal>
    </div>
  )
}

function tab(shift = false) {
  fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Tab', shiftKey: shift })
}

describe('مدیریتِ Focus در مودال', () => {
  /** 🔴 با بازشدن، Focus باید **داخلِ** مودال برود، نه روی دکمهٔ پشتِ روکش بماند. */
  it('با بازشدن، Focus به اولین عنصرِ داخلِ مودال می‌رود', () => {
    render(<Harness />)

    const opener = screen.getByText('بازکردن')
    opener.focus()
    expect(document.activeElement).toBe(opener)

    fireEvent.click(opener)

    /*
     * ⚠️ ادعا روی **اولین عنصر**، نه صرفاً «جایی داخلِ مودال».
     *
     * 🔴 نسخهٔ اول فقط `contains` را می‌سنجید و خرابکاری از تورش رد شد:
     * با حذفِ `first.focus()`، مسیرِ جایگزین Focus را روی خودِ پنل
     * می‌گذاشت — که «داخلِ مودال» بود ولی جایی نبود که کاربر بتواند با
     * آن کاری بکند. تست ضعیف‌تر از نامش بود.
     */
    expect(document.activeElement, 'Focus روی اولین عنصرِ قابلِ استفاده نیست.').toBe(
      screen.getByLabelText('بستن'),
    )
  })

  /** 🔴 Tab روی آخرین عنصر باید به اولین برگردد، نه از مودال بیرون برود. */
  it('Tab از آخرین عنصر به اولین برمی‌گردد', () => {
    render(<Harness />)
    fireEvent.click(screen.getByText('بازکردن'))

    const last = screen.getByText('آخر')
    last.focus()
    expect(document.activeElement).toBe(last)

    tab()

    const dialog = screen.getByRole('dialog')
    expect(dialog.contains(document.activeElement), 'Tab از مودال بیرون رفت.').toBe(true)
    expect(document.activeElement).not.toBe(last)
  })

  /** ⚠️ و در جهتِ برعکس: Shift+Tab روی اولین عنصر به آخرین می‌رود. */
  it('Shift+Tab از اولین عنصر به آخرین می‌رود', () => {
    render(<Harness />)
    fireEvent.click(screen.getByText('بازکردن'))

    const dialog = screen.getByRole('dialog')
    const closeButton = screen.getByLabelText('بستن')
    closeButton.focus()

    tab(true)

    expect(dialog.contains(document.activeElement), 'Shift+Tab از مودال بیرون رفت.').toBe(true)
    expect(document.activeElement).toBe(screen.getByText('آخر'))
  })

  /**
   * 🔴 پس از بستن، Focus به همان دکمه‌ای برمی‌گردد که مودال را باز کرده بود.
   *
   * ⚠️ بدونِ این، Focus به `<body>` می‌افتد و کاربرِ کیبورد باید از اولِ
   * صفحه دوباره راه بیفتد.
   */
  it('با بستن، Focus به بازکننده برمی‌گردد', () => {
    render(<Harness />)

    const opener = screen.getByText('بازکردن')
    opener.focus()
    fireEvent.click(opener)

    expect(screen.getByRole('dialog').contains(document.activeElement)).toBe(true)

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(document.activeElement, 'Focus به بازکننده برنگشت.').toBe(opener)
  })

  /** ⚠️ دکمهٔ پشتِ روکش هرگز Focus نمی‌گیرد تا مودال باز است. */
  it('عنصرِ بیرونِ مودال با Tab قابلِ رسیدن نیست', () => {
    render(<Harness />)
    fireEvent.click(screen.getByText('بازکردن'))

    const behind = screen.getByText('دکمهٔ پشتی')
    const dialog = screen.getByRole('dialog')

    // ده بار Tab — به اندازهٔ کافی که یک چرخهٔ کامل بزند
    for (let i = 0; i < 10; i++) tab()

    expect(document.activeElement).not.toBe(behind)
    expect(dialog.contains(document.activeElement)).toBe(true)
  })
})
