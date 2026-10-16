import { expect, test, type Browser, type Page } from '@playwright/test'

/**
 * زمینه مالِ نشست است — مرحلهٔ ۱۲ · فاز ۱ (بندهای ۴ و ۱۸ ِ سندِ هسته)، در مرورگرِ واقعی.
 *
 * یک حساب روی دو «دستگاه» (دو context ِ جدای مرورگر): لپ‌تاپ از «آفتاب» (مستأجر) به «مجتمع دوم سرتاسری» (مدیر) می‌رود؛
 * گوشی در آفتاب و مستأجر می‌ماند، ناوبریِ درونِ برنامه‌اش همان زمینه را می‌گیرد، و هیچ بارگذاریِ دوباره‌ای رخ نمی‌دهد
 * (سربرگِ `X-Sakena-Context` با رابطش می‌خواند). پیش از ۱۲/۱ زمینه روی ردیفِ حساب بود و گوشی هم جابه‌جا می‌شد.
 *
 * پیش‌نیاز: `DemoSeeder`، `E2eSeeder` (`TWO_DEVICES`، یکی برای هر موتور) و `otp_disabled`.
 */

const TWO_DEVICES: Record<string, string> = {
  chromium: '09120000065',
  firefox: '09120000066',
  webkit: '09120000067',
}
const SECOND = 'مجتمع دوم سرتاسری'

interface Me {
  role: string
  dashboard?: string
  complex: { id: number; name: string } | null
  memberships: { complexId: number; complexName: string }[]
}

async function signedIn(browser: Browser, phone: string): Promise<Page> {
  const page = await (await browser.newContext()).newPage()
  await page.goto('/auth')
  const status = await page.evaluate(
    async (creds) => {
      await fetch('/api/csrf-token', { credentials: 'include' })
      const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
      const res = await fetch('/api/login', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': xsrf,
        },
        body: JSON.stringify(creds),
      })
      return res.status
    },
    { phone, password: 'password' },
  )
  expect(status).toBe(200)
  return page
}

async function me(page: Page): Promise<{ user: Me; context: string | null }> {
  return page.evaluate(async () => {
    const res = await fetch('/api/v1/me', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return {
      user: ((await res.json()) as { user: Me }).user,
      context: res.headers.get('X-Sakena-Context'),
    }
  })
}

async function switchTo(page: Page, complexId: number): Promise<number> {
  return page.evaluate(async (id) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
    const res = await fetch('/api/v1/account/context', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': xsrf,
      },
      body: JSON.stringify({ complex_id: id }),
    })
    return res.status
  }, complexId)
}

test('تعویضِ مجتمع روی یک دستگاه دستگاهِ دیگرِ همان حساب را جابه‌جا نمی‌کند', async ({
  browser,
}, testInfo) => {
  test.setTimeout(90_000)
  const phoneNumber = TWO_DEVICES[testInfo.project.name]
  test.skip(phoneNumber === undefined, 'حسابِ دودستگاهی فقط برای سه موتورِ دسکتاپ ساخته شده است.')

  // ── گوشی: آفتاب، مستأجر ──
  const phone = await signedIn(browser, phoneNumber)
  let reloads = 0
  phone.on('load', () => reloads++)
  await phone.goto('/dashboard')
  await expect(phone.locator('#main-content')).toBeVisible()
  const start = await me(phone)
  expect(start.user.role).toBe('tenant')
  expect(start.user.complex?.name).not.toBe(SECOND)
  const home = start.user.complex?.id ?? 0
  expect(start.context).toBe(`${home}:tenant`)
  const second = start.user.memberships.find((m) => m.complexName === SECOND)?.complexId
  expect(second, 'عضویتِ مجتمعِ دوم در فهرست نیست.').toBeDefined()

  // ── لپ‌تاپ: همان حساب، به مجتمعِ دوم ──
  const laptop = await signedIn(browser, phoneNumber)
  await laptop.goto('/dashboard')
  expect(await switchTo(laptop, second!)).toBe(200)
  /*
   * تعویض این‌جا با fetch ِ خام است، نه از رابط؛ پس رابطِ لپ‌تاپ هنوز آفتاب را نشان می‌دهد و اولین پاسخِ برنامه با
   * سربرگِ زمینه‌ی تازه آن را خودش از نو بار می‌کند (همان محافظ). ورودِ صریح، تا آزمون با آن مسابقه ندهد.
   */
  await laptop.goto('/dashboard')
  await laptop.waitForLoadState('networkidle')
  const moved = await me(laptop)
  expect([moved.user.complex?.id, moved.user.role, moved.user.dashboard]).toEqual([
    second,
    'complex_admin',
    'manager',
  ])

  // ── گوشی هنوز آفتاب، و ناوبریِ درونِ برنامه هم ──
  const loadsBefore = reloads
  await phone.getByRole('link', { name: 'اطلاعیه‌ها' }).first().click()
  await expect(phone).toHaveURL(/\/announcements$/)
  await phone.waitForLoadState('networkidle')
  const after = await me(phone)
  expect([after.user.complex?.id, after.user.role, after.context]).toEqual([
    home,
    'tenant',
    `${home}:tenant`,
  ])
  expect(reloads, 'گوشی بی‌دلیل از نو بار شد.').toBe(loadsBefore)

  // تکرارپذیری: پیش‌فرضِ حساب به آفتاب برمی‌گردد
  expect(await switchTo(laptop, home)).toBe(200)
  await phone.context().close()
  await laptop.context().close()
})
