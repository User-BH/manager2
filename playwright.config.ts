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
  workers: 1,
  reporter: process.env.CI ? 'github' : [['list']],

  use: {
    baseURL: 'http://127.0.0.1:8001',
    trace: 'on-first-retry',
    locale: 'fa-IR',
  },

  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],

  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8001',
    url: 'http://127.0.0.1:8001',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
})
