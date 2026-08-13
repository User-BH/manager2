import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [
    laravel({
      /*
       * چند entry:
       *  - main.tsx: داشبوردِ SPA (روت‌های محافظت‌شده با react-router).
       *  - entries/{home,demo,support,auth}.tsx: صفحه‌های عمومیِ MPA؛ هر
       *    کدام یک island است که لاراول به‌صورت یک سندِ HTMLِ مستقل سرو
       *    می‌کند. جداکردنِ entryها یعنی هر صفحه فقط کدِ خودش را می‌گیرد و
       *    خزنده‌ها/هوش‌مصنوعی‌ها HTMLِ واقعیِ همان صفحه را با هدرِ مخصوصش می‌بینند.
       */
      input: [
        'resources/css/app.css',
        'resources/js/app/main.tsx',
        'resources/js/app/entries/home.tsx',
        'resources/js/app/entries/demo.tsx',
        'resources/js/app/entries/support.tsx',
        'resources/js/app/entries/auth.tsx',
      ],
      refresh: true,
    }),
    react({
      /*
       * React Compiler: همه‌ی کامپوننت‌ها به‌صورت خودکار memoize می‌شوند
       * (بدون useMemo/useCallback/React.memoِ دستی). کامپایلر هر جا مطمئن
       * نباشد بی‌صدا از خیرِ همان کامپوننت می‌گذرد، پس امن است.
       */
      babel: {
        plugins: [['babel-plugin-react-compiler', { target: '19' }]],
      },
    }),
    tailwindcss(),
  ],
  resolve: {
    // `@/...` imports come straight from the original React project.
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  build: {
    rollupOptions: {
      output: {
        /*
         * تفکیک کتابخانه‌های بزرگ از کد اپلیکیشن.
         *
         * کد ما هر استقرار عوض می‌شود ولی این‌ها ماه‌ها ثابت می‌مانند؛
         * جدا کردنشان یعنی کاربر بازگشتی فقط چانک تغییرکرده را دانلود
         * می‌کند نه ۶۵۰ کیلوبایت کامل را.
         */
        // rolldown فقط شکل تابعی را می‌پذیرد، نه نگاشت شیء‌ای
        manualChunks(id) {
          if (!id.includes('node_modules')) return undefined

          if (
            /\/node_modules\/(react|react-dom|react-router|react-router-dom|scheduler)\//.test(id)
          ) {
            return 'vendor-react'
          }
          if (
            id.includes('framer-motion') ||
            id.includes('motion-dom') ||
            id.includes('motion-utils')
          ) {
            return 'vendor-motion'
          }
          if (id.includes('swiper')) return 'vendor-swiper'

          /*
           * آیکون‌ها یک چانک، نه هفتاد تا (R36).
           *
           * ⚠️ tree-shaking اینجا **خیلی خوب** کار می‌کرد و همان مشکل بود:
           * هر آیکونِ lucide چانکِ خودش می‌شد و بیلد ۷۰ فایلِ زیرِ دو
           * کیلوبایت تولید می‌کرد با مجموعِ ‎۲۲٫۵KB — یعنی میانگینِ ۳۲۲ بایت
           * برای هر درخواستِ HTTP. سربارِ هدر و رفت‌وبرگشت از خودِ محتوا
           * بیشتر می‌شد.
           *
           * یک چانکِ ‎۲۲KB (‎~۸KB فشرده) که بین همه‌ی صفحه‌ها **مشترک** است و
           * یک بار کش می‌شود، از هفتاد درخواست بهتر است.
           */
          if (id.includes('lucide-react')) return 'vendor-icons'

          /*
           * zod و react-hook-form عمداً اینجا نیستند.
           *
           * جمع‌کردنشان در یک چانکِ نام‌دار، همان چانک را به وابستگی
           * ثابتِ main تبدیل می‌کرد و صفحه‌ی فرود — که هیچ فرمی ندارد —
           * صد کیلوبایت اعتبارسنجی فرم دانلود می‌کرد. رهاکردنشان به
           * عهده‌ی باندلر، خودش آن‌ها را در چانک صفحاتی می‌گذارد که
           * واقعاً استفاده‌شان می‌کنند.
           */

          return undefined
        },
      },
    },
  },

  server: {
    watch: {
      ignored: ['**/storage/framework/views/**'],
    },
  },
})
