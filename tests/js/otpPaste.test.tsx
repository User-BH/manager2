import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { OtpBoxes } from '@/features/auth/components/OtpBoxes'

/**
 * چسباندنِ کدِ پیامکی یا Authenticator (۲۰۲۶-۱۰-۳۰، بندِ ۵ ِ کارفرما). پیش از این `maxLength={1}` ِ هر خانه متنِ چسبانده را
 * پیش از `onChange` به یک رقم می‌بُرد و چسباندن کار نمی‌کرد.
 */
function Harness({ onComplete }: { onComplete: (code: string) => void }) {
  const [value, setValue] = useState('')
  return (
    <>
      <OtpBoxes value={value} onChange={setValue} onComplete={onComplete} />
      <output data-testid="value">{value}</output>
    </>
  )
}

function paste(target: HTMLElement, text: string) {
  fireEvent.paste(target, { clipboardData: { getData: () => text } })
}

describe('چسباندنِ کد', () => {
  it.each([
    ['123456', '123456'],
    ['۱۲۳۴۵۶', '123456'],
    [' 123-456 ', '123456'],
    ['کد: 654321', '654321'],
  ])('«%s» همه‌ی خانه‌ها را پر و کد را ارسال می‌کند', (text, code) => {
    const onComplete = vi.fn()
    render(<Harness onComplete={onComplete} />)

    paste(screen.getByLabelText('رقم 1 کد'), text)

    expect(screen.getByTestId('value')).toHaveTextContent(code)
    expect(onComplete).toHaveBeenCalledWith(code)
    expect(screen.getByLabelText('رقم 6 کد')).toHaveValue(code[5])
  })

  it('از خانه‌ی وسط هم ادامه‌ی کد را پر می‌کند و متنِ بی‌رقم را نادیده می‌گیرد', () => {
    const onComplete = vi.fn()
    render(<Harness onComplete={onComplete} />)

    fireEvent.change(screen.getByLabelText('رقم 1 کد'), { target: { value: '9' } })
    paste(screen.getByLabelText('رقم 2 کد'), 'abc')
    expect(screen.getByTestId('value')).toHaveTextContent(/^9$/)

    paste(screen.getByLabelText('رقم 2 کد'), '87654')
    expect(screen.getByTestId('value')).toHaveTextContent('987654')
    expect(onComplete).toHaveBeenCalledWith('987654')
  })
})
