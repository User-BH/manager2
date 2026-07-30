import js from '@eslint/js'
import prettier from 'eslint-config-prettier'
import a11y from 'eslint-plugin-jsx-a11y'
import reactHooks from 'eslint-plugin-react-hooks'
import globals from 'globals'
import tseslint from 'typescript-eslint'

/**
 * ESLint با آگاهی از تایپ (type-aware).
 *
 * پروژه روی **TypeScript 6** است و این یک انتخابِ آگاهانه است، نه عقب‌ماندگی:
 * `typescript-eslint` روی TS 7 اجرا نمی‌شود و صریحاً خطا می‌دهد
 * ([#10940](https://github.com/typescript-eslint/typescript-eslint/issues/10940)).
 * می‌شد TS 6 و TS 7 را کنارِ هم نصب کرد (راه‌حلِ رسمیِ تیمِ TypeScript)، ولی
 * آن‌وقت ESLint و ادیتور با یک کامپایلر فکر می‌کردند و `npm run typecheck` با
 * کامپایلری دیگر — یعنی دو منبعِ حقیقت برای تایپ‌ها.
 *
 * با تک‌کامپایلر، هرچه اینجا می‌بینید دقیقاً همان چیزی است که `tsc` هم می‌بیند.
 * هزینه‌اش اندازه‌گیری شده: typecheck از ۱٫۹ به ۶٫۳ ثانیه می‌رود. بیلد اصلاً از
 * `tsc` استفاده نمی‌کند (Vite با oxc ترجمه می‌کند)، پس این فقط روی دروازه‌ی
 * pre-commit اثر دارد.
 *
 * با پشتیبانی typescript-eslint از TS ≥ 7.1، ارتقا یک خط در `package.json` است.
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
