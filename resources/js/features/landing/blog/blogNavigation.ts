/**
 * ناوبریِ بلاگ بی‌بارگذاریِ دوباره (مرحلهٔ ۷ · فاز ۷).
 *
 * ─── چرا این‌طور و نه SPA ──────────────────────────────────────────────────
 * صفحه‌های بلاگ MPA ِ سرورساید‌اند و باید بمانند: خزنده HTML ِ کامل می‌خواهد،
 * و آدرسِ مستقیم، بارگذاریِ دوباره و مرورگرِ بی‌جاوااسکریپت همان صفحه را
 * می‌گیرند. ممیزی هم صریح گفته بود معماریِ MPA فقط برای بلاگ شکسته نشود.
 *
 * پس این ماژول روی همان صفحه سوار می‌شود: کلیکِ کارت، صفحه‌بندی، دسته و
 * برچسب، و فرمِ جست‌وجو را می‌گیرد، بدنه‌ی `<main>` را از `/api/blog` می‌خواند
 * — که **همان قالبِ Blade** را رندر کرده — و جایگزین می‌کند. آدرس با History
 * API عوض می‌شود، پس «بازگشت» و «جلو» و اشتراکِ آدرس همان رفتارِ صفحه‌ی
 * کامل را دارند.
 *
 * ─── رفتارها ───────────────────────────────────────────────────────────────
 *   • فقط آخرین درخواست برنده است: قبلی لغو می‌شود و پاسخِ دیررسیده دور ریخته
 *     می‌شود (رقابتِ دو کلیکِ پشت‌سرهم)
 *   • پاسخِ تازه ۶۰ ثانیه کش می‌شود — «بازگشت» فوری است و درخواستِ تکراری نمی‌رود
 *   • جست‌وجوی خالی یا یک‌حرفی درخواست نمی‌فرستد؛ عبارتِ تکراری هم نه
 *   • بارگذاری: اسکلت (فقط اگر بیش از ‎۱۵۰ms طول بکشد، تا پاسخِ سریع چشمک نزند)
 *   • خطا: پیامِ فارسی و «تلاش مجدد»؛ مقاله‌ی ناموجود → صفحه‌ی ۴۰۴ ِ واقعیِ سرور
 *   • جای اسکرولِ هر گام در `history.state` نگه داشته و با «بازگشت» برگردانده می‌شود
 *   • پس از جابه‌جایی، فوکوس به عنوانِ صفحه می‌رود (صفحه‌خوان تغییر را می‌شنود)
 */

export interface BlogMeta {
  view: 'listing' | 'article'
  url: string
  title: string
  description: string
  canonical: string
  robots: string | null
  ogImage: string | null
  /** `<ol>` ِ مسیرِ راهنما از همان قالب؛ بیرونِ `<main>` است و باید جدا عوض شود */
  breadcrumbs?: string
}

export interface BlogResponse {
  html: string
  meta: BlogMeta
}

/** کوتاه‌ترین عبارتِ جست‌وجو — همان `PublicBlog::MIN_SEARCH`. */
export const MIN_SEARCH = 2

const CACHE_MS = 60_000
const CACHE_SIZE = 20
const SKELETON_DELAY_MS = 150
/** ارتفاعِ سرصفحه‌ی ثابت؛ صفحه‌بندی تا زیرِ آن اسکرول می‌کند. */
const HEADER_OFFSET = 72

/**
 * آدرسِ صفحه‌ی بلاگ → آدرسِ API ِ متناظر؛ `null` یعنی «این پیوند مالِ ما نیست».
 *
 * ⚠️ تصویر (`/blog/media/…`) و هر چیزِ بیرون از `/blog` دست نمی‌خورد.
 */
export function apiUrlFor(href: string, origin = window.location.origin): string | null {
  let url: URL
  try {
    url = new URL(href, origin)
  } catch {
    return null
  }

  if (url.origin !== origin) return null

  const path = url.pathname.replace(/\/+$/, '') || '/'
  const page = url.searchParams.get('page')
  const params = new URLSearchParams()

  if (path === '/blog') {
    const q = url.searchParams.get('q')
    if (q !== null && q.trim() !== '') params.set('q', q)
    if (page) params.set('page', page)
    const query = params.toString()
    return '/api/blog' + (query ? '?' + query : '')
  }

  const listing = /^\/blog\/(category|tag)\/([^/]+)$/.exec(path)
  if (listing) {
    params.set(listing[1], decodeURIComponent(listing[2]))
    if (page) params.set('page', page)
    return '/api/blog?' + params.toString()
  }

  const article = /^\/blog\/([^/]+)$/.exec(path)
  if (article && article[1] !== 'media') {
    return '/api/blog/posts/' + article[1]
  }

  return null
}

/** فاصله‌ی دو سر و فاصله‌های پیاپی — همان کاری که سرور هم می‌کند. */
export function normalizeQuery(value: string): string {
  return value.replace(/\s+/g, ' ').trim()
}

interface NavigateOptions {
  push?: boolean
  scrollY?: number
  /** کجا اسکرول شود: بالای صفحه، بالای فهرست، یا جای ذخیره‌شده */
  scroll?: 'top' | 'list' | 'restore' | 'none'
}

interface BlogNavigationOptions {
  fetch?: typeof fetch
  /** پیش از جایگزینیِ بدنه — مثلاً برداشتنِ جزیره‌های React ِ بدنه‌ی قبلی */
  beforeSwap?: () => void
  /** پس از جایگزینی — سوارکردنِ دوباره‌ی دکمه‌ی اشتراک و نوارِ پیشرفت */
  afterSwap?: (meta: BlogMeta) => void
}

interface CacheEntry {
  at: number
  data: BlogResponse
}

export function initBlogNavigation(main: HTMLElement, options: BlogNavigationOptions = {}) {
  const doFetch = options.fetch ?? window.fetch.bind(window)
  const cache = new Map<string, CacheEntry>()
  let controller: AbortController | null = null
  let sequence = 0
  // آدرسی (بی‌ #) که `main` نشانش می‌دهد یا در راهِ نشان‌دادنش است
  let shown = window.location.pathname + window.location.search

  // اسکرول را خودمان برمی‌گردانیم؛ مرورگر پیش از رسیدنِ محتوا جای غلطی می‌پرید
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual'
  history.replaceState({ ...(history.state ?? {}), blog: true, scrollY: window.scrollY }, '')

  function rememberScroll() {
    history.replaceState({ ...(history.state ?? {}), blog: true, scrollY: window.scrollY }, '')
  }

  function cached(api: string): BlogResponse | null {
    const entry = cache.get(api)
    if (!entry || Date.now() - entry.at > CACHE_MS) return null
    return entry.data
  }

  function store(api: string, data: BlogResponse) {
    cache.delete(api)
    cache.set(api, { at: Date.now(), data })
    while (cache.size > CACHE_SIZE) cache.delete(cache.keys().next().value as string)
  }

  async function navigate(href: string, opts: NavigateOptions = {}): Promise<void> {
    const api = apiUrlFor(href)
    if (api === null) {
      window.location.assign(href)
      return
    }

    const { push = true, scroll = 'top' } = opts
    const target = new URL(href, window.location.origin)
    const relative = target.pathname + target.search
    shown = relative

    if (push) {
      rememberScroll()
      history.pushState({ blog: true, scrollY: 0 }, '', relative)
    }

    controller?.abort()
    const mine = new AbortController()
    controller = mine
    const id = ++sequence

    const fresh = cached(api)
    if (fresh) {
      apply(fresh, { scroll, scrollY: opts.scrollY, replaceUrl: false })
      return
    }

    const goingTo: BlogMeta['view'] = api.startsWith('/api/blog/posts/') ? 'article' : 'listing'
    const skeleton = window.setTimeout(() => showSkeleton(goingTo), SKELETON_DELAY_MS)
    main.setAttribute('aria-busy', 'true')

    try {
      const response = await doFetch(api, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: mine.signal,
      })

      if (id !== sequence) return

      // مقاله یا دسته‌ای که نیست: صفحه‌ی ۴۰۴ ِ واقعیِ سرور، نه یک پیامِ ساختگی
      if (response.status === 404) {
        window.location.assign(relative)
        return
      }

      if (!response.ok) {
        showError(
          goingTo,
          response.status === 429,
          () => void navigate(relative, { push: false, scroll }),
        )
        return
      }

      const data = (await response.json()) as BlogResponse
      if (id !== sequence) return

      store(api, data)
      apply(data, { scroll, scrollY: opts.scrollY, replaceUrl: true })
    } catch (error) {
      // لغوِ عمدی (کلیکِ تازه‌تر) خطا نیست
      if (id !== sequence || (error instanceof DOMException && error.name === 'AbortError')) return
      showError(goingTo, false, () => void navigate(relative, { push: false, scroll }))
    } finally {
      window.clearTimeout(skeleton)
      if (id === sequence) main.removeAttribute('aria-busy')
    }
  }

  function apply(
    data: BlogResponse,
    opts: { scroll: NavigateOptions['scroll']; scrollY?: number; replaceUrl: boolean },
  ) {
    options.beforeSwap?.()
    main.innerHTML = data.html
    main.dataset.blogView = data.meta.view
    updateHead(data.meta)

    const crumbs = document.querySelector('nav.breadcrumb-bar')
    if (crumbs && data.meta.breadcrumbs) crumbs.innerHTML = data.meta.breadcrumbs

    // سرور آدرس را نرمال می‌کند (مثلاً صفحه‌ی ۹ ِ فهرستِ دوصفحه‌ای → ۲)
    if (opts.replaceUrl && data.meta.url !== window.location.pathname + window.location.search) {
      history.replaceState({ ...(history.state ?? {}), blog: true }, '', data.meta.url)
    }
    shown = window.location.pathname + window.location.search

    if (opts.scroll === 'restore') {
      window.scrollTo({ top: opts.scrollY ?? 0, behavior: 'instant' as ScrollBehavior })
    } else if (opts.scroll === 'list') {
      const column = main.querySelector('.blog-column') ?? main
      const top = column.getBoundingClientRect().top + window.scrollY - HEADER_OFFSET
      if (window.scrollY > top) window.scrollTo({ top, behavior: 'instant' as ScrollBehavior })
    } else if (opts.scroll === 'top') {
      window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior })
    }

    main.querySelector<HTMLElement>('[data-blog-focus]')?.focus({ preventScroll: true })
    options.afterSwap?.(data.meta)
    window.dispatchEvent(new CustomEvent('blog:navigated', { detail: data.meta }))
  }

  function showSkeleton(view: BlogMeta['view']) {
    const list = main.querySelector('.blog-list, .blog-empty')
    // فهرست → فهرست: سرصفحه و جست‌وجو می‌مانند، فقط کارت‌ها اسکلت می‌شوند
    if (view === 'listing' && list && main.dataset.blogView === 'listing') {
      main.querySelector('.blog-pager')?.remove()
      list.outerHTML = listSkeleton()
      return
    }
    main.innerHTML =
      view === 'article'
        ? articleSkeleton()
        : `<div class="blog-layout"><div class="blog-column">${listSkeleton()}</div></div>`
  }

  function showError(view: BlogMeta['view'], tooMany: boolean, retry: () => void) {
    options.beforeSwap?.()
    const message = tooMany
      ? 'درخواست‌ها پشتِ هم زیاد شد. چند لحظه صبر کنید و دوباره تلاش کنید.'
      : view === 'article'
        ? 'دریافتِ مقاله با مشکل مواجه شد. دوباره تلاش کنید.'
        : 'دریافتِ مقالات با مشکل مواجه شد. دوباره تلاش کنید.'

    main.innerHTML = `<div class="blog-error" role="alert">
      <p class="blog-empty-title"></p>
      <button type="button" class="blog-retry">تلاش مجدد</button>
      <a href="/blog">همه‌ی مقاله‌ها</a>
    </div>`
    main.querySelector('.blog-empty-title')!.textContent = message
    main.querySelector('.blog-retry')!.addEventListener('click', retry)
    main.querySelector<HTMLElement>('.blog-retry')!.focus({ preventScroll: true })
  }

  function onClick(event: MouseEvent) {
    if (event.defaultPrevented || event.button !== 0) return
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

    const anchor = (event.target as Element | null)?.closest?.('a')
    if (!anchor || !main.contains(anchor)) return
    if (anchor.target && anchor.target !== '_self') return
    if (anchor.hasAttribute('download')) return

    const href = anchor.getAttribute('href') ?? ''
    // پیوندِ درون‌صفحه‌ای (فهرستِ مطالب) کارِ خودِ مرورگر است
    if (href.startsWith('#')) return
    if (apiUrlFor(anchor.href) === null) return

    event.preventDefault()
    const target = new URL(anchor.href)
    const sameListing =
      main.dataset.blogView === 'listing' &&
      apiUrlFor(target.href)?.startsWith('/api/blog?') === true
    void navigate(target.pathname + target.search, { scroll: sameListing ? 'list' : 'top' })
  }

  function hint(form: HTMLFormElement, text: string) {
    const box = main.querySelector<HTMLElement>('[data-blog-search-hint]')
    if (box) box.textContent = text
    form.querySelector<HTMLInputElement>('input[name="q"]')?.focus()
  }

  function onSubmit(event: SubmitEvent) {
    const form = event.target as HTMLFormElement
    if (!form.matches?.('[data-blog-search]') || !main.contains(form)) return
    event.preventDefault()

    const input = form.querySelector<HTMLInputElement>('input[name="q"]')
    const q = normalizeQuery(input?.value ?? '')
    if (input) input.value = q

    const current = new URL(window.location.href)
    const currentQ = normalizeQuery(current.searchParams.get('q') ?? '')

    if (q === '') {
      // پاک‌کردنِ عبارت یعنی برگشت به همه‌ی مقاله‌ها؛ وگرنه چیزی برای فرستادن نیست
      if (currentQ !== '') void navigate('/blog', { scroll: 'list' })
      else hint(form, 'عبارتی برای جست‌وجو بنویسید.')
      return
    }

    if ([...q].length < MIN_SEARCH) {
      hint(
        form,
        `عبارتِ جست‌وجو دست‌کم ${new Intl.NumberFormat('fa-IR').format(MIN_SEARCH)} حرف باشد.`,
      )
      return
    }

    // همان عبارت روی صفحه‌ی اول: درخواستِ تکراری نمی‌رود
    if (q === currentQ && !current.searchParams.has('page') && current.pathname === '/blog') return

    hint(form, '')
    void navigate('/blog?q=' + encodeURIComponent(q), { scroll: 'list' })
  }

  function onPopState(event: PopStateEvent) {
    if (!window.location.pathname.startsWith('/blog')) return
    // پیوندِ فهرستِ مطالب (`#h-…`) هم popstate می‌سازد؛ همان صفحه است و
    // پرشِ مرورگر به عنوان کافی است — وگرنه دوباره‌کشیدن آن را به بالا می‌برد
    if (window.location.pathname + window.location.search === shown) return
    const state = (event.state ?? {}) as { scrollY?: number }
    void navigate(window.location.pathname + window.location.search, {
      push: false,
      scroll: 'restore',
      scrollY: state.scrollY ?? 0,
    })
  }

  document.addEventListener('click', onClick)
  document.addEventListener('submit', onSubmit)
  window.addEventListener('popstate', onPopState)
  /*
   * از این لحظه کلیک‌ها بی‌بارگذاری‌اند. پیش از آن (اسکریپت هنوز نرسیده)
   * پیوندها ناوبریِ معمولی‌اند — همان پس‌رفتِ درست؛ E2E با همین نشانه
   * می‌داند کی کلیک کند (مرحلهٔ ۸ · فاز ۲: یک اجرای سرد پیش از آن کلیک کرد).
   */
  main.dataset.blogEnhanced = ''

  return {
    navigate,
    destroy() {
      document.removeEventListener('click', onClick)
      document.removeEventListener('submit', onSubmit)
      window.removeEventListener('popstate', onPopState)
      delete main.dataset.blogEnhanced
      controller?.abort()
    },
  }
}

function updateHead(meta: BlogMeta) {
  document.title = meta.title
  setMeta('name', 'description', meta.description)
  setMeta('name', 'robots', meta.robots)
  setMeta('property', 'og:title', meta.title)
  setMeta('property', 'og:description', meta.description)
  setMeta('property', 'og:url', meta.canonical)
  setMeta('property', 'og:image', meta.ogImage)

  let canonical = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]')
  if (!canonical) {
    canonical = document.createElement('link')
    canonical.rel = 'canonical'
    document.head.appendChild(canonical)
  }
  canonical.href = meta.canonical
}

function setMeta(attribute: 'name' | 'property', key: string, value: string | null) {
  let tag = document.head.querySelector<HTMLMetaElement>(`meta[${attribute}="${key}"]`)
  if (value === null || value === '') {
    // robots ِ جست‌وجو (noindex) نباید روی صفحه‌ی بعدی بماند
    tag?.remove()
    return
  }
  if (!tag) {
    tag = document.createElement('meta')
    tag.setAttribute(attribute, key)
    document.head.appendChild(tag)
  }
  tag.content = value
}

function listSkeleton(): string {
  const card = `<div class="blog-card blog-skeleton" aria-hidden="true">
      <div class="blog-card-cover"><span class="blog-skeleton-block"></span></div>
      <div class="blog-card-body">
        <span class="blog-skeleton-line" style="width:30%"></span>
        <span class="blog-skeleton-line blog-skeleton-title" style="width:85%"></span>
        <span class="blog-skeleton-line" style="width:100%"></span>
        <span class="blog-skeleton-line" style="width:70%"></span>
      </div>
    </div>`
  return `<div class="blog-list" role="status" aria-label="در حالِ دریافتِ مقاله‌ها">${card.repeat(3)}</div>`
}

function articleSkeleton(): string {
  return `<div class="blog-article blog-skeleton" role="status" aria-label="در حالِ دریافتِ مقاله">
      <span class="blog-skeleton-line" style="width:20%"></span>
      <span class="blog-skeleton-line blog-skeleton-heading" style="width:80%"></span>
      <span class="blog-skeleton-line" style="width:35%"></span>
      <span class="blog-skeleton-block blog-skeleton-cover"></span>
      <span class="blog-skeleton-line" style="width:100%"></span>
      <span class="blog-skeleton-line" style="width:95%"></span>
      <span class="blog-skeleton-line" style="width:60%"></span>
    </div>`
}
