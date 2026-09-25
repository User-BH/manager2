import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { render, screen } from '@testing-library/react'
import { createElement } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { copyText } from '@/shared/lib/clipboard'
import { Modal } from '@/shared/ui/Modal'

/**
 * سازگاریِ مرورگر (مرحلهٔ ۵ · فاز ۸ · بندِ ۶۶: Clipboard و ارتفاعِ مودال).
 *
 * 🔴 هر دو دکمه‌ی کپی `navigator.clipboard?.writeText` بودند و شکست را
 * می‌بلعیدند. روی http، در WebViewِ پیام‌رسان‌ها و Safariِ قدیمی دکمه هیچ
 * کاری نمی‌کرد — ساکن فکر می‌کرد شماره‌ی کارت کپی شده.
 */

const original = Object.getOwnPropertyDescriptor(navigator, 'clipboard')

afterEach(() => {
  if (original) Object.defineProperty(navigator, 'clipboard', original)
  else Reflect.deleteProperty(navigator, 'clipboard')
  vi.restoreAllMocks()
})

function setClipboard(value: unknown) {
  Object.defineProperty(navigator, 'clipboard', { value, configurable: true })
}

describe('کپی در کلیپ‌بورد', () => {
  it('با API جدید کپی می‌کند', async () => {
    const writeText = vi.fn(() => Promise.resolve())
    setClipboard({ writeText })

    expect(await copyText('6037-9975')).toBe(true)
    expect(writeText).toHaveBeenCalledWith('6037-9975')
  })

  it('وقتی API نیست، راهِ قدیمی را امتحان می‌کند', async () => {
    setClipboard(undefined)
    const exec = vi.fn(() => true)
    Object.defineProperty(document, 'execCommand', { value: exec, configurable: true })

    expect(await copyText('IR82')).toBe(true)
    expect(exec).toHaveBeenCalledWith('copy')
    // textareaِ موقت پس از کپی برداشته شد
    expect(document.querySelector('textarea')).toBeNull()
  })

  /** ⚠️ شکست باید **گزارش** شود تا کاربر دستی کپی کند، نه بلعیده شود. */
  it('وقتی هیچ راهی نیست، false می‌دهد', async () => {
    setClipboard({ writeText: () => Promise.reject(new Error('NotAllowedError')) })
    Object.defineProperty(document, 'execCommand', { value: () => false, configurable: true })

    expect(await copyText('x')).toBe(false)
  })
})

describe('هیچ کدی کلیپ‌بورد را مستقیم صدا نمی‌زند', () => {
  const JS = join(__dirname, '../../resources/js')

  function walk(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
      const path = join(dir, name)
      return statSync(path).isDirectory() ? walk(path) : [path]
    })
  }

  it('فقط `shared/lib/clipboard.ts`', () => {
    const direct = walk(JS)
      .filter((f) => /\.(ts|tsx)$/.test(f))
      .filter((f) =>
        /navigator\.clipboard/.test(readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')),
      )
      .map((f) => relative(JS, f).replace(/\\/g, '/'))

    expect(direct).toEqual(['shared/lib/clipboard.ts'])
  })
})

/**
 * 🔴 در Safariِ iOS، `92vh` نوارِ ابزارِ مرورگر را هم می‌شمرد و دکمه‌ی ذخیره‌ی
 * مودالِ بلند زیرِ نوار می‌رفت. `dvh` فقط جایی که پشتیبانی می‌شود؛ `vh`
 * برای مرورگرِ قدیمی باید بماند.
 */
describe('ارتفاعِ مودال روی موبایل', () => {
  it('dvh با پشتوانه‌ی vh', () => {
    render(createElement(Modal, { open: true, title: 'آزمون', onClose: () => {}, children: 'متن' }))
    const panel = screen.getByRole('dialog').className

    expect(panel).toContain('supports-[max-height:1dvh]:max-h-[92dvh]')
    expect(panel).toContain('max-h-[92vh]')
  })
})
