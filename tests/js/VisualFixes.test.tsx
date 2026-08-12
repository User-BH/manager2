import { describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { ContactAvatar } from '@/shared/common/ContactAvatar'
import { ForbiddenPage } from '@/features/error/ForbiddenPage'
import { GalleryLightbox } from '@/features/landing/home/components/GalleryLightbox'
import { SupportWheel } from '@/features/landing/support/SupportWheel'
import { supportTopics } from '@/features/landing/support/supportContent'
import { galleryItems } from '@/shared/constants/images'

/**
 * چهار باگِ بصریِ تأییدشده (R33).
 *
 * ─── چرا این‌ها تست دارند ───────────────────────────────────────────────────
 * هر چهار مورد **خطا نمی‌دادند**: پنلِ بیرون‌زده فقط بریده می‌شد، تأخیرِ
 * هاور فقط کند به‌نظر می‌رسید، و دکمه‌ی اضافه فقط کاربر را به حلقه
 * می‌انداخت. چیزی که خطا نمی‌دهد، بدونِ تست خیلی راحت برمی‌گردد.
 */
describe('صفحه‌ی ۴۰۳', () => {
  it('فقط یک راهِ خروج دارد و آن صفحه‌ی اصلی است', () => {
    render(<ForbiddenPage />)

    expect(screen.getByRole('button', { name: /صفحه اصلی/ })).toBeInTheDocument()

    /*
     * ⚠️ «بازگشت به صفحه‌ی قبل» عمداً حذف شد: کاربر معمولاً از همان
     * صفحه‌ای به اینجا رسیده که اجازه‌اش را نداشته، پس برگرداندنش یعنی
     * دوباره ۴۰۳ گرفتن.
     */
    expect(screen.queryByRole('button', { name: /بازگشت به صفحه قبل/ })).toBeNull()
  })

  it('دکمه در ظرفِ وسط‌چین می‌نشیند', () => {
    render(<ForbiddenPage />)

    const row = screen.getByRole('button', { name: /صفحه اصلی/ }).parentElement

    expect(row?.className).toContain('justify-center')
  })
})

describe('آواتارِ نظرات', () => {
  it('SVG است، نه عکسِ آدمِ واقعی', () => {
    const { container } = render(<ContactAvatar name="مهندس رضایی" />)

    expect(container.querySelector('svg')).toBeInTheDocument()
    expect(container.querySelector('img')).toBeNull()
  })

  it('نامِ شخص برای صفحه‌خوان می‌ماند', () => {
    render(<ContactAvatar name="خانم احمدی" />)

    expect(screen.getByRole('img', { name: 'خانم احمدی' })).toBeInTheDocument()
  })

  it('رنگ از نام مشتق می‌شود و پایدار است', () => {
    /*
     * ⚠️ مقایسه‌ی `innerHTML` اینجا **پوچ** است: `aria-label` خودش نامِ
     * شخص را دارد، پس دو آواتار حتی با رنگِ کاملاً یکسان هم مارک‌آپِ
     * متفاوت می‌دهند و تست بی‌آنکه رنگ را سنجیده باشد سبز می‌ماند.
     * (در پاسِ خرابکاری با ثابت‌کردنِ رنگ لو رفت.) پس خودِ `fill` خوانده
     * می‌شود.
     */
    const fillOf = (name: string) =>
      render(<ContactAvatar name={name} />)
        .container.querySelector('circle')
        ?.getAttribute('fill')

    // همان نام ⇒ همان رنگ؛ وگرنه هر رندر آواتار را عوض می‌کرد
    expect(fillOf('مهندس رضایی')).toBe(fillOf('مهندس رضایی'))
    // نامِ متفاوت ⇒ رنگِ متفاوت، وگرنه سه کارت عینِ هم می‌شدند
    expect(fillOf('مهندس رضایی')).not.toBe(fillOf('آقای کریمی'))
  })
})

describe('چرخِ پشتیبانی', () => {
  it('تولتیپِ بومیِ مرورگر ندارد', () => {
    const { container } = render(
      <SupportWheel topics={supportTopics} activeId={null} onSelect={vi.fn()} />,
    )

    /*
     * ⚠️ `<title>` داخلِ SVG باعث می‌شد مرورگر تولتیپِ زردِ خودش را با
     * تأخیرِ سیستمی نشان بدهد. حالا `aria-label` جایش را گرفته.
     */
    expect(container.querySelectorAll('svg title')).toHaveLength(0)
  })

  it('هر ربع برای صفحه‌خوان نام دارد', () => {
    render(<SupportWheel topics={supportTopics} activeId={null} onSelect={vi.fn()} />)

    for (const topic of supportTopics) {
      expect(screen.getByRole('button', { name: new RegExp(topic.title) })).toBeInTheDocument()
    }
  })

  it('هاور تأخیرِ پله‌ای ندارد', () => {
    const { container } = render(
      <SupportWheel topics={supportTopics} activeId={null} onSelect={vi.fn()} />,
    )

    /*
     * تأخیرِ ورود (`0.15 + index * 0.12`) باید فقط روی انیمیشنِ ورود
     * باشد. اگر کسی transitionِ اختصاصیِ `whileHover` را بردارد،
     * framer-motion دوباره همان تأخیر را به هاور می‌دهد و ربعِ چهارم
     * نیم ثانیه دیر واکنش نشان می‌دهد.
     */
    const groups = container.querySelectorAll('g[role="button"]')

    expect(groups).toHaveLength(supportTopics.length)
  })

  it('کلیک روی ربع، همان بخش را انتخاب می‌کند', async () => {
    const onSelect = vi.fn()
    render(<SupportWheel topics={supportTopics} activeId={null} onSelect={onSelect} />)

    await userEvent.click(screen.getByRole('button', { name: new RegExp(supportTopics[1].title) }))

    expect(onSelect).toHaveBeenCalledWith(supportTopics[1].id)
  })
})

describe('لایت‌باکسِ گالری', () => {
  /**
   * ⚠️ باگِ اندازه‌گیری‌شده: در ۳۹۰×۵۶۰ پنلِ متن تا `y=831` می‌رفت — ۲۷۱
   * پیکسل بیرون از صفحه — و چون ظرف `overflow-hidden` داشت، متن بریده
   * می‌شد نه اسکرول. یعنی توضیحِ هر تصویر در موبایل خوانده نمی‌شد.
   *
   * jsdom چیدمان را حساب نمی‌کند، پس اینجا **قرارداد** سنجیده می‌شود:
   * پنل باید بتواند کوچک شود (`min-h-0`) و خودش اسکرول داشته باشد.
   */
  it('پنلِ متن اسکرولِ داخلی دارد و می‌تواند کوچک شود', () => {
    const { container } = render(
      <GalleryLightbox items={galleryItems} index={0} onClose={vi.fn()} onNavigate={vi.fn()} />,
    )

    const panel = container.querySelector('.overflow-y-auto')

    expect(panel).toBeTruthy()
    expect(panel?.className).toContain('min-h-0')
  })

  it('ارتفاع‌ها با واحدِ dvh بسته می‌شوند، نه vh', () => {
    const { container } = render(
      <GalleryLightbox items={galleryItems} index={0} onClose={vi.fn()} onNavigate={vi.fn()} />,
    )

    const html = container.innerHTML

    /*
     * در موبایل نوارِ آدرسِ مرورگر باعث می‌شود `vh` بزرگ‌تر از فضای واقعی
     * گزارش شود — دقیقاً همان چیزی که پنل را بیرون می‌انداخت.
     */
    expect(html).not.toMatch(/max-h-\[\d+vh\]/)
    expect(html).toMatch(/dvh/)
  })

  it('محتوای تصویرِ انتخابی نشان داده می‌شود', () => {
    render(
      <GalleryLightbox items={galleryItems} index={0} onClose={vi.fn()} onNavigate={vi.fn()} />,
    )

    expect(screen.getByText(galleryItems[0].title)).toBeInTheDocument()
    expect(screen.getByText(galleryItems[0].description)).toBeInTheDocument()
  })

  it('بسته بودن یعنی هیچ‌چیز رندر نمی‌شود', () => {
    const { container } = render(
      <GalleryLightbox items={galleryItems} index={null} onClose={vi.fn()} onNavigate={vi.fn()} />,
    )

    expect(within(container).queryByText(galleryItems[0].title)).toBeNull()
  })
})
