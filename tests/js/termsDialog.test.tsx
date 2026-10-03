import { readFileSync } from 'node:fs'
import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import TermsDialog from '@/features/auth/components/TermsDialog'

/**
 * قوانین در ثبت‌نام (مرحلهٔ ۷ · فاز ۶).
 *
 * 🔴 پیوندِ قبلی `target="_blank"` بود و با ساکنای نصب‌شده در پنجره‌ی اپ باز
 * می‌شد. حالا کلیکِ ساده پنجره‌ی همین صفحه را باز می‌کند و ناوبری‌ای نمی‌سازد.
 * رفتارِ آدرس (مستقیم، بارگذاریِ دوباره، بازگشت/جلو) در
 * `tests/e2e/terms-link.spec.ts` سنجیده می‌شود.
 */

vi.mock('@/shared/lib/api', () => ({ api: vi.fn(), ApiError: Error, setCsrfToken: vi.fn() }))

const { RegisterForm } = await import('@/features/auth/components/RegisterForm')

describe('پیوندِ قوانین', () => {
  it('هیچ پنجره یا تبِ تازه‌ای نمی‌خواهد', () => {
    render(<RegisterForm onOpenTerms={vi.fn()} />)

    const link = screen.getByRole('link', { name: 'قوانین و مقررات استفاده از پنل' })
    expect(link).not.toHaveAttribute('target')
    // بی‌جاوااسکریپت هنوز به صفحه‌ی عمومیِ قوانین می‌رسد
    expect(link).toHaveAttribute('href', '/support?topic=terms')
  })

  it('کلیکِ ساده پنجره را باز می‌کند و ناوبری را می‌گیرد', async () => {
    const open = vi.fn()
    render(<RegisterForm onOpenTerms={open} />)

    const link = screen.getByRole('link', { name: 'قوانین و مقررات استفاده از پنل' })
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 })
    link.dispatchEvent(event)

    expect(open).toHaveBeenCalledOnce()
    expect(event.defaultPrevented).toBe(true)
    // پذیرشِ قوانین آگاهانه است؛ خواندن تیک نمی‌زند
    expect(screen.getByRole('checkbox')).not.toBeChecked()
    await userEvent.click(link)
    expect(screen.getByRole('checkbox')).not.toBeChecked()
  })

  it('Ctrl و کلیکِ میانی خواستِ صریحِ تبِ تازه‌اند و دست نمی‌خورند', () => {
    const open = vi.fn()
    render(<RegisterForm onOpenTerms={open} />)
    const link = screen.getByRole('link', { name: 'قوانین و مقررات استفاده از پنل' })

    fireEvent.click(link, { ctrlKey: true })
    fireEvent.click(link, { button: 1 })

    expect(open).not.toHaveBeenCalled()
  })
})

describe('پنجره‌ی قوانین', () => {
  const source = JSON.parse(readFileSync('resources/content/support-topics.json', 'utf8')) as {
    topics: Array<{ id: string; entries: Array<{ question: string; answer: string }> }>
  }
  const terms = source.topics.find((topic) => topic.id === 'terms')!

  it('همه‌ی بندها از همان منبعِ صفحه‌ی پشتیبانی، با عنوانِ پنجره', () => {
    render(<TermsDialog open onClose={vi.fn()} />)

    const dialog = screen.getByRole('dialog', { name: 'قوانین و مقررات' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    for (const entry of terms.entries) {
      expect(within(dialog).getByRole('heading', { name: entry.question })).toBeInTheDocument()
    }
  })

  it('`**…**` پررنگ می‌شود و هیچ HTMLی از متن ساخته نمی‌شود', () => {
    render(<TermsDialog open onClose={vi.fn()} />)
    const dialog = screen.getByRole('dialog')

    expect(dialog.textContent).not.toContain('**')
    expect(within(dialog).getByText('داده‌ای پاک نمی‌شود').tagName).toBe('STRONG')
    expect(dialog.querySelector('img, script, a')).toBeNull()
  })

  it('«بازگشت به ثبت‌نام» و Escape می‌بندند', async () => {
    const onClose = vi.fn()
    render(<TermsDialog open onClose={onClose} />)

    await userEvent.click(screen.getByRole('button', { name: 'بازگشت به ثبت‌نام' }))
    await userEvent.keyboard('{Escape}')

    expect(onClose).toHaveBeenCalledTimes(2)
  })
})
