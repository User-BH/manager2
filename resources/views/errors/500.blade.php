{{--
  صفحه‌ی خطای سرور (R47).

  ─── چرا این فایل لازم بود ─────────────────────────────────────────────────
  ⚠️ R43 صفحه‌ی حالتِ تعمیر (۵۰۳) را فارسی کرد، ولی خطای **غیرمنتظره**
  همچنان قالبِ پیش‌فرضِ لاراول را نشان می‌داد: انگلیسی، چپ‌چین، با عنوانِ
  «Server Error».

  اندازه‌گیری شد: با دیتابیسِ از دسترس خارج، صفحه‌ی فرود ۵۰۰ می‌دهد و
  کاربر همان صفحه‌ی انگلیسی را می‌بیند. نشتی در کار نیست (محافظِ R44
  `APP_DEBUG` را در محصول خاموش می‌کند) ولی ساکن نمی‌فهمد چه شده.

  ─── چرا صفحه‌ی فرود اصلاً به دیتابیس نیاز دارد ─────────────────────────────
  ⚠️ `csrf_token()` و `@auth` در چیدمان، نشست را باز می‌کنند و نشستِ این
  پروژه روی **دیتابیس** است. یعنی حتی صفحه‌ای که به نظر ایستاست هم بدونِ
  دیتابیس سرو نمی‌شود.

  این عمداً تغییر داده نشد: برداشتنِ نشست از صفحه‌ی فرود، توکنِ CSRF و
  نمایشِ وضعیتِ ورودِ کاربر را می‌شکند — یعنی قابلیتِ واقعی فدای سناریویی
  می‌شود که در آن کلِ سامانه پایین است.

  ⚠️ مثلِ ۵۰۳، همه‌چیز درون‌خطی است: این صفحه دقیقاً وقتی رندر می‌شود که
  چیزی خراب است، و هر `asset()` می‌تواند همان‌جا شکست بخورد.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>خطای سرور — ساکنا</title>
    <style>
        :root { color-scheme: light dark; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #f8fafc;
            color: #0f172a;
            font-family: Vazirmatn, 'Segoe UI', Tahoma, sans-serif;
            line-height: 1.9;
        }

        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e2e8f0; }
            .card { background: #1e293b; border-color: #334155; }
            .note { background: #0f172a; border-color: #334155; }
        }

        .card {
            width: 100%;
            max-width: 30rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            text-align: center;
        }

        .mark {
            width: 3.5rem;
            height: 3.5rem;
            margin: 0 auto 1.25rem;
            border-radius: 50%;
            background: #f43f5e;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }

        h1 { margin: 0 0 .75rem; font-size: 1.35rem; }

        p { margin: 0 0 1rem; font-size: .95rem; }

        .note {
            margin: 1.5rem 0 0;
            padding: .85rem 1rem;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: .6rem;
            font-size: .85rem;
            color: #475569;
        }

        @media (prefers-color-scheme: dark) {
            .note { color: #94a3b8; }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="mark" aria-hidden="true">&#9888;</div>

        <h1>مشکلی پیش آمد</h1>

        <p>
            خطایی در سرور رخ داده و این صفحه بارگذاری نشد. اطلاعاتِ شما
            دست‌نخورده است و هیچ پرداخت یا پیامی از بین نمی‌رود.
        </p>

        <p>لطفاً چند دقیقه‌ی دیگر دوباره تلاش کنید.</p>

        <p class="note">
            اگر باز هم تکرار شد، با مدیرِ مجتمعِ خود تماس بگیرید.
        </p>
    </main>
</body>
</html>
