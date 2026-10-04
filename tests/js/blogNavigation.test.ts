import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { waitFor } from '@testing-library/react'
import {
  apiUrlFor,
  initBlogNavigation,
  normalizeQuery,
  type BlogResponse,
} from '@/features/landing/blog/blogNavigation'

/**
 * ناوبریِ بلاگ بی‌بارگذاریِ دوباره (مرحلهٔ ۷ · فاز ۷).
 *
 * رفتارِ مرورگرِ واقعی — بازگشت/جلو، بارگذاریِ دوباره، اسکرول — در
 * `tests/e2e/blog-navigation.spec.ts` است؛ این‌جا منطقِ خودِ ماژول: کدام
 * کلیک گرفته می‌شود، رقابتِ دو درخواست، خطا، و جست‌وجوی خالی و کوتاه.
 */

const ORIGIN = window.location.origin

function response(view: 'listing' | 'article', url: string, marker: string): BlogResponse {
  return {
    html:
      view === 'article'
        ? `<article data-reading-progress><h1 tabindex="-1" data-blog-focus>${marker}</h1></article>`
        : `<div class="blog-layout"><div class="blog-column"><header><h1 tabindex="-1" data-blog-focus>${marker}</h1>
           <form data-blog-search action="/blog"><input name="q" value=""><button type="submit">جست‌وجو</button></form>
           <p data-blog-search-hint></p></header><div class="blog-list"><article class="blog-card"><a href="/blog/a">a</a></article></div>
           <nav class="blog-pager"><a href="/blog?page=2">۲</a></nav></div></div>`,
    meta: {
      view,
      url,
      title: `${marker} | ساکنا`,
      description: `توضیحِ ${marker}`,
      canonical: ORIGIN + url,
      robots: url.includes('q=') ? 'noindex,follow' : null,
      ogImage: null,
    },
  }
}

function json(data: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

let main: HTMLElement
let destroy: (() => void) | null = null
let scrollSpy: ReturnType<typeof vi.fn>

function setup(fetchImpl: typeof fetch) {
  const nav = initBlogNavigation(main, { fetch: fetchImpl })
  destroy = () => nav.destroy()
  return nav
}

function click(selector: string, init: MouseEventInit = {}) {
  const el = main.querySelector(selector)!
  const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init })
  el.dispatchEvent(event)
  return event
}

beforeEach(() => {
  history.replaceState(null, '', '/blog')
  document.title = 'بلاگ'
  document.head.querySelectorAll('meta[name="robots"]').forEach((m) => m.remove())
  main = document.createElement('main')
  main.dataset.blogView = 'listing'
  main.innerHTML = response('listing', '/blog', 'فهرست').html
  document.body.appendChild(main)
  scrollSpy = vi.fn()
  window.scrollTo = scrollSpy as unknown as typeof window.scrollTo
})

afterEach(() => {
  destroy?.()
  destroy = null
  main.remove()
  vi.useRealTimers()
})

describe('نگاشتِ آدرس به API', () => {
  it('فهرست، صفحه، جست‌وجو، دسته، برچسب و مقاله', () => {
    expect(apiUrlFor('/blog', ORIGIN)).toBe('/api/blog')
    expect(apiUrlFor('/blog?page=2', ORIGIN)).toBe('/api/blog?page=2')
    expect(apiUrlFor('/blog?q=شارژ&page=2', ORIGIN)).toBe(
      '/api/blog?q=' + encodeURIComponent('شارژ') + '&page=2',
    )
    expect(apiUrlFor('/blog/category/mali', ORIGIN)).toBe('/api/blog?category=mali')
    expect(apiUrlFor('/blog/tag/' + encodeURIComponent('شارژ') + '?page=3', ORIGIN)).toBe(
      '/api/blog?tag=' + encodeURIComponent('شارژ') + '&page=3',
    )
    expect(apiUrlFor('/blog/' + encodeURIComponent('پیام-رسان'), ORIGIN)).toBe(
      '/api/blog/posts/' + encodeURIComponent('پیام-رسان'),
    )
  })

  it('تصویر، بیرون از بلاگ و مبدأِ دیگر مالِ ما نیستند', () => {
    expect(apiUrlFor('/blog/media/' + 'a'.repeat(40) + '.webp', ORIGIN)).toBeNull()
    expect(apiUrlFor('/demo', ORIGIN)).toBeNull()
    expect(apiUrlFor('https://example.com/blog', ORIGIN)).toBeNull()
  })

  it('عبارت مثلِ سرور تمیز می‌شود', () => {
    expect(normalizeQuery('   پرداخت    آنلاین  ')).toBe('پرداخت آنلاین')
  })
})

describe('سوارشدن', () => {
  it('نشانه‌ی «بی‌بارگذاری فعال است» با سوارشدن می‌آید و با برداشتن می‌رود', () => {
    expect(main.hasAttribute('data-blog-enhanced')).toBe(false)
    const nav = setup(vi.fn())
    expect(main.hasAttribute('data-blog-enhanced')).toBe(true)
    nav.destroy()
    destroy = null
    expect(main.hasAttribute('data-blog-enhanced')).toBe(false)
  })
})

describe('کلیک', () => {
  it('کارت بی‌بارگذاری باز می‌شود: بدنه، عنوان، آدرس و فوکوس عوض می‌شوند', async () => {
    const fetchImpl = vi.fn(() => json(response('article', '/blog/a', 'مقاله‌ی الف')))
    setup(fetchImpl)

    const before = history.length
    const event = click('.blog-card a')

    expect(event.defaultPrevented).toBe(true)
    // یک گامِ تازه در تاریخچه — وگرنه «بازگشت» از بلاگ بیرون می‌رفت
    expect(history.length).toBe(before + 1)
    await waitFor(() => expect(main.querySelector('h1')?.textContent).toBe('مقاله‌ی الف'))
    expect(fetchImpl).toHaveBeenCalledWith(
      '/api/blog/posts/a',
      expect.objectContaining({ headers: { Accept: 'application/json' } }),
    )
    expect(window.location.pathname).toBe('/blog/a')
    expect(document.title).toBe('مقاله‌ی الف | ساکنا')
    expect(main.dataset.blogView).toBe('article')
    expect(document.activeElement).toBe(main.querySelector('h1'))
    expect(document.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe(
      ORIGIN + '/blog/a',
    )
  })

  it('مسیرِ راهنما (بیرونِ main) هم با صفحه عوض می‌شود', async () => {
    const bar = document.createElement('nav')
    bar.className = 'breadcrumb-bar'
    bar.innerHTML =
      '<ol><li><a href="/">خانه</a></li><li><span aria-current="page">بلاگ</span></li></ol>'
    document.body.prepend(bar)
    const article = response('article', '/blog/a', 'مقاله‌ی الف')
    article.meta.breadcrumbs =
      '<ol><li><a href="/">خانه</a></li><li><a href="/blog">بلاگ</a></li><li><span aria-current="page">مقاله‌ی الف</span></li></ol>'
    setup(vi.fn(() => json(article)))

    click('.blog-card a')

    try {
      await waitFor(() =>
        expect(bar.querySelector('[aria-current="page"]')?.textContent).toBe('مقاله‌ی الف'),
      )
      expect(bar.querySelector('a[href="/blog"]')).not.toBeNull()
    } finally {
      bar.remove()
    }
  })

  it('Ctrl، کلیکِ میانی، پنجره‌ی تازه و پیوندِ درون‌صفحه گرفته نمی‌شوند', () => {
    const fetchImpl = vi.fn()
    setup(fetchImpl)
    main.insertAdjacentHTML(
      'beforeend',
      '<a id="blank" href="/blog/x" target="_blank">x</a><a id="toc" href="#h-1">toc</a>',
    )

    expect(click('.blog-card a', { ctrlKey: true }).defaultPrevented).toBe(false)
    expect(click('.blog-card a', { button: 1 }).defaultPrevented).toBe(false)
    expect(click('#blank').defaultPrevented).toBe(false)
    expect(click('#toc').defaultPrevented).toBe(false)
    expect(fetchImpl).not.toHaveBeenCalled()
  })

  it('رقابت: پاسخِ دیررسیده‌ی کلیکِ اول روی کلیکِ دوم نمی‌نشیند', async () => {
    /*
     * ⚠️ پاسخِ اول **لغو را نادیده می‌گیرد** و پس از دومی، با یک بدنه‌ی معتبر
     * می‌رسد — مثلِ کشی که لغو را نمی‌فهمد. نسخه‌ی اولِ این تست پاسخِ اول را
     * فقط با لغو رها می‌کرد و خرابکاری نشان داد که برداشتنِ بررسیِ ترتیب را
     * نمی‌گیرد؛ لغو به‌تنهایی همان کار را می‌کرد.
     */
    let releaseFirst!: (r: Response) => void
    const first = new Promise<Response>((resolve) => (releaseFirst = resolve))
    const fetchImpl = vi
      .fn()
      .mockImplementationOnce(() => first)
      .mockImplementationOnce(() => json(response('listing', '/blog?page=2', 'صفحه‌ی دو')))
    setup(fetchImpl)

    click('.blog-card a')
    click('.blog-pager a')

    await waitFor(() => expect(main.querySelector('h1')?.textContent).toBe('صفحه‌ی دو'))
    // درخواستِ اول لغو شد
    expect((fetchImpl.mock.calls[0][1] as RequestInit).signal?.aborted).toBe(true)

    releaseFirst(
      new Response(JSON.stringify(response('article', '/blog/a', 'دیررسیده')), {
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    await new Promise((r) => setTimeout(r, 30))
    expect(main.querySelector('h1')?.textContent).toBe('صفحه‌ی دو')
    expect(window.location.search).toBe('?page=2')
  })

  it('خطا: پیامِ فارسی و «تلاش مجدد» که همان درخواست را دوباره می‌فرستد', async () => {
    const fetchImpl = vi
      .fn()
      .mockImplementationOnce(() => json({}, 500))
      .mockImplementationOnce(() => json(response('listing', '/blog?page=2', 'صفحه‌ی دو')))
    setup(fetchImpl)

    click('.blog-pager a')

    await waitFor(() => expect(main.querySelector('[role="alert"]')).not.toBeNull())
    expect(main.textContent).toContain('دریافتِ مقالات با مشکل مواجه شد. دوباره تلاش کنید.')

    main.querySelector<HTMLButtonElement>('.blog-retry')!.click()
    await waitFor(() => expect(main.querySelector('h1')?.textContent).toBe('صفحه‌ی دو'))
    expect(fetchImpl).toHaveBeenCalledTimes(2)
  })

  it('سقفِ نرخ پیامِ جدا دارد', async () => {
    setup(vi.fn(() => json({}, 429)))

    click('.blog-pager a')

    await waitFor(() => expect(main.textContent).toContain('چند لحظه صبر کنید'))
  })
})

describe('جست‌وجو', () => {
  function submit(value: string) {
    const input = main.querySelector<HTMLInputElement>('input[name="q"]')!
    input.value = value
    const form = main.querySelector('form')!
    const event = new Event('submit', { bubbles: true, cancelable: true })
    form.dispatchEvent(event)
    return event
  }

  it('خالی و یک‌حرفی درخواست نمی‌فرستند و علت را می‌گویند', () => {
    const fetchImpl = vi.fn()
    setup(fetchImpl)

    expect(submit('   ').defaultPrevented).toBe(true)
    expect(main.querySelector('[data-blog-search-hint]')?.textContent).toBe(
      'عبارتی برای جست‌وجو بنویسید.',
    )

    submit('ش')
    expect(main.querySelector('[data-blog-search-hint]')?.textContent).toContain('دست‌کم')
    expect(fetchImpl).not.toHaveBeenCalled()
  })

  it('عبارتِ تمیزشده در آدرس می‌ماند و robots ِ noindex اعمال می‌شود', async () => {
    const fetchImpl = vi.fn(() => json(response('listing', '/blog?q=شارژ', 'نتیجه')))
    setup(fetchImpl)

    submit('  شارژ  ')

    await waitFor(() => expect(main.querySelector('h1')?.textContent).toBe('نتیجه'))
    expect(fetchImpl).toHaveBeenCalledWith(
      '/api/blog?q=' + encodeURIComponent('شارژ'),
      expect.anything(),
    )
    expect(new URL(window.location.href).searchParams.get('q')).toBe('شارژ')
    expect(document.head.querySelector('meta[name="robots"]')?.getAttribute('content')).toBe(
      'noindex,follow',
    )
  })

  it('همان عبارت دوباره درخواست نمی‌فرستد (دوبار-ارسال)', () => {
    history.replaceState(null, '', '/blog?q=' + encodeURIComponent('شارژ'))
    const fetchImpl = vi.fn()
    setup(fetchImpl)

    submit('شارژ')

    expect(fetchImpl).not.toHaveBeenCalled()
  })

  it('robots ِ جست‌وجو روی صفحه‌ی بعدی نمی‌ماند', async () => {
    const fetchImpl = vi
      .fn()
      .mockImplementationOnce(() => json(response('listing', '/blog?q=شارژ', 'نتیجه')))
      .mockImplementationOnce(() => json(response('article', '/blog/a', 'مقاله')))
    setup(fetchImpl)

    submit('شارژ')
    await waitFor(() => expect(document.head.querySelector('meta[name="robots"]')).not.toBeNull())

    click('.blog-card a')
    await waitFor(() => expect(main.querySelector('h1')?.textContent).toBe('مقاله'))
    expect(document.head.querySelector('meta[name="robots"]')).toBeNull()
  })
})

describe('تاریخچه', () => {
  it('پیوندِ درون‌صفحه (# ِ فهرستِ مطالب) صفحه را دوباره نمی‌کشد و به بالا نمی‌برد', () => {
    const fetchImpl = vi.fn(() => json(response('listing', '/blog', 'فهرست')))
    setup(fetchImpl)

    history.pushState(null, '', '/blog#h-x')
    window.dispatchEvent(new PopStateEvent('popstate', { state: null }))
    history.replaceState(null, '', '/blog')

    expect(fetchImpl).not.toHaveBeenCalled()
    expect(scrollSpy).not.toHaveBeenCalled()
    expect(main.querySelector('h1')?.textContent).toBe('فهرست')
  })

  it('«بازگشت» از کش می‌خواند و درخواستِ تکراری نمی‌فرستد', async () => {
    const fetchImpl = vi
      .fn()
      .mockImplementationOnce(() => json(response('listing', '/blog?page=2', 'صفحه‌ی دو')))
      .mockImplementationOnce(() => json(response('article', '/blog/a', 'مقاله')))
    setup(fetchImpl)

    click('.blog-pager a')
    await waitFor(() => expect(main.querySelector('h1')?.textContent).toBe('صفحه‌ی دو'))
    click('.blog-card a')
    await waitFor(() => expect(main.querySelector('h1')?.textContent).toBe('مقاله'))

    history.replaceState({ blog: true, scrollY: 0 }, '', '/blog?page=2')
    window.dispatchEvent(new PopStateEvent('popstate', { state: { blog: true, scrollY: 420 } }))

    await waitFor(() => expect(main.querySelector('h1')?.textContent).toBe('صفحه‌ی دو'))
    expect(fetchImpl).toHaveBeenCalledTimes(2)
    expect(scrollSpy).toHaveBeenLastCalledWith({ top: 420, behavior: 'instant' })
  })
})
