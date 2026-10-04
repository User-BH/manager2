import { readFileSync, existsSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { LazyVisible } from '@/shared/ui/LazyVisible'
import { scrollToSection } from '@/shared/lib/scroll'
import { HomeFooter } from '@/features/landing/home/components/HomeFooter'
import { GallerySwiperSection } from '@/features/landing/home/components/GallerySwiperSection'
import { InstallPrompt } from '@/shared/pwa/InstallPrompt'
import { features } from '@/features/landing/content/landingContent'
import { PANEL_TOUR } from '@/features/landing/content/sections'
import { buildingPhotos, panelTourItems } from '@/shared/constants/images'

/**
 * صفحه‌ی اصلی (مرحلهٔ ۷ · فاز ۵).
 *
 * باگِ هدرِ ‎۶۰۰–۷۶۸ و منوی بخش‌ها در `HomeNavbar.test.tsx` است؛ اینجا لنگرِ
 * بخش‌های تنبل، اسکرول به مقصدِ جابه‌جاشونده، فوتر، «تور پنل» و پیشنهادِ نصب.
 */

vi.mock('@/shared/lib/mpaNav', () => ({
  Link: ({ to, children }: { to: string; children?: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
  useNavigate: () => vi.fn(),
}))

const siteContent = vi.hoisted(() => ({
  contact: { title: 'ارتباط با ما', phone: '۰۲۱-۸۸۷۷۶۶۵۵' },
  // برچسبِ ادمین عمداً نامِ شبکه نیست
  social: [{ id: 'instagram', label: 'پیجِ ما', href: 'https://instagram.com/x' }],
}))

vi.mock('@/shared/hooks', async (original) => ({
  ...(await original<typeof import('@/shared/hooks')>()),
  useSiteContent: () => siteContent,
}))

/** بارگذاری‌ای که هرگز تمام نمی‌شود — بخش در حالِ جای‌گیر می‌ماند. */
const never = () => new Promise<{ default: React.ComponentType }>(() => {})

describe('لنگرِ بخش‌های تنبل', () => {
  /*
   * 🔴 پیش از این «گالری» و «نظرات» در هدر و فوتر کاری نمی‌کردند تا وقتی
   * بخش بار نشده بود: شناسه روی خودِ بخش بود و `getElementById` تهی برمی‌گشت.
   */
  it('شناسه از رندرِ اول، پیش از بارگذاریِ بخش، روی قاب است', () => {
    render(<LazyVisible id="panel-tour" load={never} height={600} />)

    expect(document.getElementById('panel-tour')).not.toBeNull()
  })

  it('ارتفاعِ ریسپانسیوِ جای‌گیر با کلاس است، نه عددِ ثابت', () => {
    render(<LazyVisible id="x" load={never} height={200} heightClass="h-[200px] sm:h-[248px]" />)

    const placeholder = document.getElementById('x')!.firstElementChild as HTMLElement
    expect(placeholder.className).toContain('sm:h-[248px]')
    expect(placeholder.style.height).toBe('')
  })
})

describe('اسکرول به بخش', () => {
  let frames: FrameRequestCallback[] = []
  let now = 0
  let top = 1000

  beforeEach(() => {
    frames = []
    now = 0
    top = 1000
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb) => frames.push(cb))
    vi.spyOn(performance, 'now').mockImplementation(() => now)
    const target = document.createElement('section')
    target.id = 'moving'
    target.getBoundingClientRect = () => ({ top }) as DOMRect
    document.body.appendChild(target)
    Object.defineProperty(document.documentElement, 'scrollHeight', {
      configurable: true,
      value: 10_000,
    })
  })

  afterEach(() => {
    document.getElementById('moving')?.remove()
    vi.restoreAllMocks()
  })

  /*
   * بخشی بالاتر از مقصد وسطِ حرکت بار می‌شود و مقصد پایین‌تر می‌رود؛ حرکت
   * باید روی **جای تازه** بایستد، نه جایی که لحظه‌ی کلیک حساب شده بود.
   */
  it('مقصدی که وسطِ حرکت جابه‌جا شود، درست همان‌جا می‌ایستد', () => {
    const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    scrollToSection('moving')
    now = 300
    frames.shift()!(now)

    top = 1150 // بخشِ بالایی ‎۱۵۰px بلندتر از جای‌گیرش درآمد
    now = 5000
    frames.shift()!(now)

    expect(scrollTo).toHaveBeenLastCalledWith({ top: 1150 - 72, behavior: 'instant' })
    expect(frames).toHaveLength(0)
  })
})

describe('فوتر', () => {
  beforeEach(() => {
    const seal = document.createElement('div')
    seal.id = 'enamad-seal'
    seal.style.display = 'none'
    document.body.appendChild(seal)
  })

  afterEach(() => document.getElementById('enamad-seal')?.remove())

  it('زیرِ ‎۱۰۲۴ تک‌ستونی نیست: ۲ ← ۳ ← ۴ ستون', () => {
    const { container } = render(<HomeFooter />)
    const grid = container.querySelector('footer .grid')!

    expect(grid.className).toMatch(/(^|\s)grid-cols-2(\s|$)/)
    expect(grid.className).toContain('md:grid-cols-3')
    expect(grid.className).toContain('lg:grid-cols-[')
  })

  /*
   * ⚠️ کدِ اینماد در Blade است و دست نمی‌خورد؛ فقط گره جابه‌جا می‌شود. اینجا
   * سنجیده می‌شود که به ستونِ تماس رفته (همان فضای خالیِ زیرِ تلفن).
   */
  it('نشانِ اینماد در ستونِ تماس می‌نشیند، کنارِ تلفن', () => {
    render(<HomeFooter />)

    const seal = document.getElementById('enamad-seal')!
    const phone = screen.getByRole('link', { name: '۰۲۱-۸۸۷۷۶۶۵۵' })

    expect(seal.style.display).toBe('')
    expect(seal.closest('.md\\:col-span-1')).toBe(phone.closest('.md\\:col-span-1'))
  })

  it('دو جمله‌ی پایین کنارِ هم و در دو سو', () => {
    render(<HomeFooter />)

    const row = screen.getByText(/تمامی حقوق محفوظ است/).parentElement!
    expect(row.className).toContain('sm:flex-row')
    expect(row.className).toContain('sm:justify-between')
    expect(within(row).getByText(/ساخته‌شده با/)).toBeInTheDocument()
  })

  it('تولتیپِ آیکون نامِ دقیقِ شبکه است، نه برچسبِ ادمین', () => {
    render(<HomeFooter />)

    const link = screen.getByRole('link', { name: 'پیجِ ما' })
    const tip = link.querySelector('.social-tooltip')!

    expect(tip.textContent).toBe('اینستاگرام')
    // به داخلِ صفحه باز می‌شود، وسط‌چین نیست — روی موبایل از لبه بیرون نزند
    expect(tip.className).toContain('right-0')
    expect(tip.className).not.toContain('left-1/2')
    expect(tip.getAttribute('aria-hidden')).toBe('true')
    // با کیبورد هم دیده می‌شود، نه فقط با هاور
    expect(tip.className).toContain('group-focus-visible:opacity-100')
  })

  it('پیوندِ «تور پنل» همان نامِ عنوانِ بخش است', () => {
    render(<HomeFooter />)

    expect(screen.getByRole('button', { name: PANEL_TOUR.name })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'گالری' })).toBeNull()
  })
})

/** ابعادِ یک فایلِ WebP از سرصفحه‌اش (VP8، VP8L یا VP8X). */
function webpSize(path: string): { width: number; height: number } {
  const b = readFileSync(path)
  expect(b.toString('ascii', 0, 4)).toBe('RIFF')
  expect(b.toString('ascii', 8, 12)).toBe('WEBP')
  const chunk = b.toString('ascii', 12, 16)
  if (chunk === 'VP8 ') {
    return { width: b.readUInt16LE(26) & 0x3fff, height: b.readUInt16LE(28) & 0x3fff }
  }
  if (chunk === 'VP8L') {
    const bits = b.readUInt32LE(21)
    return { width: (bits & 0x3fff) + 1, height: ((bits >> 14) & 0x3fff) + 1 }
  }
  return { width: b.readUIntLE(24, 3) + 1, height: b.readUIntLE(27, 3) + 1 }
}

describe('عکسِ کارت‌های ویژگی', () => {
  /*
   * خواسته‌ی ممیزی: «تمام Feature Cardها باید دارای تصویر مناسب باشند». تا
   * فاز ۵ چهار کارت فقط آیکونِ برند داشتند.
   */
  it('هر هشت کارت عکس دارند و هیچ عکسی تکرار نشده', () => {
    const images = features.map((f) => f.image)

    expect(images.every(Boolean)).toBe(true)
    expect(new Set(images).size).toBe(features.length)
  })

  it('عکس‌های تازه ‎۹۰۰×۵۰۶ و سبک‌اند', () => {
    for (const name of ['wallet', 'polls', 'reminders', 'unit']) {
      const file = `resources/images/feature-${name}.webp`
      expect(webpSize(file)).toEqual({ width: 900, height: 506 })
      expect(readFileSync(file).length).toBeLessThan(60 * 1024)
    }
  })
})

describe('تور پنل (جای «گالری»)', () => {
  it('هر قاب تصویرِ واقعیِ پنل است، ‎۸۰۰×۱۰۰۰، و تعدادشان مثلِ قبل ۸ است', () => {
    expect(panelTourItems).toHaveLength(8)

    for (const item of panelTourItems) {
      expect(item.src).toMatch(/^\/images\/tour\/[a-z-]+\.webp$/)
      const file = `public${item.src}`
      expect(existsSync(file), file).toBe(true)
      expect(webpSize(file)).toEqual({ width: 800, height: 1000 })
    }
  })

  it('عنوانِ بخش همان نامِ مشترکِ هدر و فوتر است و عکسِ ساختمان نشان نمی‌دهد', () => {
    render(<GallerySwiperSection />)

    expect(screen.getByRole('heading', { level: 2 })).toHaveTextContent(PANEL_TOUR.name)
    const sources = screen.getAllByRole('img').map((img) => img.getAttribute('src'))
    expect(sources.every((src) => src?.startsWith('/images/tour/'))).toBe(true)
  })

  /*
   * 🔴 متن‌های صفحه‌ی اصلی پنج قابلیتِ ساخته‌نشده را وعده می‌دادند (با کد
   * سنجیده شد): کنترلِ تردد، یادآورِ خودکارِ سرویس، قرارداد و سررسیدِ سرویس،
   * بودجه‌ی سالانه، و پارکینگ در محاسبه‌ی شارژ.
   */
  it('هیچ متنی قابلیتِ ساخته‌نشده را وعده نمی‌دهد', () => {
    const texts = [
      ...features.map((f) => f.description),
      ...panelTourItems.flatMap((i) => [i.description, ...i.tags]),
      ...buildingPhotos.flatMap((i) => [i.description, ...i.tags]),
      // متنِ رندرشده‌ی زیرِ عنوان — نه کدِ منبع، که توضیحِ «چرا» را دارد
      (() => {
        const { unmount } = render(<GallerySwiperSection />)
        const text = screen.getByRole('heading', { level: 2 }).nextElementSibling!.textContent ?? ''
        unmount()
        return text
      })(),
    ]

    for (const text of texts) {
      expect(text).not.toMatch(/کنترل ?تردد|قرارداد|بودجه|یادآورهای خودکار|سرویس دوره‌ای/)
      // پارکینگ در پرونده‌ی واحد هست، ولی هیچ قاعده‌ی شارژی آن را نمی‌شمارد
      if (/پارکینگ/.test(text)) expect(text).not.toMatch(/شارژ|سهم هر واحد/)
    }
  })
})

describe('پیشنهادِ نصب', () => {
  const ua = Object.getOwnPropertyDescriptor(window.navigator, 'userAgent')

  function fireInstallEvent() {
    const prompt = vi.fn(async () => {})
    const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
      prompt,
      userChoice: Promise.resolve({ outcome: 'accepted' as const }),
    })
    act(() => {
      window.dispatchEvent(event)
    })
    return prompt
  }

  function pretendIos(standalone: boolean | undefined) {
    Object.defineProperty(window.navigator, 'userAgent', {
      configurable: true,
      value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15',
    })
    Object.defineProperty(window.navigator, 'standalone', { configurable: true, value: standalone })
  }

  beforeEach(() => vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] }))

  afterEach(() => {
    vi.useRealTimers()
    if (ua) Object.defineProperty(window.navigator, 'userAgent', ua)
    else delete (window.navigator as { userAgent?: string }).userAgent
    delete (window.navigator as { standalone?: boolean }).standalone
  })

  it('بی‌رویدادِ واقعیِ مرورگر هیچ کارتی نمی‌آید', () => {
    render(<InstallPrompt />)
    act(() => {
      vi.advanceTimersByTime(10_000)
    })

    expect(screen.queryByTestId('install-prompt')).toBeNull()
  })

  it('با رویدادِ مرورگر، پس از مکثی کوتاه می‌آید و «نصب» همان رویداد را صدا می‌زند', async () => {
    render(<InstallPrompt />)
    const prompt = fireInstallEvent()

    expect(screen.queryByTestId('install-prompt')).toBeNull()
    act(() => {
      vi.advanceTimersByTime(1600)
    })

    vi.useRealTimers()
    await screen.findByTestId('install-prompt')
    await userEvent.click(screen.getByRole('button', { name: 'نصب' }))
    expect(prompt).toHaveBeenCalledOnce()
  })

  it('بستن: به‌خاطر سپرده می‌شود و کارت از DOM برداشته می‌شود', async () => {
    render(<InstallPrompt />)
    fireInstallEvent()
    act(() => {
      vi.advanceTimersByTime(1600)
    })
    vi.useRealTimers()

    await userEvent.click(await screen.findByRole('button', { name: 'بستنِ پیشنهادِ نصب' }))

    expect(localStorage.getItem('sakena:install-dismissed')).toBe('1')
    await waitFor(() => expect(screen.queryByTestId('install-prompt')).toBeNull())
  })

  it('پس از بستن، رویدادِ بعدی هم آن را برنمی‌گرداند (با هر بارگذاری تحمیل نمی‌شود)', () => {
    localStorage.setItem('sakena:install-dismissed', '1')
    render(<InstallPrompt />)
    fireInstallEvent()
    act(() => {
      vi.advanceTimersByTime(5000)
    })

    expect(screen.queryByTestId('install-prompt')).toBeNull()
  })

  it('در صفحه‌ی عمومی کنارِ ستونِ دکمه‌های شناور می‌نشیند و زیرِ پنلِ گفت‌وگو می‌ماند', async () => {
    render(<InstallPrompt placement="public" />)
    fireInstallEvent()
    act(() => {
      vi.advanceTimersByTime(1600)
    })
    vi.useRealTimers()

    const card = await screen.findByTestId('install-prompt')
    expect(card.className).toContain('right-[5.5rem]')
    expect(card.className).toContain('z-40')
  })

  it('iOS (سافاری): راهنمای «افزودن به صفحه‌ی اصلی»، نه دکمه‌ی نصب', async () => {
    pretendIos(false)
    render(<InstallPrompt />)
    act(() => {
      vi.advanceTimersByTime(4100)
    })
    vi.useRealTimers()

    const card = await screen.findByTestId('install-prompt')
    expect(card).toHaveTextContent('Add to Home Screen')
    expect(within(card).queryByRole('button', { name: 'نصب' })).toBeNull()
  })

  it('iOS ِ نصب‌شده یا مرورگرِ درون‌برنامه‌ای: هیچ راهنمایی', () => {
    pretendIos(true)
    const { unmount } = render(<InstallPrompt />)
    act(() => {
      vi.advanceTimersByTime(5000)
    })
    expect(screen.queryByTestId('install-prompt')).toBeNull()
    unmount()

    pretendIos(undefined)
    render(<InstallPrompt />)
    act(() => {
      vi.advanceTimersByTime(5000)
    })
    expect(screen.queryByTestId('install-prompt')).toBeNull()
  })

  /* ── مرحلهٔ ۸ · فاز ۲: تکرار، متنِ مشروط، نصب در همین صفحه ─────────────── */

  it('بی‌بستن هم با بارگذاریِ دوباره برنمی‌گردد (هر بارگذاری مزاحم نمی‌شود)', () => {
    const first = render(<InstallPrompt />)
    fireInstallEvent()
    act(() => {
      vi.advanceTimersByTime(1600)
    })
    expect(screen.getByTestId('install-prompt')).toBeInTheDocument()
    first.unmount()

    // «بارگذاریِ دوباره»: همان مرورگر، همان رویداد، کارت نه
    render(<InstallPrompt />)
    fireInstallEvent()
    act(() => {
      vi.advanceTimersByTime(5000)
    })
    expect(screen.queryByTestId('install-prompt')).toBeNull()
  })

  it('نصب در همین صفحه تمام شد (appinstalled): کارت می‌رود و دیگر برنمی‌گردد', async () => {
    render(<InstallPrompt />)
    fireInstallEvent()
    act(() => {
      vi.advanceTimersByTime(1600)
    })
    expect(screen.getByTestId('install-prompt')).toBeInTheDocument()

    act(() => {
      window.dispatchEvent(new Event('appinstalled'))
    })
    vi.useRealTimers()
    await waitFor(() => expect(screen.queryByTestId('install-prompt')).toBeNull())
    expect(JSON.parse(localStorage.getItem('sakena:install-state') ?? '{}')).toMatchObject({
      installedSeen: true,
    })
  })

  it('رویدادِ مرورگر یعنی «نصب نیست»: نشانه‌ی کهنه‌ی «نصب‌شده» پاک می‌شود (اپ حذف شده بود)', () => {
    localStorage.setItem('sakena:install-state', JSON.stringify({ installedSeen: true }))
    render(<InstallPrompt />)
    fireInstallEvent()

    expect(JSON.parse(localStorage.getItem('sakena:install-state') ?? '{}')).not.toHaveProperty(
      'installedSeen',
    )
  })

  it('راهنما (iOS) مشروط است: «اگر هنوز نصب نکرده‌اید» — سافاری نمی‌تواند بداند', async () => {
    pretendIos(false)
    render(<InstallPrompt />)
    act(() => {
      vi.advanceTimersByTime(4100)
    })
    vi.useRealTimers()

    const card = await screen.findByTestId('install-prompt')
    expect(card).toHaveTextContent('اگر هنوز نصبش نکرده‌اید')
    expect(within(card).getByRole('button', { name: 'متوجه شدم' })).toBeInTheDocument()
  })

  it('سافاریِ مک: راهنمای «Add to Dock»، بی‌دکمه‌ی ساختگیِ نصب', async () => {
    Object.defineProperty(window.navigator, 'userAgent', {
      configurable: true,
      value:
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    })
    render(<InstallPrompt />)
    act(() => {
      vi.advanceTimersByTime(4100)
    })
    vi.useRealTimers()

    const card = await screen.findByTestId('install-prompt')
    expect(card).toHaveTextContent('Add to Dock')
    expect(card).toHaveTextContent('اگر هنوز نصبش نکرده‌اید')
    expect(within(card).queryByRole('button', { name: 'نصب' })).toBeNull()
  })
})
