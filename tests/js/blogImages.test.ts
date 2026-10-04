import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { watchBlogImages } from '@/features/landing/blog/blogImages'

/**
 * تصویرِ بلاگِ بار‌نشده (مرحلهٔ ۸ · فاز ۳ · بندِ ۱۶): متنِ همان تصویر به‌جایش،
 * و **گزارش** — تا جایگزین ریشه را پنهان نکند.
 */

let main: HTMLElement
let stop: () => void

function image(attrs: Record<string, string>, state?: { complete: boolean; naturalWidth: number }) {
  const img = document.createElement('img')
  for (const [k, v] of Object.entries(attrs)) img.setAttribute(k, v)
  if (state) {
    Object.defineProperty(img, 'complete', { value: state.complete })
    Object.defineProperty(img, 'naturalWidth', { value: state.naturalWidth })
  }
  main.appendChild(img)
  return img
}

const loaded = { complete: true, naturalWidth: 1200 }
const pending = { complete: false, naturalWidth: 0 }

beforeEach(() => {
  main = document.createElement('main')
  document.body.appendChild(main)
})

afterEach(() => {
  stop?.()
  main.remove()
})

describe('تصویری که بار نشد', () => {
  it('جلد: عنوانِ مقاله به‌جایش — به‌صورتِ متن، نه HTML', () => {
    const report = vi.fn()
    const img = image(
      { src: '/blog/media/x.webp', alt: 'جلد', 'data-fallback': '<b>عنوانِ مقاله</b>' },
      pending,
    )
    stop = watchBlogImages(main, report)

    img.dispatchEvent(new Event('error'))

    const fallback = main.querySelector('.blog-img-fallback')!
    expect(fallback.textContent).toBe('<b>عنوانِ مقاله</b>')
    expect(fallback.querySelector('b')).toBeNull()
    expect(fallback.getAttribute('role')).toBe('img')
    expect(fallback.getAttribute('aria-label')).toBe('جلد')
    expect(img.classList.contains('is-broken')).toBe(true)
  })

  it('تصویرِ متن: توضیحِ خودش (alt) به‌جایش', () => {
    const img = image({ src: '/blog/media/y.webp', alt: 'نمودارِ روندِ درآمد' }, pending)
    stop = watchBlogImages(main, vi.fn())

    img.dispatchEvent(new Event('error'))

    expect(main.querySelector('.blog-img-fallback')?.textContent).toBe('نمودارِ روندِ درآمد')
  })

  it('گزارش می‌شود — یک بار برای هر نشانی، تا ریشه پنهان نماند', () => {
    const report = vi.fn()
    const a = image({ src: '/blog/media/a.webp', alt: 'الف' }, pending)
    const b = image({ src: '/blog/media/a.webp', alt: 'الف دوباره' }, pending)
    stop = watchBlogImages(main, report)

    a.dispatchEvent(new Event('error'))
    b.dispatchEvent(new Event('error'))
    a.dispatchEvent(new Event('error'))

    expect(report).toHaveBeenCalledOnce()
    expect(report.mock.calls[0][0]).toContain('/blog/media/a.webp')
    expect(main.querySelectorAll('.blog-img-fallback')).toHaveLength(2)
  })

  it('تصویری که پیش از رسیدنِ اسکریپت شکسته بود هم گرفته می‌شود؛ سالم و درحالِ‌بارگذاری نه', () => {
    image({ src: '/blog/media/broken.webp', alt: 'شکسته' }, { complete: true, naturalWidth: 0 })
    image({ src: '/blog/media/ok.webp', alt: 'سالم' }, loaded)
    image({ src: '/blog/media/lazy.webp', alt: 'تنبل' }, pending)
    const report = vi.fn()

    stop = watchBlogImages(main, report)

    expect([...main.querySelectorAll('.blog-img-fallback')].map((f) => f.textContent)).toEqual([
      'شکسته',
    ])
    expect(report).toHaveBeenCalledOnce()
  })

  it('پس از جابه‌جاییِ بی‌بارگذاری هم (blog:navigated) دوباره نگاه می‌کند', () => {
    stop = watchBlogImages(main, vi.fn())
    image({ src: '/blog/media/late.webp', alt: 'دیررس' }, { complete: true, naturalWidth: 0 })

    window.dispatchEvent(new CustomEvent('blog:navigated'))

    expect(main.querySelector('.blog-img-fallback')?.textContent).toBe('دیررس')
  })
})
