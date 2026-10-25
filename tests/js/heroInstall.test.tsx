import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HeroInstallButton } from '@/shared/pwa/HeroInstallButton'
import { InstallPrompt } from '@/shared/pwa/InstallPrompt'
import { installSnapshot, promptInstall } from '@/shared/pwa/installEvent'

/**
 * دکمه‌ی نصبِ Hero و انباره‌ی مشترکِ رویدادِ نصب (مرحلهٔ ۱۳ · فاز ۲؛ ایرادات ۱۱، بخشِ ۱).
 *
 * رویدادِ مرورگر همان شکلی ساخته می‌شود که کروم می‌فرستد (`beforeinstallprompt` با `prompt` و `userChoice`). آنچه سنجیده
 * می‌شود رفتارِ ما در برابرِ آن است: پنجره‌ی واقعی صدا زده می‌شود، «نصب شد» فقط با `appinstalled`، انصراف ادعای نصب
 * نمی‌سازد، بی رویداد پنجره‌ی ساختگی نیست، و دو مصرف‌کننده یک رویداد را دو بار مصرف نمی‌کنند.
 */

const ua = Object.getOwnPropertyDescriptor(window.navigator, 'userAgent')

function offer(outcome: 'accepted' | 'dismissed' = 'accepted') {
  const prompt = vi.fn(async () => {})
  const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
    prompt,
    userChoice: Promise.resolve({ outcome }),
  })
  act(() => {
    window.dispatchEvent(event)
  })
  return { prompt, event }
}

function pretend(userAgent: string, standalone?: boolean) {
  Object.defineProperty(window.navigator, 'userAgent', { configurable: true, value: userAgent })
  Object.defineProperty(window.navigator, 'standalone', { configurable: true, value: standalone })
}

afterEach(async () => {
  // رویدادِ مانده‌ی هر آزمون با همان مسیرِ واقعیِ کلیک مصرف می‌شود (انباره در سطحِ ماژول است)
  await promptInstall()
  if (ua) Object.defineProperty(window.navigator, 'userAgent', ua)
  delete (window.navigator as { standalone?: boolean }).standalone
})

describe('انباره‌ی رویدادِ نصب', () => {
  it('رویداد را نگه می‌دارد، نوارِ پیش‌فرضِ مرورگر را می‌گیرد و فقط یک بار مصرف می‌شود', async () => {
    const { prompt, event } = offer()

    expect(event.defaultPrevented).toBe(true)
    expect(installSnapshot().event).toBe(event)

    await expect(promptInstall()).resolves.toBe('accepted')
    // دومی دیگر رویدادی ندارد — نه خطا، نه پنجره‌ی دوم
    await expect(promptInstall()).resolves.toBe('unavailable')
    expect(prompt).toHaveBeenCalledOnce()
  })

  it('«نصب‌شده» فقط با `appinstalled` ِ مرورگر', async () => {
    offer('accepted')
    await promptInstall()
    expect(installSnapshot().installed).toBe(false)
  })
})

describe('دکمه‌ی نصبِ Hero', () => {
  it('مرورگر پیشنهاد داد: کلیک پنجره‌ی واقعیِ نصب را باز می‌کند؛ «نصب شد» فقط پس از `appinstalled`', async () => {
    const user = userEvent.setup()
    render(<HeroInstallButton />)
    const { prompt } = offer('accepted')

    await user.click(screen.getByTestId('hero-install'))
    expect(prompt).toHaveBeenCalledOnce()
    expect(await screen.findByText('در انتظارِ مرورگر…')).toBeInTheDocument()
    expect(screen.queryByText('اپلیکیشن نصب شد')).toBeNull()

    act(() => {
      window.dispatchEvent(new Event('appinstalled'))
    })
    expect(await screen.findByText('اپلیکیشن نصب شد')).toBeInTheDocument()
    expect(screen.getByTestId('hero-install')).toBeDisabled()
  })

  it('انصراف: هیچ ادعای نصب، فقط «نصب انجام نشد»؛ کلیکِ دوباره پنجره‌ی مصرف‌شده را صدا نمی‌زند', async () => {
    const user = userEvent.setup()
    render(<HeroInstallButton />)
    const { prompt } = offer('dismissed')

    await user.click(screen.getByTestId('hero-install'))
    expect(await screen.findByTestId('hero-install-help')).toHaveTextContent('نصب انجام نشد')
    expect(screen.getByTestId('hero-install')).toHaveTextContent('نصب اپلیکیشن')
    expect(screen.getByTestId('hero-install')).toBeEnabled()

    await user.click(screen.getByRole('button', { name: 'متوجه شدم' }))
    await user.click(screen.getByTestId('hero-install'))
    expect(prompt).toHaveBeenCalledOnce()
    expect(await screen.findByTestId('hero-install-help')).toHaveTextContent(
      'نصبِ مستقیم را پیشنهاد نمی‌دهد',
    )
  })

  it('بی رویداد (کرومیوم پیش از پیشنهاد یا مرورگرِ بی نصب): دلیل و راهِ دیگر، نه پنجره‌ی ساختگی', async () => {
    const user = userEvent.setup()
    render(<HeroInstallButton />)

    const button = screen.getByTestId('hero-install')
    expect(button).toHaveAttribute('aria-expanded', 'false')
    await user.click(button)

    expect(await screen.findByTestId('hero-install-help')).toHaveTextContent(
      'نصبِ مستقیم را پیشنهاد نمی‌دهد',
    )
    expect(button).toHaveAttribute('aria-expanded', 'true')
    expect(button).toHaveAttribute('aria-controls', 'hero-install-help')
    expect(screen.queryByText('اپلیکیشن نصب شد')).toBeNull()

    // دوباره زدن، راهنما را می‌بندد
    await user.click(button)
    await waitFor(() => expect(screen.queryByTestId('hero-install-help')).toBeNull())
  })

  it('سافاریِ iOS: راهنمای مشروطِ «Add to Home Screen»', async () => {
    const user = userEvent.setup()
    pretend('Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15', false)
    render(<HeroInstallButton />)

    await user.click(screen.getByTestId('hero-install'))
    const help = await screen.findByTestId('hero-install-help')
    expect(help).toHaveTextContent('اگر هنوز نصبش نکرده‌اید')
    expect(help).toHaveTextContent('Add to Home Screen')
  })

  it('سافاریِ مک: راهنمای «Add to Dock»', async () => {
    const user = userEvent.setup()
    pretend(
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    )
    render(<HeroInstallButton />)

    await user.click(screen.getByTestId('hero-install'))
    expect(await screen.findByTestId('hero-install-help')).toHaveTextContent('Add to Dock')
  })

  it('داخلِ اپِ نصب‌شده (iOS standalone): دکمه‌ای نیست', () => {
    pretend('Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15', true)
    render(<HeroInstallButton />)

    expect(screen.queryByTestId('hero-install')).toBeNull()
  })

  it('«نه، ممنون» ِ کارتِ شناور دکمه‌ی Hero را پنهان نمی‌کند — این‌جا درخواستِ صریحِ کاربر است', async () => {
    const user = userEvent.setup()
    localStorage.setItem('sakena:install-dismissed', '1')
    render(<HeroInstallButton />)
    const { prompt } = offer('accepted')

    await user.click(screen.getByTestId('hero-install'))
    expect(prompt).toHaveBeenCalledOnce()
    localStorage.removeItem('sakena:install-dismissed')
  })

  it('کارت و Hero یک رویداد را دو بار مصرف نمی‌کنند: پس از نصب از Hero، کارت پنجره‌ی مصرف‌شده را صدا نمی‌زند', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    render(
      <>
        <HeroInstallButton />
        <InstallPrompt placement="public" />
      </>,
    )
    const { prompt } = offer('dismissed')
    act(() => {
      vi.advanceTimersByTime(1600)
    })
    vi.useRealTimers()
    const card = await screen.findByTestId('install-prompt')
    expect(card).toBeInTheDocument()

    await userEvent.click(screen.getByTestId('hero-install'))
    // رویداد مصرف شد؛ کارت دیگر دکمه‌ی «نصب» ِ مرده ندارد
    await waitFor(() => expect(screen.queryByTestId('install-prompt')).toBeNull())
    expect(prompt).toHaveBeenCalledOnce()
  })
})
