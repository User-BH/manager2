import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import { fileURLToPath, URL } from 'node:url'

/**
 * پیکربندی تست فرانت‌اند.
 *
 * عمداً از `vite.config.js` جداست: آن فایل افزونه‌ی laravel-vite-plugin و
 * tailwind را دارد که برای تست لازم نیستند (و افزونه‌ی لاراول انتظار دارد در
 * بسترِ ساخت دارایی اجرا شود). اینجا فقط چیزی می‌آید که برای رندرِ کامپوننت
 * لازم است.
 *
 * افزونه‌ی React هم بدون React Compiler می‌آید؛ کامپایلر یک بهینه‌سازیِ زمانِ
 * بیلد است و در تست فقط اجرا را کند می‌کند بی‌آنکه رفتار را عوض کند.
 */
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/js/setup.ts'],
    include: ['tests/js/**/*.test.{ts,tsx}'],
    // خروجی خلاصه و قابل‌خواندن در ترمینال
    reporters: ['default'],
    restoreMocks: true,
  },
})
