{{--
  صفحه‌ی حالتِ تعمیر (R43).

  ─── چرا این فایل لازم بود ─────────────────────────────────────────────────
  ⚠️ `php artisan down` — که اسکریپتِ استقرارِ R42 در هر بار اجرا صدایش
  می‌زند — صفحه‌ی پیش‌فرضِ لاراول را نشان می‌داد: انگلیسی، چپ‌چین، و با متنِ
  «Service Unavailable». ساکنی که وسطِ استقرار سر می‌زند، یک صفحه‌ی خطای
  انگلیسی می‌بیند و نتیجه می‌گیرد سامانه خراب شده.

  ─── چرا همه‌چیز درون‌خطی است ────────────────────────────────────────────────
  ⚠️ این صفحه دقیقاً وقتی نشان داده می‌شود که برنامه بالا **نیست**. هر
  `asset()`، هر فونت و هر تصویری که از سرور بیاید، همان‌جا شکست می‌خورد و
  صفحه‌ی خرابی را خراب‌تر نشان می‌دهد. پس نه CSSِ بیرونی، نه فونتِ بیرونی،
  نه هیچ درخواستِ شبکه‌ای.

  ⚠️ رفرشِ خودکار هم عمداً نیست: کاربری که این صفحه را باز گذاشته، با رفرشِ
  هر چند ثانیه به سروری فشار می‌آورد که همین حالا هم در حالِ مهاجرت است.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>به‌روزرسانی ساکنا</title>
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
            background: #0ea5e9;
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
        <div class="mark" aria-hidden="true">&#9881;</div>

        <h1>ساکنا در حالِ به‌روزرسانی است</h1>

        <p>
            چند دقیقه‌ای طول می‌کشد. اطلاعاتِ شما دست‌نخورده است و
            هیچ پرداخت یا پیامی از بین نمی‌رود.
        </p>

        <p>لطفاً کمی بعد دوباره همین صفحه را باز کنید.</p>

        <p class="note">
            اگر بیش از نیم ساعت طول کشید، با مدیرِ مجتمعِ خود تماس بگیرید.
        </p>
    </main>
</body>
</html>
