import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            // main.tsx is the SPA entry. js-legacy/app.js still backs the old
            // Blade screens under /legacy until every page exists in React.
            input: [
                'resources/css/app.css',
                'resources/js/main.tsx',
                'resources/js-legacy/app.js',
            ],
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
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
