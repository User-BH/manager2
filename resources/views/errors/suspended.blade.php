{{-- صفحه‌ی تعلیقِ مجتمع (R29) — بدونِ اپلیکیشنِ React، چون کاربر هرگز به داشبورد نمی‌رسد. --}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>دسترسی تعلیق شده</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #f8fafc; color: #1e293b;
               display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .box { max-width: 480px; padding: 32px; background: #fff; border-radius: 16px;
               border: 1px solid #e2e8f0; text-align: center; }
        h1 { font-size: 18px; margin: 0 0 12px; color: #b45309; }
        p { font-size: 14px; line-height: 2; color: #475569; margin: 0 0 16px; }
        a { color: #0284c7; font-size: 13px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>دسترسی تعلیق شده است</h1>
        <p>{{ $message }}</p>
        <a href="{{ route('support') }}">تماس با پشتیبانی</a>
    </div>
</body>
</html>
