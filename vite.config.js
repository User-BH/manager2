import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            // Single entry: the SPA. The Blade panel and its Chart.js bundle
            // are gone now that every screen exists in React.
            input: ['resources/css/app.css', 'resources/js/main.tsx'],
            refresh: true,
        }),
        react(),
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
                    if (!id.includes('node_modules')) return undefined;

                    if (/[\/]node_modules[\/](react|react-dom|react-router|react-router-dom|scheduler)[\/]/.test(id)) {
                        return 'vendor-react';
                    }
                    if (id.includes('framer-motion') || id.includes('motion-dom') || id.includes('motion-utils')) {
                        return 'vendor-motion';
                    }
                    if (id.includes('swiper')) return 'vendor-swiper';

                    /*
                     * zod و react-hook-form عمداً اینجا نیستند.
                     *
                     * جمع‌کردنشان در یک چانکِ نام‌دار، همان چانک را به وابستگی
                     * ثابتِ main تبدیل می‌کرد و صفحه‌ی فرود — که هیچ فرمی ندارد —
                     * صد کیلوبایت اعتبارسنجی فرم دانلود می‌کرد. رهاکردنشان به
                     * عهده‌ی باندلر، خودش آن‌ها را در چانک صفحاتی می‌گذارد که
                     * واقعاً استفاده‌شان می‌کنند.
                     */

                    return undefined;
                },
            },
        },
    },

    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
