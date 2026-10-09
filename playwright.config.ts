import { defineConfig, devices } from '@playwright/test'

/**
 * تستِ End-to-End روی سرورِ واقعیِ لاراول.
 *
 * برخلاف تست‌های Vitest که در jsdom اجرا می‌شوند، اینجا مرورگرِ واقعی به سرورِ
 * واقعی وصل می‌شود؛ پس مرزِ MPA/SPA، نشستِ کوکی، ریدایرکتِ سمت سرور و
 * دارایی‌های ساخته‌شده هم آزموده می‌شوند — چیزهایی که در jsdom قابل سنجش نیستند.
 *
 * `webServer` خودش `php artisan serve` را بالا می‌آورد و در پایان می‌بندد.
 * دارایی‌ها باید از پیش با `npm run build` ساخته شده باشند.
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  /*
   * 🔴 تلاشِ دوم شکستِ اول را **پنهان نمی‌کند** (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶؛
   * تصمیمِ کارفرما: «خطای اجرای اول را پنهان نکن»). تکرار فقط مدرک می‌سازد —
   * قطعی است یا نه، با ردِ ضبط‌شده‌ی اولین شکست — ولی کارِ CI با تستِ
   * ناپایدار قرمز می‌شود، نه سبز.
   */
  failOnFlakyTests: !!process.env.CI,
  workers: 1,
  reporter: process.env.CI ? 'github' : [['list']],

  use: {
    baseURL: 'http://127.0.0.1:8001',
    /*
     * 🔴 ردِ **همان تلاشِ شکست‌خورده**، نه تلاشِ دوباره (مرحلهٔ ۹ · فاز ۶).
     *
     * `on-first-retry` فقط تلاشِ دوم را ضبط می‌کرد — که معمولاً سبز است — و
     * گزارش هم فقط با شکستِ کار بالا می‌رفت. یعنی هر تستِ «ناپایدار» (شکست،
     * سپس سبز) هیچ مدرکی به جا نمی‌گذاشت و ریشه‌اش هرگز پیدا نمی‌شد. حالا ردِ
     * اولین شکست می‌ماند (سبزها ردی ندارند) و CI آن را بالا می‌برد.
     */
    trace: 'retain-on-first-failure',
    locale: 'fa-IR',
  },

  /*
   * ─── سه موتور، نه سه مرورگر (R70) ────────────────────────────────────────
   * ⚠️ این تفکیک مهم است و در گزارش هم صریح آمده: Playwright سه **موتورِ
   * رندر** را اجرا می‌کند، نه سه محصولِ تجاری را.
   *
   *   chromium → همان موتورِ Chrome و Edge
   *   firefox  → همان موتورِ Firefox
   *   webkit   → موتورِ Safari، **ولی خودِ Safari نیست**
   *
   * 🔴 WebKitِ Playwright بیلدِ خودِ موتور است، نه Safariِ اپل. تفاوت‌های
   * موتور (CSS، جاوااسکریپت، طرح‌بندی) را می‌گیرد؛ رفتارهای مخصوصِ Safari
   * مثل ITP و کاستی‌های iOS را نه. آن‌ها NOT VERIFIED می‌مانند.
   */
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },

    /*
     * ─── رزولوشن‌ها ──────────────────────────────────────────────────────
     * ⚠️ فقط عرضِ پنجره عوض نمی‌شود: پروفایلِ موبایل عاملِ کاربر، نسبتِ
     * پیکسل و **رویدادِ لمسی** را هم می‌آورد. صفحه‌ای که با ماوس کار
     * می‌کند و با انگشت نه، با تغییرِ صرفِ عرض سالم به نظر می‌رسد.
     */
    { name: 'موبایل', use: { ...devices['Pixel 7'] }, testMatch: /responsive\.spec\.ts/ },
    { name: 'موبایلِ iOS', use: { ...devices['iPhone 13'] }, testMatch: /responsive\.spec\.ts/ },
    { name: 'تبلت', use: { ...devices['iPad (gen 7)'] }, testMatch: /responsive\.spec\.ts/ },
    {
      name: 'لپ‌تاپ',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 768 } },
      testMatch: /responsive\.spec\.ts/,
    },
  ],

  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8001',
    url: 'http://127.0.0.1:8001',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
})
