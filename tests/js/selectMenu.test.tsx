import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { SelectMenu } from '@/shared/ui/SelectMenu'
import { filterPersianName } from '@/features/landing/support/feedbackSchema'

/**
 * فهرستِ انتخابیِ سفارشی (مرحلهٔ ۸ · فاز ۵ · بندِ ۷) — همان رفتارِ select ِ
 * بومی برای کیبورد و صفحه‌خوان.
 */

const OPTIONS = [
  { value: 'improvement', label: 'پیشنهاد' },
  { value: 'criticism', label: 'انتقاد' },
  { value: 'bug', label: 'گزارشِ مشکل' },
  { value: 'other', label: 'سایر' },
]

function Harness({
  onChange = vi.fn(),
  error,
}: {
  onChange?: (v: string) => void
  error?: string
}) {
  const [value, setValue] = useState('')
  return (
    <>
      <SelectMenu
        label="نوعِ پیام"
        options={OPTIONS}
        value={value}
        onChange={(next) => {
          setValue(next)
          onChange(next)
        }}
        error={error}
      />
      <button type="button">بعدی</button>
    </>
  )
}

describe('SelectMenu', () => {
  it('نام و نقشِ درست برای صفحه‌خوان، بسته در آغاز', () => {
    render(<Harness />)
    const box = screen.getByRole('combobox', { name: 'نوعِ پیام' })

    expect(box).toHaveAttribute('aria-expanded', 'false')
    expect(box).toHaveAttribute('aria-haspopup', 'listbox')
    expect(box).toHaveTextContent('انتخاب کنید')
    expect(screen.queryByRole('listbox')).toBeNull()
  })

  it('کیبورد: ↓ باز می‌کند، ↓↑ حرکت، Enter انتخاب و بستن — فوکوس روی خودِ فهرست می‌ماند', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)
    const box = screen.getByRole('combobox', { name: 'نوعِ پیام' })

    box.focus()
    await user.keyboard('{ArrowDown}')
    expect(box).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('listbox')).toBeVisible()
    const first = screen.getByRole('option', { name: 'پیشنهاد' })
    expect(box).toHaveAttribute('aria-activedescendant', first.id)

    await user.keyboard('{ArrowDown}{ArrowDown}{ArrowUp}{ArrowDown}{Enter}')
    expect(onChange).toHaveBeenLastCalledWith('bug')
    expect(box).toHaveAttribute('aria-expanded', 'false')
    expect(box).toHaveTextContent('گزارشِ مشکل')
    expect(box).toHaveFocus()
  })

  it('Home/End، Esc بدونِ تغییر، و Tab انتخاب می‌کند و می‌رود', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)
    const box = screen.getByRole('combobox', { name: 'نوعِ پیام' })

    box.focus()
    await user.keyboard('{End}')
    expect(box).toHaveAttribute(
      'aria-activedescendant',
      screen.getByRole('option', { name: 'سایر' }).id,
    )
    await user.keyboard('{Escape}')
    expect(box).toHaveAttribute('aria-expanded', 'false')
    expect(onChange).not.toHaveBeenCalled()

    await user.keyboard('{Home}{ArrowDown}{Tab}')
    expect(onChange).toHaveBeenLastCalledWith('criticism')
    expect(screen.getByRole('button', { name: 'بعدی' })).toHaveFocus()
  })

  it('حرفِ اول به گزینه می‌پرد', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)
    screen.getByRole('combobox', { name: 'نوعِ پیام' }).focus()

    await user.keyboard('س{Enter}')
    expect(onChange).toHaveBeenLastCalledWith('other')
  })

  it('موس: کلیک باز، کلیکِ گزینه انتخاب، کلیکِ بیرون بی‌انتخاب می‌بندد', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)
    const box = screen.getByRole('combobox', { name: 'نوعِ پیام' })

    await user.click(box)
    await user.click(screen.getByRole('option', { name: 'انتقاد' }))
    expect(onChange).toHaveBeenCalledWith('criticism')
    expect(screen.getByRole('option', { hidden: true, name: 'انتقاد' })).toHaveAttribute(
      'aria-selected',
      'true',
    )

    await user.click(box)
    await user.click(document.body)
    expect(box).toHaveAttribute('aria-expanded', 'false')
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('خطا به کنترل وصل است', () => {
    render(<Harness error="نوعِ پیام را انتخاب کنید." />)
    const box = screen.getByRole('combobox', { name: 'نوعِ پیام' })

    expect(box).toHaveAttribute('aria-invalid', 'true')
    expect(box).toHaveAccessibleDescription('نوعِ پیام را انتخاب کنید.')
  })
})

describe('filterPersianName', () => {
  it.each([
    ['علی رضایی', 'علی رضایی', false],
    ['علی‌اکبر', 'علی‌اکبر', false],
    ['علي كاظمي', 'علی کاظمی', false],
    ['علی  رضایی', 'علی رضایی', false],
    ['Ali علی', 'علی', true],
    ['علی۲', 'علی', true],
    ['علی!', 'علی', true],
    ['مریم، رضایی', 'مریم رضایی', true],
    ['<script>', '', true],
  ])('«%s» ← «%s»', (input, expected, warned) => {
    const result = filterPersianName(input)
    expect(result.value).toBe(expected)
    expect(result.changed).toBe(warned)
  })
})
