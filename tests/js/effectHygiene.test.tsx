import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithQuery } from './helpers/renderWithQuery'

/**
 * رفتارِ کامپوننت‌هایی که در R49 از «افکت» به «مشتق» منتقل شدند.
 *
 * ─── چرا این تست‌ها لازم بودند ─────────────────────────────────────────────
 * R49 روی کاغذ «رفعِ ۱۵ هشدارِ لینت» بود، ولی رفعِ واقعیِ
 * `set-state-in-effect` یعنی **بازنویسیِ منطقِ کامپوننت** — و هیچ‌کدام از این
 * مسیرها تستِ رفتاری نداشتند. یعنی می‌شد شمارِ لینت را صفر کرد و هم‌زمان
 * چیزی را بی‌سروصدا شکست.
 *
 * هرکدام از این تست‌ها روی همان نقطه‌ای می‌ایستد که تغییر کرده است، نه روی
 * ظاهرِ کلیِ کامپوننت.
 */

const apiMock = vi.fn()
const toastSuccessMock = vi.fn()

vi.mock('@/shared/lib/api', () => ({
  api: (...args: unknown[]) => apiMock(...args),
  ApiError: class extends Error {},
  setCsrfToken: vi.fn(),
}))

vi.mock('@/shared/lib/alert', () => ({
  toastSuccess: (...args: unknown[]) => toastSuccessMock(...args),
  alertError: vi.fn(),
  confirmAction: vi.fn(),
  toastTopSuccess: vi.fn(),
  toastTopError: vi.fn(),
  toastError: vi.fn(),
}))

const { GalleryLightbox } = await import('@/features/landing/home/components/GalleryLightbox')
const { AuthPage } = await import('@/features/auth/AuthPage')
const { SiteSettingsPage } = await import('@/features/system/SiteSettingsPage')
const { SupportChat } = await import('@/features/landing/home/components/SupportChat')
const { CalculatorPage } = await import('@/features/tools/calculator/CalculatorPage')
const { evaluate, formatResult } = await import('@/shared/lib/calculator')
const { toPersianDigits } = await import('@/shared/lib/format')

/*
 * ⚠️ jsdom روی عناصر `scrollTo` ندارد (فقط روی `window` هست، که
 * `tests/js/setup.ts` بدلش را گذاشته). چتِ پشتیبانی با هر پیامِ تازه
 * گفت‌وگو را پایین می‌کشد، پس بدونِ این بدل، تست با
 * «scrollTo is not a function» می‌افتد — خطایی که ربطی به آنچه سنجیده
 * می‌شود ندارد.
 */
if (!Element.prototype.scrollTo) {
  Element.prototype.scrollTo = vi.fn()
}

beforeEach(() => {
  apiMock.mockReset()
  toastSuccessMock.mockReset()
})

// ───────────────────────────────────────────────────────────────────────────
//  ذره‌بینِ گالری: ریست بدونِ یک فریمِ ناهمخوان
// ───────────────────────────────────────────────────────────────────────────

const galleryItems = [
  { src: '/a.webp', title: 'تصویرِ یک', description: 'یک', tags: ['الف'] },
  { src: '/b.webp', title: 'تصویرِ دو', description: 'دو', tags: ['ب'] },
]

/**
 * ⚠️ در jsdom `getBoundingClientRect` همه‌چیز را صفر برمی‌گرداند.
 *
 * با عرضِ صفر، محاسبه‌ی درصدِ موقعیت به `NaN` می‌رسد. شیءِ ذره‌بین آن‌وقت هنوز
 * ساخته می‌شود و تست سبز می‌ماند — ولی سبزی‌اش تصادفی است. با ابعادِ واقعی،
 * همان مسیری پیموده می‌شود که کاربر می‌پیماید.
 */
function withFrameSize<T>(run: () => T): T {
  /*
   * ⚠️ `vi.spyOn` و نه جایگزینیِ دستیِ متدِ prototype: گرفتنِ ارجاعِ خامِ
   * متد، آن را از شیءِ خودش جدا می‌کند و لینت (به‌درستی) هشدار می‌دهد.
   * spy هم بازگردانیِ مطمئن دارد و هم این مشکل را ندارد.
   */
  const spy = vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({
    left: 0,
    top: 0,
    width: 400,
    height: 500,
    right: 400,
    bottom: 500,
    x: 0,
    y: 0,
    toJSON: () => ({}),
  })

  try {
    return run()
  } finally {
    spy.mockRestore()
  }
}

function moveOver(alt: string) {
  const frame = screen.getByAltText(alt).parentElement

  if (!frame) throw new Error('قابِ تصویر پیدا نشد.')

  fireEvent.mouseMove(frame, { clientX: 200, clientY: 250 })
}

describe('ذره‌بینِ لایت‌باکسِ گالری', () => {
  const HINT = 'نشانگر را روی تصویر ببرید'

  it('با بردنِ نشانگر روی تصویر فعال می‌شود', () => {
    withFrameSize(() => {
      render(
        <GalleryLightbox items={galleryItems} index={0} onClose={vi.fn()} onNavigate={vi.fn()} />,
      )

      expect(screen.getByText(HINT)).toBeInTheDocument()

      moveOver('تصویرِ یک')

      expect(screen.queryByText(HINT)).not.toBeInTheDocument()
    })
  })

  /**
   * ⚠️ همان ناهمخوانی‌ای که بازنویسیِ R49 حذفش کرد.
   *
   * پیش از این ریست با `useEffect(() => setLens(null), [index])` انجام
   * می‌شد. یعنی تصویرِ **بعدی** یک رندرِ کامل با ذره‌بینِ تصویرِ **قبلی** روی
   * صفحه می‌نشست و رندرِ دوم پاکش می‌کرد. حالا شماره‌ی تصویر داخلِ خودِ حالت
   * است، پس ناهمخوانی اصلاً ساخته نمی‌شود.
   */
  it('با عوض شدنِ تصویر، در همان رندر ریست می‌شود', () => {
    withFrameSize(() => {
      const { rerender } = render(
        <GalleryLightbox items={galleryItems} index={0} onClose={vi.fn()} onNavigate={vi.fn()} />,
      )

      moveOver('تصویرِ یک')
      expect(screen.queryByText(HINT)).not.toBeInTheDocument()

      rerender(
        <GalleryLightbox items={galleryItems} index={1} onClose={vi.fn()} onNavigate={vi.fn()} />,
      )

      expect(screen.getByText(HINT)).toBeInTheDocument()
    })
  })
})

// ───────────────────────────────────────────────────────────────────────────
//  زبانه‌ی ورود/ثبت‌نام: آدرس تنها منبعِ حقیقت
// ───────────────────────────────────────────────────────────────────────────

describe('زبانه‌ی صفحه‌ی ورود', () => {
  function renderAuth(path: string) {
    return render(
      <MemoryRouter initialEntries={[path]}>
        <AuthPage />
      </MemoryRouter>,
    )
  }

  /*
   * ⚠️ صفحه دو نسخه‌ی هم‌زمان از فرم دارد (دسکتاپ و موبایل)، پس هر عنوان
   * **دو بار** در DOM هست. شمارش می‌شود نه `getByRole` — و مهم‌تر، نبودنِ
   * عنوانِ آن‌یکی هم ادعا می‌شود، وگرنه تست فقط «چیزی رندر شد» را می‌گفت.
   */
  function headings(name: string) {
    return screen.queryAllByRole('heading', { name }).length
  }

  const LOGIN = 'ورود به پنل مدیریت'
  const REGISTER = 'ساخت حساب مجتمع جدید'

  it('زبانه‌ی آغازین را از آدرس می‌گیرد', () => {
    renderAuth('/auth?tab=register')

    expect(headings(REGISTER)).toBeGreaterThan(0)
    expect(headings(LOGIN)).toBe(0)
  })

  it('بدونِ پارامتر، ورود را نشان می‌دهد', () => {
    renderAuth('/auth')

    expect(headings(LOGIN)).toBeGreaterThan(0)
    expect(headings(REGISTER)).toBe(0)
  })

  /**
   * ⚠️ پیش از R49 دو منبعِ حقیقت وجود داشت: یک `useState` و افکتی که با هر
   * تغییرِ آدرس رویش می‌نوشت. حالا کلیک آدرس را عوض می‌کند و زبانه از آدرس
   * خوانده می‌شود — پس این تست هر دو جهت را با هم می‌سنجد.
   */
  it('کلیک روی زبانه، فرم را عوض می‌کند', async () => {
    const user = userEvent.setup()
    renderAuth('/auth')

    expect(headings(LOGIN)).toBeGreaterThan(0)

    await user.click(screen.getAllByRole('button', { name: 'ثبت‌نام' })[0])

    await waitFor(() => {
      expect(headings(REGISTER)).toBeGreaterThan(0)
    })
    expect(headings(LOGIN)).toBe(0)
  })
})

// ───────────────────────────────────────────────────────────────────────────
//  تنظیماتِ فوتر: دست‌نوشته روی داده‌ی سرور
// ───────────────────────────────────────────────────────────────────────────

const siteSettings = {
  footer: {
    contact: {
      title: 'ارتباط با ما',
      address: 'تهران',
      phone: '۰۲۱',
      email: 'a@b.c',
      mapEmbedUrl: '',
      showAddress: true,
      showPhone: true,
      showEmail: true,
      showMap: false,
    },
    social: [{ id: 'instagram', label: 'اینستاگرام', href: '', enabled: false }],
  },
}

describe('تنظیماتِ فوترِ سایت', () => {
  it('مقدارهای سرور را نشان می‌دهد', async () => {
    apiMock.mockResolvedValue(siteSettings)

    renderWithQuery(<SiteSettingsPage />)

    expect(await screen.findByDisplayValue('ارتباط با ما')).toBeInTheDocument()
  })

  /**
   * ⚠️ نقصی که بازنویسیِ R49 هم‌زمان رفعش کرد.
   *
   * پیش از این داده‌ی سرور با یک افکت در حالتِ محلی کپی می‌شد. یعنی هر بار
   * که react-query دوباره می‌گرفت — با برگشتنِ تمرکز به پنجره یا وصل‌شدنِ
   * دوباره‌ی شبکه — ویرایشِ نیمه‌کاره‌ی کاربر **بی‌صدا پاک می‌شد**.
   *
   * حالا فقط دست‌نوشته حالت است و داده‌ی تازه‌ی سرور زیرش می‌نشیند.
   */
  it('گرفتنِ دوباره‌ی داده، ویرایشِ نیمه‌کاره را پاک نمی‌کند', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue(siteSettings)

    const { queryClient } = renderWithQuery(<SiteSettingsPage />)

    const title = await screen.findByDisplayValue('ارتباط با ما')
    await user.clear(title)
    await user.type(title, 'تماس')

    expect(screen.getByDisplayValue('تماس')).toBeInTheDocument()

    await queryClient.refetchQueries()

    await waitFor(() => {
      expect(screen.getByDisplayValue('تماس')).toBeInTheDocument()
    })
  })

  /** و پس از ذخیره، دوباره سرور حرفِ آخر را می‌زند. */
  it('پس از ذخیره، مقدارِ سرور جای دست‌نوشته را می‌گیرد', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue(siteSettings)

    renderWithQuery(<SiteSettingsPage />)

    const title = await screen.findByDisplayValue('ارتباط با ما')
    await user.clear(title)
    await user.type(title, 'تماس')

    await user.click(screen.getByRole('button', { name: 'ذخیره تنظیمات' }))

    await waitFor(() => {
      expect(toastSuccessMock).toHaveBeenCalled()
    })

    await waitFor(() => {
      expect(screen.getByDisplayValue('ارتباط با ما')).toBeInTheDocument()
    })
  })
})

// ───────────────────────────────────────────────────────────────────────────
//  چتِ پشتیبانی: آماده‌سازی در رویداد، نه در افکت
// ───────────────────────────────────────────────────────────────────────────

const WELCOME_START = 'سلام! 👋 من دستیار پشتیبانی ساکنا هستم.'

describe('چتِ پشتیبانیِ صفحه‌ی فرود', () => {
  /**
   * ⚠️ پیش از R49 پیامِ خوش‌آمد را افکتی با وابستگیِ
   * `[open, messages.length]` می‌گذاشت. یعنی باز شدنِ چت یک رندر با پنجره‌ی
   * **خالی** می‌داد و رندرِ دوم پیام را می‌آورد. حالا در همان رویدادِ کلیک
   * انجام می‌شود.
   */
  it('با اولین باز شدن، پیامِ خوش‌آمد و سوال‌های پیشنهادی می‌آیند', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ starters: ['شارژ چطور حساب می‌شود؟'] })

    render(<SupportChat />)

    await user.click(screen.getByRole('button', { name: 'گفت‌وگو با پشتیبانی' }))

    expect(screen.getByText(WELCOME_START, { exact: false })).toBeInTheDocument()
    expect(await screen.findByText('شارژ چطور حساب می‌شود؟')).toBeInTheDocument()
    expect(apiMock).toHaveBeenCalledWith('/support/starters')
  })

  /** و بستن و باز کردنِ دوباره نباید خوش‌آمد را تکرار کند. */
  it('بازِ دوباره، پیامِ خوش‌آمد را تکرار نمی‌کند', async () => {
    const user = userEvent.setup()
    apiMock.mockResolvedValue({ starters: [] })

    render(<SupportChat />)

    await user.click(screen.getByRole('button', { name: 'گفت‌وگو با پشتیبانی' }))
    await user.click(screen.getByRole('button', { name: 'بستن پشتیبانی' }))
    await user.click(screen.getByRole('button', { name: 'گفت‌وگو با پشتیبانی' }))

    expect(screen.getAllByText(WELCOME_START, { exact: false })).toHaveLength(1)
    expect(apiMock).toHaveBeenCalledTimes(1)
  })
})

// ───────────────────────────────────────────────────────────────────────────
//  ماشین‌حساب: تغییرِ درجه/رادیان با مقدارِ تازه حساب می‌کند
// ───────────────────────────────────────────────────────────────────────────

describe('ماشین‌حسابِ درجه و رادیان', () => {
  /**
   * ⚠️ پیش از R49 این کار در افکتی با `[angleMode]` و یک
   * `eslint-disable-next-line react-hooks/exhaustive-deps` انجام می‌شد. حالا
   * در همان رویدادِ کلیک است و از `next` استفاده می‌کند نه از `angleMode`ِ
   * هنوز-عوض-نشده.
   *
   * مقدارِ انتظار از خودِ `evaluate` گرفته می‌شود تا تست به شکلِ نمایشِ عدد
   * گره نخورد — چیزی که به این تغییر ربطی ندارد.
   */
  it('با تغییرِ حالتِ زاویه، نتیجه دوباره حساب می‌شود', async () => {
    const user = userEvent.setup()

    renderWithQuery(<CalculatorPage />)

    const input = screen.getByLabelText('عبارت ریاضی')
    await user.type(input, 'sin(30){Enter}')

    /* نمایشِ نتیجه به شکلِ «= ۰٫۵» است: علامت مساوی و ارقامِ فارسی. */
    const shown = (mode: 'deg' | 'rad') =>
      `= ${toPersianDigits(formatResult(evaluate('sin(30)', mode)))}`

    const inDegrees = shown('deg')
    const inRadians = shown('rad')

    expect(inDegrees).not.toBe(inRadians)

    /*
     * ⚠️ `getAllByText` و نه `getByText`: نتیجه هم در نمایشگر می‌آید و هم در
     * تاریخچه. ادعای «مقدارِ درجه دیگر نیست» هم غلط می‌بود — تاریخچه باید
     * همان چیزی را نگه دارد که واقعاً حساب شده بود.
     *
     * ادعای واقعی این است: مقدارِ رادیانی **پیش از** کلیک هیچ‌جا نیست و
     * **پس از** کلیک هست. اگر تابع مثلِ گذشته `angleMode`ِ هنوز-عوض-نشده را
     * می‌خواند، این مقدار هرگز ساخته نمی‌شد.
     */
    await waitFor(() => {
      expect(screen.getAllByText(inDegrees).length).toBeGreaterThan(0)
    })
    expect(screen.queryByText(inRadians)).toBeNull()

    await user.click(screen.getByRole('button', { name: 'رادیان' }))

    await waitFor(() => {
      expect(screen.getAllByText(inRadians).length).toBeGreaterThan(0)
    })
  })
})
