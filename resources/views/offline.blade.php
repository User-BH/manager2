{{--
    صفحه‌ی آفلاین (R35).

    ─── چرا کاملاً مستقل است ──────────────────────────────────────────────
    این صفحه دقیقاً وقتی نشان داده می‌شود که شبکه‌ای در کار نیست. پس هر
    چیزی که از بیرون بخواهد — فونت، CSSِ ساخته‌شده، اسکریپت — همان لحظه
    شکست می‌خورد و کاربر یک صفحه‌ی بی‌شکل می‌بیند. به همین دلیل استایل
    درون‌خطی است و هیچ دارایی‌ای بار نمی‌شود.

    ⚠️ اینجا `@vite` نگذارید. بارگذاریِ CSSِ اصلی این صفحه را در همان
    حالتی که برایش ساخته شده بی‌استفاده می‌کند.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>اتصال اینترنت برقرار نیست | ساکنا</title>
    <meta name="theme-color" content="#0f6e56">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f6f8f7;
            --card: #ffffff;
            --text: #10201a;
            --muted: #5a6b64;
            --brand: #0f6e56;
            --border: #dbe4e0;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1411;
                --card: #16201b;
                --text: #e8f0ec;
                --muted: #9bafa7;
                --brand: #3fae8e;
                --border: #263630;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: var(--bg);
            color: var(--text);
            font-family: Vazirmatn, 'Segoe UI', Tahoma, sans-serif;
        }

        .card {
            width: 100%;
            max-width: 440px;
            padding: 32px 28px;
            border: 1px solid var(--border);
            border-radius: 20px;
            background: var(--card);
            text-align: center;
        }

        .glyph {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            color: var(--brand);
        }

        h1 { margin: 0 0 12px; font-size: 20px; }

        p { margin: 0 0 8px; color: var(--muted); font-size: 14.5px; line-height: 2; }

        ul {
            margin: 18px 0 24px;
            padding: 0 18px 0 0;
            text-align: right;
            color: var(--muted);
            font-size: 13.5px;
            line-height: 2.1;
        }

        button {
            width: 100%;
            padding: 12px 18px;
            border: 0;
            border-radius: 12px;
            background: var(--brand);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover { filter: brightness(1.08); }

        .state {
            margin-top: 14px;
            font-size: 12.5px;
            color: var(--muted);
            min-height: 18px;
        }
    </style>
</head>
<body>
    <main class="card">
        <svg class="glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" aria-hidden="true">
            <path d="M2 2l20 20"/>
            <path d="M8.5 16.5a5 5 0 0 1 7 0"/>
            <path d="M5 12.9a10 10 0 0 1 3.2-2.1"/>
            <path d="M15.5 10.7a10 10 0 0 1 3.5 2.2"/>
            <path d="M2 8.8a15 15 0 0 1 4.2-2.7"/>
            <path d="M12 5c3.4 0 6.6 1.3 9 3.5"/>
            <path d="M12 20h.01"/>
        </svg>

        <h1>اتصال اینترنت برقرار نیست</h1>

        <p>صفحه‌ای که خواستید هنوز روی این دستگاه ذخیره نشده، پس تا وصل‌شدن به اینترنت نمی‌توانیم نشانش بدهیم.</p>

        <ul>
            <li>اگر روی وای‌فای هستید، اتصال مودم را ببینید.</li>
            <li>اگر با داده‌ی همراه هستید، شاید آنتن‌دهی ضعیف باشد.</li>
            <li>صفحه‌هایی که پیش‌تر باز کرده‌اید همچنان بدون اینترنت باز می‌شوند.</li>
        </ul>

        <button type="button" id="retry">تلاش دوباره</button>

        <p class="state" id="state" role="status" aria-live="polite"></p>
    </main>

    <script>
        (function () {
            var state = document.getElementById('state')

            /*
             * `navigator.onLine` فقط می‌گوید کارتِ شبکه وصل است، نه اینکه
             * اینترنت واقعاً کار می‌کند. پس صرفاً پیام می‌دهد و خودِ تصمیم
             * با تلاشِ دوباره گرفته می‌شود.
             */
            function show() {
                state.textContent = navigator.onLine
                    ? 'اتصال برقرار شد. «تلاش دوباره» را بزنید.'
                    : 'هنوز آفلاین هستید.'
            }

            document.getElementById('retry').addEventListener('click', function () {
                location.reload()
            })

            window.addEventListener('online', show)
            window.addEventListener('offline', show)
            show()
        })()
    </script>
</body>
</html>
