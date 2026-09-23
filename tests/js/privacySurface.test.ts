import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * حریمِ خصوصی، اسکریپتِ بیرونی و پیکربندیِ باندل (مرحلهٔ ۵ · فاز ۷ · بندهای ۵۷–۶۳).
 *
 * دو نیمه دارد:
 *  - **رفتاری:** سرویس‌های تحلیلی واقعاً بار می‌شوند و محموله‌شان سنجیده می‌شود.
 *  - **ساختاری:** قاعده‌هایی که فردا با یک خطِ تازه می‌شکنند (innerHTML،
 *    لینکِ `_blank` بی‌`rel`، متغیرِ محیطیِ تازه در باندل).
 */

const ROOT = join(__dirname, '../..')
const JS = join(ROOT, 'resources/js')

function walk(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? walk(path) : [path]
  })
}

/** کامنت‌ها برداشته می‌شوند تا توضیحی که قاعده را نام می‌برد، نقضش حساب نشود. */
function code(path: string): string {
  return readFileSync(path, 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:'"`])\/\/.*$/gm, '$1')
    .replace(/\{\s*\/\*[\s\S]*?\*\/\s*\}/g, '')
}

const SOURCES = walk(JS).filter((f) => /\.(ts|tsx)$/.test(f) && !f.endsWith('.d.ts'))

/* ── رفتاری: سرویس‌های تحلیلی ─────────────────────────────────────────────── */

describe('سرویس‌های تحلیلی', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubEnv('PROD', true)
    document.head.innerHTML = ''
    const config = document.createElement('script')
    config.type = 'application/json'
    config.id = 'observability-config'
    config.textContent = JSON.stringify({
      ga4MeasurementId: 'G-TEST',
      gtmContainerId: 'GTM-TEST',
      clarityProjectId: 'clar1',
    })
    document.head.appendChild(config)
    window.dataLayer = []
    delete window.gtag
    delete window.clarity
  })

  afterEach(() => {
    vi.unstubAllEnvs()
    window.history.replaceState(null, '', '/')
  })

  const injected = () =>
    Array.from(document.head.querySelectorAll('script[src]'), (s) => s.getAttribute('src') ?? '')

  it('داشبورد و صفحه‌ی ورود: نه ضبطِ جلسه، نه GTM — فقط GA4', async () => {
    const { initAnalytics } = await import('@/shared/lib/observability/analytics')

    initAnalytics()

    const srcs = injected()
    expect(srcs.some((s) => s.includes('gtag/js'))).toBe(true)
    expect(srcs.some((s) => s.includes('clarity.ms'))).toBe(false)
    expect(srcs.some((s) => s.includes('gtm.js'))).toBe(false)
    expect(window.clarity).toBeUndefined()
  })

  it('صفحه‌های بازاریابی: هر سه', async () => {
    const { initAnalytics } = await import('@/shared/lib/observability/analytics')

    initAnalytics(true)

    const srcs = injected()
    expect(srcs.some((s) => s.includes('clarity.ms'))).toBe(true)
    expect(srcs.some((s) => s.includes('gtm.js'))).toBe(true)
  })

  it('بازدیدِ صفحه بدونِ کوئری و hash می‌رود — حتی اگر فراخوان کوئری بدهد', async () => {
    const { initAnalytics, trackPageView } = await import('@/shared/lib/observability/analytics')
    window.history.replaceState(null, '', '/pay/7?error=secret-message#frag')

    initAnalytics(true)
    trackPageView('/pay/7?error=secret-message')

    const sent = JSON.stringify(window.dataLayer)
    expect(sent).toContain('/pay/7')
    expect(sent).not.toContain('secret-message')
    expect(sent).not.toContain('frag')
  })

  it('بی‌GTM، رویداد به dataLayerِ GTM نمی‌رود', async () => {
    const { initAnalytics, trackPageView } = await import('@/shared/lib/observability/analytics')

    initAnalytics()
    trackPageView('/dashboard')

    expect(JSON.stringify(window.dataLayer)).not.toContain('spa_page_view')
  })
})

describe('گزارشِ خطا', () => {
  afterEach(() => {
    window.history.replaceState(null, '', '/')
    vi.restoreAllMocks()
  })

  it('آدرسِ خطا بدونِ کوئری از مرورگر بیرون می‌رود', async () => {
    const { reportError } = await import('@/shared/lib/observability/errorReporting')
    const beacon = vi.fn<(url: string, data?: BodyInit | null) => boolean>(() => true)
    Object.defineProperty(navigator, 'sendBeacon', { value: beacon, configurable: true })
    window.history.replaceState(null, '', '/search?q=private-term#x')

    reportError(new Error('boom'))

    const blob = beacon.mock.calls[0]?.[1]
    expect(blob).toBeInstanceOf(Blob)
    const body = await (blob as Blob).text()
    expect(body).toContain('/search')
    expect(body).not.toContain('private-term')
  })

  it('withoutQuery هم کوئری و هم hash را می‌بُرد، و آدرسِ بی‌کوئری را دست نمی‌زند', async () => {
    const { withoutQuery } = await import('@/shared/lib/observability/errorReporting')

    expect(withoutQuery('https://a.test/x?phone=0912#h')).toBe('https://a.test/x')
    expect(withoutQuery('https://a.test/x#only')).toBe('https://a.test/x')
    expect(withoutQuery('/plain/path')).toBe('/plain/path')
  })
})

/* ── ساختاری ──────────────────────────────────────────────────────────────── */

describe('نقطه‌های ورود', () => {
  const entries = [
    ...readdirSync(join(JS, 'app/entries')).map((f) => join(JS, 'app/entries', f)),
    join(JS, 'app/main.tsx'),
  ]

  it('فقط خانه، دمو و پشتیبانی تگ‌های بازاریابی (Clarity/GTM) می‌گیرند', () => {
    const calls = entries.map((f) => ({
      name: relative(JS, f).replace(/\\/g, '/'),
      source: code(f),
    }))

    // ضدِ پوچی: هر نقطه‌ی ورود پایش را راه می‌اندازد
    for (const { name, source } of calls) expect(source, name).toMatch(/initObservability\(/)
    expect(calls.length).toBeGreaterThanOrEqual(5)

    const marketing = calls
      .filter(({ source }) => /marketingTags\s*:\s*true/.test(source))
      .map(({ name }) => name)
    expect(marketing.sort()).toEqual([
      'app/entries/demo.tsx',
      'app/entries/home.tsx',
      'app/entries/support.tsx',
    ])
  })
})

describe('HTMLِ خام', () => {
  it('هیچ کدی HTML را مستقیم در DOM نمی‌نویسد', () => {
    const offenders = SOURCES.filter((f) =>
      /dangerouslySetInnerHTML|\.innerHTML\s*=|\.outerHTML\s*=|insertAdjacentHTML|document\.write\(/.test(
        code(f),
      ),
    ).map((f) => relative(JS, f))

    expect(SOURCES.length).toBeGreaterThan(150)
    expect(offenders).toEqual([])
  })
})

describe('لینکِ پنجره‌ی تازه', () => {
  /** تگِ کاملِ JSX که `target="_blank"` در آن است — با آکولادهای تودرتو. */
  function blankTargets(source: string): string[] {
    const tags: string[] = []
    let from = 0

    for (;;) {
      const at = source.indexOf('target="_blank"', from)
      if (at === -1) return tags

      const start = source.lastIndexOf('<', at)
      let depth = 0
      let end = start
      for (; end < source.length; end++) {
        const ch = source[end]
        if (ch === '{') depth++
        else if (ch === '}') depth--
        else if (ch === '>' && depth === 0 && source[end - 1] !== '=') break
      }

      tags.push(source.slice(start, end + 1))
      from = at + 1
    }
  }

  it('هر لینکِ بیرونیِ _blank با noopener یا noreferrer است', () => {
    const tags = SOURCES.flatMap((f) =>
      blankTargets(code(f)).map((tag) => ({ file: relative(JS, f), tag })),
    )

    // ضدِ پوچی: همان ده لینکی که فهرستِ اولیه شمرده بود
    expect(tags.length).toBeGreaterThanOrEqual(10)

    const unsafe = tags.filter(
      ({ tag }) => /\bhref=/.test(tag) && !/\brel="[^"]*\bno(opener|referrer)\b/.test(tag),
    )
    expect(unsafe).toEqual([])
  })

  it('در قالب‌های Blade هم', () => {
    const views = walk(join(ROOT, 'resources/views')).filter((f) => f.endsWith('.blade.php'))
    const tags = views.flatMap(
      (f) => readFileSync(f, 'utf8').match(/<a\b[^>]*target="_blank"[^>]*>/g) ?? [],
    )

    expect(tags.length).toBeGreaterThanOrEqual(1)
    for (const tag of tags) expect(tag).toMatch(/\brel="[^"]*\bno(opener|referrer)\b/)
  })
})

describe('چه چیزی به باندلِ مرورگر می‌رود', () => {
  /*
   * ⚠️ Vite هر متغیرِ `VITE_*` را **در متنِ باندل** می‌نویسد؛ هر کسی
   * می‌خواندش. پیکربندیِ زمانِ اجرا (شناسه‌های تحلیل، CSRF، نسخه‌ی ساخت) از
   * خودِ سرور در `<head>` می‌آید، پس یک باندل در هر سه محیط کار می‌کند و
   * باندل به هیچ متغیرِ محیطیِ دیگری نیاز ندارد.
   */
  const ALLOWED_META = new Set(['DEV', 'PROD', 'MODE', 'SSR', 'BASE_URL'])

  it('کد فقط پرچم‌های داخلیِ Vite را از import.meta.env می‌خواند', () => {
    const used = new Set(
      SOURCES.flatMap((f) => [...code(f).matchAll(/import\.meta\.env\.(\w+)/g)].map((m) => m[1])),
    )

    expect(used.size).toBeGreaterThan(0)
    expect([...used].filter((name) => !ALLOWED_META.has(name))).toEqual([])
  })

  it('هیچ متغیرِ VITE_ در نمونه‌های محیط، شبیهِ راز نیست', () => {
    const examples = readdirSync(ROOT).filter(
      (f) => /^\.env\..*example$/.test(f) || f === '.env.example',
    )
    expect(examples.length).toBeGreaterThanOrEqual(3)

    const vite = examples.flatMap((f) =>
      [...readFileSync(join(ROOT, f), 'utf8').matchAll(/^(VITE_\w+)=/gm)].map((m) => m[1]),
    )

    expect(vite.filter((name) => /SECRET|KEY|TOKEN|PASSWORD|DSN|PRIVATE/i.test(name))).toEqual([])
  })

  it('پیشوندِ Vite عوض نشده و process.env به باندل تزریق نمی‌شود', () => {
    const config = readFileSync(join(ROOT, 'vite.config.js'), 'utf8')

    expect(config).not.toMatch(/envPrefix/)
    expect(config).not.toMatch(/process\.env/)
  })
})
