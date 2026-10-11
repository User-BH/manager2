{{--
  صفحه‌ی «پیدا نشد» برای مسیرهای سرورساید (مرحلهٔ ۷ · فاز ۱).

  ─── 🔴 چه چیزی خراب بود ─────────────────────────────────────────────────
  آدرسِ ناشناخته‌ی بلاگ (و هر ۴۰۴ِ دیگری که سرور می‌دهد) قالبِ پیش‌فرضِ
  لاراول را نشان می‌داد: انگلیسی، چپ‌چین، با عنوانِ «Not Found» و بی‌هیچ
  راهِ خروجی.

  ─── تاریخچه‌ی مرورگر ─────────────────────────────────────────────────────
  این یک سندِ واقعی است، پس «بازگشتِ» مرورگر خودبه‌خود کاربر را به صفحه‌ی
  پیشین می‌برد و دستکاری‌ای لازم نیست. پیوندها `href`ِ ساده‌اند: کاربر
  آگاهانه به صفحه‌ی تازه می‌رود و این ۴۰۴ در تاریخچه می‌ماند — همان رفتاری
  که از هر سایتی انتظار می‌رود.

  ⚠️ مثلِ ۵۰۰، همه‌چیز درون‌خطی است و `@vite` ندارد: ۴۰۴ ممکن است برای خودِ
  یک داراییِ ساخته‌شده (`/build/...`) رندر شود.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>صفحه پیدا نشد — ساکنا</title>
    @include('partials.theme-init')
    <style>
        :root { color-scheme: light; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #f6f7f6;
            color: #1d2421;
            font-family: Vazirmatn, 'Segoe UI', Tahoma, sans-serif;
            line-height: 1.9;
        }

        .card {
            width: 100%;
            max-width: 30rem;
            background: #fff;
            border: 1px solid #e3e6e3;
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            text-align: center;
        }

        .code {
            margin: 0 0 .5rem;
            font-size: 3rem;
            font-weight: 800;
            line-height: 1.2;
            color: #0f6e56;
        }

        h1 { margin: 0 0 .75rem; font-size: 1.35rem; }

        p { margin: 0 0 1.5rem; font-size: .95rem; color: #5f6a64; }

        nav { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; }

        nav a {
            display: inline-block;
            padding: .65rem 1.25rem;
            border-radius: .75rem;
            border: 1px solid #d3d8d4;
            color: #1d2421;
            font-weight: 600;
            text-decoration: none;
        }

        nav a.primary { background: #0f6e56; border-color: #0f6e56; color: #fff; }

        nav a:focus-visible { outline: 2px solid #0f6e56; outline-offset: 2px; }

        .dark { color-scheme: dark; }
        .dark body { background: #0f1411; color: #eef2ef; }
        .dark .card { background: #161c19; border-color: #28332d; }
        .dark .code { color: #4ec49b; }
        .dark p { color: #a9b3ad; }
        .dark nav a { border-color: #344038; color: #eef2ef; }
        .dark nav a.primary { background: #2a9e7c; border-color: #2a9e7c; color: #fff; }
        .dark nav a:focus-visible { outline-color: #2a9e7c; }
    </style>
</head>
<body>
    <main class="card">
        <p class="code" aria-hidden="true">۴۰۴</p>

        <h1>این صفحه پیدا نشد</h1>

        <p>
            نشانی‌ای که باز کرده‌اید وجود ندارد یا جابه‌جا شده است.
        </p>

        <nav aria-label="راه‌های بازگشت">
            <a class="primary" href="/">صفحه‌ی اصلی</a>
            @if (request()->is('blog', 'blog/*'))
                <a href="/blog">همه‌ی مقاله‌ها</a>
            @endif
        </nav>
    </main>
    @include('partials.preview-banner')
</body>
</html>
