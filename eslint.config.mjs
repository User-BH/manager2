import js from '@eslint/js'
import prettier from 'eslint-config-prettier'
import a11y from 'eslint-plugin-jsx-a11y'
import reactHooks from 'eslint-plugin-react-hooks'
import globals from 'globals'
import tseslint from 'typescript-eslint'

/**
 * ESLint با آگاهی از تایپ (type-aware).
 *
 * ─── چرا TypeScript دو نسخه‌ای نصب است ─────────────────────────────────────
 * `typescript-eslint` روی TypeScript 7 **اجرا نمی‌شود** و صریحاً خطا می‌دهد
 * (typescript-eslint#10940). راه‌حلِ رسمیِ خودِ تیم TypeScript نصبِ کنار-هم است
 * (اعلامیه‌ی TS 7.0، بخش «Running side-by-side with TypeScript 6.0») و در
 * `package.json` همان را داریم — با نام‌های عوض‌شده که عمدی است:
 *
 *   "typescript":          "npm:@typescript/typescript6"  →  فقط API برای لینتر
 *   "@typescript/native":  "npm:typescript@7"             →  کامپایلرِ واقعی
 *
 * چون ابزارهایی مثل typescript-eslint پکیجِ `typescript` را مستقیم import
 * می‌کنند، همان نام باید TS 6 باشد. کامپایلری که `npm run typecheck` و ادیتور
 * جدی می‌گیرند TS 7 است و باینری‌ها قاطی نمی‌شوند: TS 7 → `tsc`، TS 6 → `tsc6`.
 *
 * پس **مرجعِ درستیِ تایپ‌ها `npm run typecheck` است، نه ESLint.** اگر روزی این
 * دو اختلاف پیدا کردند، حرفِ `tsc` مقدم است. با پشتیبانی typescript-eslint از
 * TS ≥7.1، نصبِ TS 6 حذف می‌شود و این توضیح هم با آن می‌رود.
 */

export default tseslint.config(
  {
    // فایل‌های تولیدشده و وابستگی‌ها هرگز لینت نمی‌شوند
    ignores: [
      'node_modules/**',
      'vendor/**',
      'public/build/**',
      'storage/**',
      'bootstrap/cache/**',
      'coverage/**',
      'test-results/**',
      'playwright-report/**',
    ],
  },

  // ───────────────────────── کدِ اپلیکیشن (با آگاهی از تایپ) ─────────────────
  {
    files: ['resources/js/**/*.{ts,tsx}'],
    extends: [js.configs.recommended, ...tseslint.configs.recommendedTypeChecked],
    languageOptions: {
      globals: { ...globals.browser },
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    plugins: { 'react-hooks': reactHooks, 'jsx-a11y': a11y },
    rules: {
      ...reactHooks.configs['recommended-latest'].rules,
      ...a11y.flatConfigs.recommended.rules,

      /*
       * «متغیرِ استفاده‌نشده» خطا است، نه هشدار — هشدار در عمل نادیده گرفته
       * می‌شود و انباشته می‌گردد. استثنا: آرگومان‌هایی که با `_` شروع می‌شوند،
       * برای وقتی امضای تابع پارامتری را اجبار می‌کند که لازممان نیست.
       */
      '@typescript-eslint/no-unused-vars': [
        'error',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrors: 'all',
          caughtErrorsIgnorePattern: '^_',
        },
      ],

      /*
       * `any` هشدار است نه خطا: مخزن هم‌اکنون چند موردِ موجود دارد و تبدیلِ
       * یک‌باره‌ی همه‌شان به خطا یعنی یک کامیتِ غول که هیچ‌کس مرور نمی‌کند.
       * قاعده: عددش فقط پایین می‌آید (مثل baselineِ PHPStan).
       */
      '@typescript-eslint/no-explicit-any': 'warn',

      /*
       * `checksVoidReturn.attributes` خاموش است — و این تنظیمِ درست است، نه
       * سرکوبِ خطا.
       *
       * تایپ‌های خودِ React همه‌ی هندلرها (`onClick`, `onSubmit`, …) را
       * `() => void` تعریف می‌کنند، پس هر هندلرِ async در JSX خطا می‌گرفت
       * (۶۰ مورد در این مخزن). ریسکی که این قاعده از آن محافظت می‌کند
       * «rejectionِ مدیریت‌نشده» است، و در این پروژه هوکِ `useMutation` همه‌ی
       * mutationها را می‌گیرد و به توست می‌فرستد؛ یعنی ریسک ساختاراً بسته است.
       * بقیه‌ی حالت‌های قاعده (آرگومان، return، property) روشن می‌مانند.
       */
      '@typescript-eslint/no-misused-promises': [
        'error',
        { checksVoidReturn: { attributes: false } },
      ],

      /*
       * ── بدهیِ فنیِ صحتِ React (سقف‌دار، فقط کم می‌شود) ──────────────────
       *
       * این چهار قاعده مواردِ **واقعی** پیدا کرده‌اند، نه نوفه: state گذاشتن
       * داخل effect (به‌جای مشتق‌کردن)، خواندن ref در رندر، صدا زدنِ
       * `Date.now()` در رندر، و دو کامپوننت که React Compiler **از
       * کامپایل‌کردنشان صرف‌نظر کرده** (یعنی memoization‌ای که برایش هزینه
       * داده‌ایم آنجا اعمال نمی‌شود).
       *
       * `warn` است نه `error`، چون رفعِ درستشان طراحیِ دوباره‌ی هر مورد است و
       * نباید با کامیتِ ابزارِ کیفیت قاطی شود. سقف با `--max-warnings` در
       * اسکریپت `lint` قفل است، پس تعدادشان فقط می‌تواند کم شود. مرحله‌ی
       * رفعشان: R49.
       */
      'react-hooks/set-state-in-effect': 'warn',
      'react-hooks/refs': 'warn',
      'react-hooks/purity': 'warn',
      'react-hooks/incompatible-library': 'warn',

      /*
       * تعاملِ کاربر در این پروژه بارها فارسی/RTL است و متنِ دکمه‌ها گاهی فقط
       * آیکون است؛ این دو قاعده جلوی دکمه‌ی بی‌نامِ دسترس‌ناپذیر را می‌گیرند.
       */
      'jsx-a11y/alt-text': 'error',
      'jsx-a11y/anchor-is-valid': 'error',

      // `console` در کدِ محصول نمی‌ماند؛ خطاها به Sentry/توست می‌روند (R8)
      'no-console': ['error', { allow: ['warn', 'error'] }],
    },
  },

  // ───────────────────────── تست‌ها ──────────────────────────────────────────
  {
    files: ['tests/js/**/*.{ts,tsx}', 'tests/e2e/**/*.ts'],
    extends: [js.configs.recommended, ...tseslint.configs.recommendedTypeChecked],
    languageOptions: {
      globals: { ...globals.browser, ...globals.node },
      parserOptions: { projectService: true, tsconfigRootDir: import.meta.dirname },
    },
    rules: {
      /*
       * تست‌ها عمداً آزادترند: mockها `any` می‌گیرند و اثباتِ رفتارِ اشتباه
       * لازم است داده‌ی بی‌ریخت بسازد. سخت‌گیریِ کدِ محصول اینجا فقط باعث
       * می‌شود تست نوشتن سخت شود.
       */
      '@typescript-eslint/no-explicit-any': 'off',
      '@typescript-eslint/no-unsafe-assignment': 'off',
      '@typescript-eslint/no-unsafe-member-access': 'off',
      '@typescript-eslint/no-unsafe-call': 'off',
      '@typescript-eslint/no-unsafe-argument': 'off',
      '@typescript-eslint/no-unsafe-return': 'off',
      'no-console': 'off',
    },
  },

  // ───────────────────────── فایل‌های پیکربندی (بدون آگاهی از تایپ) ──────────
  {
    files: ['*.{js,mjs,cjs,ts}', 'vite.config.js', 'vitest.config.ts', 'playwright.config.ts'],
    extends: [js.configs.recommended, ...tseslint.configs.recommended],
    languageOptions: { globals: { ...globals.node } },
    rules: { 'no-console': 'off' },
  },

  // قواعدِ قالب‌بندی خاموش می‌شوند تا با Prettier نجنگند — باید آخر بماند
  prettier,
)
