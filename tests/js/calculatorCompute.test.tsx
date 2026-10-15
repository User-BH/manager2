import { act, fireEvent, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithQuery } from './helpers/renderWithQuery'

/**
 * ماشین‌حساب فقط با فرمانِ کاربر حساب می‌کند — «چند نکته»، بندِ ۶.
 *
 * `evaluate` جاسوسی می‌شود تا ادعا «محاسبه‌ای اجرا نشد» باشد، نه «چیزی دیده نشد»: پیش از این یک پیش‌نمایشِ زنده با هر
 * نویسه واقعاً حساب می‌کرد و نتیجه را زیرِ عبارت می‌گذاشت.
 */

const calculator =
  await vi.importActual<typeof import('@/shared/lib/calculator')>('@/shared/lib/calculator')
const evaluate = vi.fn(calculator.evaluate)
vi.mock('@/shared/lib/calculator', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/shared/lib/calculator')>()),
  evaluate: (...args: Parameters<typeof calculator.evaluate>) => evaluate(...args),
}))
vi.mock('@/shared/lib/alert', () => ({
  confirmAction: vi.fn(() => Promise.resolve(true)),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

const { CalculatorPage } = await import('@/features/tools/calculator/CalculatorPage')
const { toPersianDigits } = await import('@/shared/lib/format')

const shown = (value: string) => `= ${toPersianDigits(value)}`

beforeEach(() => {
  evaluate.mockClear()
  window.localStorage.clear()
})

function setup() {
  const user = userEvent.setup()
  renderWithQuery(<CalculatorPage />)
  return { user, input: screen.getByLabelText('عبارت ریاضی') }
}

describe('محاسبه فقط با «=» یا Enter', () => {
  it('هنگامِ تایپ هیچ محاسبه‌ای اجرا و هیچ نتیجه‌ای نشان داده نمی‌شود', async () => {
    const { user, input } = setup()

    await user.type(input, '12*3+4')

    expect(evaluate).not.toHaveBeenCalled()
    expect(screen.queryByText(shown('40'))).toBeNull()

    await user.keyboard('{Enter}')
    expect(evaluate).toHaveBeenCalledTimes(1)
    expect((await screen.findAllByText(shown('40'))).length).toBeGreaterThan(0)
  })

  it('دکمه‌ی «=» همان Enter است', async () => {
    const { user, input } = setup()

    await user.type(input, '7*6')
    await user.click(screen.getByRole('button', { name: 'محاسبه' }))

    expect(evaluate).toHaveBeenCalledTimes(1)
    expect((await screen.findAllByText(shown('42'))).length).toBeGreaterThan(0)
  })

  it('عبارتِ نامعتبر خطای قابلِ فهم می‌دهد، نه نتیجه؛ عبارتِ خالی هیچ کاری نمی‌کند', async () => {
    const { user, input } = setup()

    await user.keyboard('{Enter}')
    expect(evaluate).not.toHaveBeenCalled()

    await user.type(input, '2+{Enter}')
    const message = (() => {
      try {
        calculator.evaluate('2+', 'deg')
        return ''
      } catch (error) {
        return (error as Error).message
      }
    })()
    expect(message).not.toBe('')
    expect(await screen.findByText(message)).toBeInTheDocument()
    expect(screen.queryByText(/^= /)).toBeNull()
  })

  it('تکرارِ ناخواسته یک محاسبه است: Enter ِ دوباره، Enter ِ نگه‌داشته، و Enter روی دکمه‌ی «=»', async () => {
    const { user, input } = setup()

    await user.type(input, '5+5{Enter}{Enter}')
    expect(evaluate).toHaveBeenCalledTimes(1)

    // نگه داشتنِ کلید: رویدادهای تکراری
    await user.keyboard('{Backspace}6')
    await user.keyboard('{Enter>3/}')
    expect(evaluate).toHaveBeenCalledTimes(2)

    // Enter روی دکمه‌ی متمرکزِ «=»: فقط خودِ دکمه
    await user.type(input, '+1')
    const equals = screen.getByRole('button', { name: 'محاسبه' })
    act(() => equals.focus())
    await user.keyboard('{Enter}')
    expect(evaluate).toHaveBeenCalledTimes(3)

    const history = screen.getAllByTitle('بازگرداندن این عبارت به ماشین حساب')
    expect(history).toHaveLength(3)

    // Enter روی دکمه‌ی دیگری (حذف از تاریخچه) با عبارتِ حساب‌نشده: کارِ همان دکمه است، نه یک محاسبه‌ی اضافه
    await user.type(input, '*2')
    const remove = screen.getAllByRole('button', { name: 'حذف این مورد از تاریخچه' })[0]
    fireEvent.keyDown(remove, { key: 'Enter', bubbles: true })
    expect(evaluate).toHaveBeenCalledTimes(3)
  })

  it('ویرایشِ عبارت نتیجه‌ی قبلی را برمی‌دارد — نتیجه‌ی کهنه زیرِ عبارتِ تازه نمی‌ماند', async () => {
    const { user, input } = setup()

    await user.type(input, '9*9{Enter}')
    expect((await screen.findAllByText(shown('81'))).length).toBeGreaterThan(0)

    await user.type(input, '+1')
    expect(screen.queryByText(shown('81'), { selector: '[dir="ltr"].text-\\[22px\\]' })).toBeNull()
    expect(evaluate).toHaveBeenCalledTimes(1)
  })

  it('بازگرداندن از تاریخچه حالتِ زاویه‌ی همان محاسبه را هم برمی‌گرداند', async () => {
    const { user, input } = setup()

    await user.type(input, 'sin(30){Enter}')
    await user.click(screen.getByRole('button', { name: 'رادیان' }))
    await user.click(screen.getAllByTitle('بازگرداندن این عبارت به ماشین حساب')[0])

    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'درجه' })).toHaveStyle({ color: '#fff' }),
    )
  })

  it('دکمه‌های حافظه فقط با فرمانِ خودشان حساب می‌کنند؛ عبارتِ نامعتبر حافظه را دست نمی‌زند', async () => {
    const { user, input } = setup()

    await user.type(input, '2+')
    await user.click(screen.getByTitle('ذخیره در حافظه'))
    expect(screen.queryByText(/^حافظه:/)).toBeNull()

    await user.type(input, '3')
    expect(evaluate).toHaveBeenCalledTimes(1)
    await user.click(screen.getByTitle('ذخیره در حافظه'))
    expect(await screen.findByText(`حافظه: ${toPersianDigits('5')}`)).toBeInTheDocument()
    // مقدارِ حافظه محاسبه‌ای است که کاربر خواست، نه ردیفِ تاریخچه
    expect(screen.queryAllByTitle('بازگرداندن این عبارت به ماشین حساب')).toHaveLength(0)
  })
})
