<?php

/*
|--------------------------------------------------------------------------
| پیام‌های اعتبارسنجی فارسی
|--------------------------------------------------------------------------
|
| کل رابط کاربری فارسی است، اما پیام‌های پیش‌فرض اعتبارسنجی لاراول انگلیسی
| برمی‌گشتند و مستقیم زیر فیلدهای فرم React نمایش داده می‌شدند. اینجا فقط
| قواعدی ترجمه شده که در این پروژه واقعاً استفاده می‌شوند.
|
*/

return [
    'accepted' => 'پذیرفتن :attribute الزامی است.',
    'after' => ':attribute باید تاریخی بعد از :date باشد.',
    'after_or_equal' => ':attribute باید تاریخی بعد از :date یا برابر آن باشد.',
    'before' => ':attribute باید تاریخی قبل از :date باشد.',
    'boolean' => 'مقدار :attribute باید درست یا نادرست باشد.',
    'confirmed' => ':attribute با تکرارش یکسان نیست.',
    'date' => ':attribute یک تاریخ معتبر نیست.',
    'different' => ':attribute و :other نباید یکسان باشند.',
    'email' => 'قالب :attribute معتبر نیست.',
    'exists' => ':attribute انتخاب‌شده معتبر نیست.',
    'file' => ':attribute باید یک فایل باشد.',
    'image' => ':attribute باید یک تصویر باشد.',
    'in' => ':attribute انتخاب‌شده معتبر نیست.',
    'integer' => ':attribute باید عدد صحیح باشد.',
    'max' => [
        'array' => ':attribute نباید بیشتر از :max آیتم باشد.',
        'file' => 'حجم :attribute نباید بیشتر از :max کیلوبایت باشد.',
        'numeric' => ':attribute نباید بزرگ‌تر از :max باشد.',
        'string' => ':attribute نباید بیشتر از :max کاراکتر باشد.',
    ],
    'mimes' => ':attribute باید یکی از این قالب‌ها باشد: :values.',
    'min' => [
        'array' => ':attribute باید حداقل :min آیتم باشد.',
        'file' => 'حجم :attribute باید حداقل :min کیلوبایت باشد.',
        'numeric' => ':attribute نباید کوچک‌تر از :min باشد.',
        'string' => ':attribute باید حداقل :min کاراکتر باشد.',
    ],
    'numeric' => ':attribute باید عدد باشد.',
    'regex' => 'قالب :attribute معتبر نیست.',
    'required' => 'وارد کردن :attribute الزامی است.',
    'same' => ':attribute و :other باید یکسان باشند.',
    'string' => ':attribute باید متن باشد.',
    'unique' => ':attribute قبلاً ثبت شده است.',
    'uploaded' => 'بارگذاری :attribute ناموفق بود.',

    // پیام‌های قاعده‌ی Password::min()
    'password' => [
        'letters' => ':attribute باید حداقل یک حرف داشته باشد.',
        'mixed' => ':attribute باید حداقل یک حرف بزرگ و یک حرف کوچک داشته باشد.',
        'numbers' => ':attribute باید حداقل یک عدد داشته باشد.',
        'symbols' => ':attribute باید حداقل یک نماد داشته باشد.',
        'uncompromised' => 'این :attribute در نشت اطلاعات دیده شده است. لطفاً مقدار دیگری انتخاب کنید.',
    ],

    'custom' => [],

    'attributes' => [
        'name' => 'نام',
        'phone' => 'شماره تلفن',
        'email' => 'ایمیل',
        'password' => 'رمز عبور',
        'complex_name' => 'نام مجتمع',
        'unit_number' => 'شماره واحد',
        'floor' => 'طبقه',
        'area' => 'متراژ',
        'residents_count' => 'تعداد ساکنین',
        'occupancy_status' => 'وضعیت سکونت',
        'coefficient' => 'ضریب',
        'role' => 'نقش',
        'unit_id' => 'واحد',
        'period' => 'دوره',
        'amount' => 'مبلغ',
    ],
];
