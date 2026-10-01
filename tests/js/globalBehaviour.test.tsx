import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useNavigationType } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * رفتارهای سراسری (مرحلهٔ ۷ · فاز ۱): تم، فیلدهای فرم، تاریخچه‌ی مرورگر.
 *
 * هر سه پیش از این **خطا نمی‌دادند** — تمِ تیره فقط «اشتباه» بود، فیلد فقط
 * «ضخیم» بود و «بازگشت» فقط کاربر را به فرمِ ورود می‌برد. چیزی که خطا نمی‌دهد
 * بدونِ تست بی‌صدا برمی‌گردد.
 */

const authState = { isAuthenticated: false }
vi.mock('@/shared/stores/authStore', () => ({
  useAuth: () => ({ ...authState, isLoading: false, user: null }),
}))

function codeOf(path: string): string {
  return readFileSync(path, 'utf8')
}

/** تگِ آغازینِ JSX تا `>`ِ خودش — `>`ِ درونِ `{...}` (مثلِ `=>`) را رد می‌کند. */
function openingTag(code: string, start: number): string {
  let depth = 0
  for (let i = start; i < code.length; i++) {
    const char = code[i]
    if (char === '{') depth++
    else if (char === '}') depth--
    else if (char === '>' && depth === 0) return code.slice(start, i + 1)
  }
  return code.slice(start)
}

function filesUnder(dir: string, ext: RegExp): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? filesUnder(path, ext) : ext.test(name) ? [path] : []
  })
}

// ─── تم ───────────────────────────────────────────────────────────────────

describe('تمِ پیش‌فرض', () => {
  beforeEach(() => {
    vi.resetModules()
    localStorage.clear()
    document.documentElement.classList.remove('dark')
    document.head.innerHTML = '<meta name="theme-color" content="#0f6e56">'
    // 🔴 سیستم‌عاملِ تیره: همان حالتی که پیش از این تیره را تحمیل می‌کرد
    vi.stubGlobal(
      'matchMedia',
      vi.fn((query: string) => ({
        matches: query.includes('dark'),
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      })),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  async function loadStore() {
    return import('@/shared/stores/themeStore')
  }

  it('بی‌انتخابِ کاربر روشن است، حتی با سیستم‌عاملِ تیره', async () => {
    const { useThemeStore } = await loadStore()

    expect(useThemeStore.getState().theme).toBe('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it('حدس ذخیره نمی‌شود؛ فقط انتخابِ کاربر', async () => {
    const { useThemeStore, THEME_KEY } = await loadStore()
    expect(localStorage.getItem(THEME_KEY)).toBeNull()

    useThemeStore.getState().toggleTheme()

    expect(localStorage.getItem(THEME_KEY)).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.querySelector('meta[name="theme-color"]')?.getAttribute('content')).toBe(
      '#0f1411',
    )
  })

  it('انتخابِ ذخیره‌شده در بازدیدِ بعدی می‌ماند', async () => {
    localStorage.setItem('sakena:theme', 'dark')
    const { useThemeStore } = await loadStore()

    expect(useThemeStore.getState().theme).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })

  it('کلیدِ قدیمِ آلوده نادیده گرفته و پاک می‌شود', async () => {
    // مقدارِ کلیدِ قدیم را خودِ کد از سیستم‌عامل حدس زده و نوشته بود
    localStorage.setItem('theme', 'dark')
    const { useThemeStore } = await loadStore()

    expect(useThemeStore.getState().theme).toBe('light')
    expect(localStorage.getItem('theme')).toBeNull()
  })

  it('اسکریپتِ ضدِپرشِ <head> همان کلید و همان قاعده را دارد', async () => {
    const { THEME_KEY } = await loadStore()
    const init = codeOf('resources/views/partials/theme-init.blade.php')

    expect(init).toContain(`localStorage.getItem('${THEME_KEY}') === 'dark'`)
    expect(init).not.toMatch(/matchMedia|prefers-color-scheme/)
  })

  it('هیچ قالبی تم را از سیستم‌عامل نمی‌گیرد و همه از یک اسکریپت می‌خوانند', () => {
    const views = filesUnder('resources/views', /\.blade\.php$/)

    const followOs = views.filter((path) => /prefers-color-scheme/.test(codeOf(path)))
    expect(followOs).toEqual([])

    // ⚠️ نسخه‌ی تکراری همان باگ بود: سه پوسته، سه اسکریپتِ جدا
    const ownScript = views.filter(
      (path) =>
        !path.endsWith('theme-init.blade.php') && /localStorage\.getItem\(/.test(codeOf(path)),
    )
    expect(ownScript).toEqual([])
  })
})

// ─── فیلدهای فرم ───────────────────────────────────────────────────────────

describe('فیلدِ فرم', () => {
  it('خطا و «معتبر» هرگز با هم نمی‌نشینند — خطا برنده است', async () => {
    const { TextField } = await import('@/shared/ui/Field')
    render(<TextField label="نام" error="لازم است" valid />)

    const input = screen.getByLabelText('نام')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).not.toHaveAttribute('data-valid')
  })

  it('معتبر سبز است و خنثی هیچ‌کدام', async () => {
    const { TextField } = await import('@/shared/ui/Field')
    render(
      <>
        <TextField label="معتبر" valid />
        <TextField label="خنثی" />
      </>,
    )

    expect(screen.getByLabelText('معتبر')).toHaveAttribute('data-valid', 'true')
    expect(screen.getByLabelText('خنثی')).not.toHaveAttribute('data-valid')
    expect(screen.getByLabelText('خنثی')).not.toHaveAttribute('aria-invalid')
  })

  /**
   * ⚠️ قراردادِ سراسری: هر فیلدِ متنیِ پروژه کلاسِ `field` دارد و رنگ یا
   * حلقه‌ی خودش را نمی‌سازد. پیش از این بیش از بیست ورودی هرکدام نسخه‌ی خودشان
   * را داشتند و یک فرم‌ِ تازه با `focus:ring-2` همان حاشیه‌ی ضخیم را برمی‌گرداند.
   */
  it('هر ورودی، انتخاب‌گر و متنِ چندخطی از `.field` می‌آید', () => {
    // ورودی‌های بی‌حاشیه‌ای که قابِ بیرونی‌شان حالت را نشان می‌دهد
    const exempt: Record<string, string> = {
      'features/tools/calculator/Display.tsx': 'نمایشگرِ ماشین‌حساب، نه فیلدِ فرم',
      'features/messaging/messenger/ThreadSearch.tsx': 'قاب `.field` دارد (`:focus-within`)',
    }
    const skipTypes = /type="(hidden|checkbox|radio|range|file)"/

    const offenders: string[] = []
    for (const path of filesUnder('resources/js', /\.tsx$/)) {
      const rel = path.replace(/\\/g, '/').replace('resources/js/', '')
      if (rel in exempt) continue
      const code = codeOf(path)

      for (const match of code.matchAll(/<(input|select|textarea)\b/g)) {
        const tag = openingTag(code, match.index)
        // `<input>`ِ بی‌صفت فقط در توضیحِ کد پیدا می‌شود
        if (!/\s\w/.test(tag.slice(match[0].length))) continue
        const usesField = /className=(?:"[^"]*\bfield\b|\{[^}]*['"`]field\b)/.test(tag)
        // چک‌باکس، فایلِ پنهان و… فیلدِ متنی نیستند — مگر خودشان `.field` گرفته باشند
        if (skipTypes.test(tag) && !usesField) continue
        if (/className=\{baseInput\}|className=\{`\$\{baseInput\}/.test(tag)) continue
        if (!usesField || /focus:ring|--tw-ring-color|borderColor/.test(tag)) {
          offenders.push(`${rel}: ${tag.slice(0, 60)}`)
        }
      }
    }

    expect(offenders).toEqual([])
  })

  it('CSS: فوکوسِ سراسری زیرِ لایه است و فیلدِ نامعتبر فقط قرمز می‌شود', () => {
    const css = codeOf('resources/css/app.css')

    // 🔴 بیرون از لایه، `border-radius: 4px`ش گوشه‌ی گردِ هر فیلد و دکمه را می‌خورد
    expect(css).toMatch(
      /@layer base \{\s*:where\(a, button, \[role='button'\], input, select, textarea, summary, \[tabindex\]\):focus-visible/,
    )

    const invalidFocus =
      css.match(/\.field\[aria-invalid='true'\]:focus[^{]*\{([^}]*)\}/)?.[1] ?? ''
    expect(invalidFocus).toContain('var(--color-danger)')
    expect(invalidFocus).not.toContain('--ring-focus')

    const validFocus = css.match(/\.field\[data-valid='true'\]:focus[^{]*\{([^}]*)\}/)?.[1] ?? ''
    expect(validFocus).toContain('var(--color-success)')
  })
})

// ─── تاریخچه‌ی مرورگر ───────────────────────────────────────────────────────

describe('تاریخچه پس از ورود', () => {
  /**
   * ⚠️ رفتنِ واقعی به داشبورد در jsdom سنجیدنی نیست (ناوبریِ سند)؛ آن را
   * آزمونِ مرورگرِ `critical-flows` با «بازگشت» می‌سنجد. اینجا فقط قفل
   * می‌شود که هیچ گامی از جریانِ ورود دوباره `assign` نشود.
   */
  it('هیچ گامی از جریانِ ورود به داشبورد `assign` نمی‌کند', () => {
    const auth = filesUnder('resources/js/features/auth', /\.tsx?$/)

    expect(auth.filter((path) => /location\.assign\(/.test(codeOf(path)))).toEqual([])

    for (const file of [
      'components/LoginForm.tsx',
      'VerifyOtpPage.tsx',
      'ForgotPasswordPage.tsx',
    ]) {
      expect(codeOf(`resources/js/features/auth/${file}`), file).toContain(
        "window.location.replace('/dashboard')",
      )
    }
  })

  it('داشبورد با روترِ خودش به `/auth` نمی‌رود — آن سندِ جداست', () => {
    /*
     * 🔴 بازیابیِ پشتیبان پس از بستنِ نشست `navigate('/auth')` می‌کرد؛ `/auth`
     * روتِ داشبورد نیست و کاربر صفحه‌ی «پیدا نشد» می‌دید. راهِ درست
     * `leaveToAuth()` است: ناوبریِ واقعیِ مرورگر با `replace`.
     */
    const outsideAuth = filesUnder('resources/js', /\.tsx?$/).filter(
      (path) => !/[\\/]features[\\/]auth[\\/]/.test(path),
    )

    expect(outsideAuth.filter((path) => /navigate\(\s*['"`]\/auth/.test(codeOf(path)))).toEqual([])
  })

  it('پیوندهای میانِ گام‌های ورود هم جایگزین می‌کنند', () => {
    expect(codeOf('resources/js/features/auth/components/AuthScreen.tsx')).toMatch(
      /to=\{backTo\}[\s\S]{0,120}\breplace\b/,
    )
    expect(codeOf('resources/js/features/auth/components/LoginForm.tsx')).toMatch(
      /to="\/auth\/forgot"[\s\S]{0,120}\breplace\b/,
    )
  })
})

describe('صفحه‌ی «پیدا نشد» داشبورد', () => {
  function NavigationType() {
    return <p>نوعِ ناوبری: {useNavigationType()}</p>
  }

  async function renderAt(entries: string[]) {
    const { NotFoundPage } = await import('@/features/error/NotFoundPage')
    return render(
      <MemoryRouter initialEntries={entries} initialIndex={entries.length - 1}>
        <Routes>
          <Route path="/dashboard" element={<NavigationType />} />
          <Route path="/units" element={<p>واحدها</p>} />
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </MemoryRouter>,
    )
  }

  beforeEach(() => {
    authState.isAuthenticated = false
  })

  it('۴۰۴ است، نه ۴۰۳', async () => {
    await renderAt(['/no-such-page'])

    expect(screen.getByRole('heading', { name: 'این صفحه پیدا نشد' })).toBeInTheDocument()
    expect(screen.queryByText(/دسترسی/)).toBeNull()
  })

  it('با آدرسِ مستقیم «صفحه‌ی قبل» ندارد — قبلِ داخلی‌ای نیست', async () => {
    await renderAt(['/no-such-page'])

    expect(screen.queryByRole('button', { name: 'صفحه‌ی قبل' })).toBeNull()
  })

  it('با ناوبریِ داخلی «صفحه‌ی قبل» همان صفحه را برمی‌گرداند', async () => {
    const user = userEvent.setup()
    await renderAt(['/units', '/no-such-page'])

    await user.click(screen.getByRole('button', { name: 'صفحه‌ی قبل' }))

    expect(await screen.findByText('واحدها')).toBeInTheDocument()
  })

  it('رفتن به داشبورد این بن‌بست را جایگزین می‌کند', async () => {
    authState.isAuthenticated = true
    const user = userEvent.setup()
    await renderAt(['/units', '/no-such-page'])

    await user.click(screen.getByRole('button', { name: 'داشبورد' }))

    expect(await screen.findByText('نوعِ ناوبری: REPLACE')).toBeInTheDocument()
  })
})
