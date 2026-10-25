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
| «چند نکته»، بندِ ۴: پانزده قاعده‌ی به‌کاررفته پیام نداشتند (`mimetypes`، `prohibits`،
| `required_without_all`، `distinct`، …). چون زبانِ پشتیبان هم fa است، کاربر نه انگلیسی
| که خودِ کلید را می‌دید («validation.mimetypes»). `PersianValidationTest` هر قاعده‌ی
| به‌کاررفته در FormRequestها و هر فیلدِ پیام‌رسان را با این فایل می‌سنجد.
|
*/

return [
    'accepted' => 'پذیرفتن :attribute الزامی است.',
    'alpha_num' => ':attribute فقط می‌تواند حرف و عدد باشد.',
    'array' => ':attribute باید فهرست باشد.',
    'before_or_equal' => ':attribute باید تاریخی پیش از :date یا برابر آن باشد.',
    'between' => [
        'array' => ':attribute باید بین :min و :max آیتم باشد.',
        'file' => 'حجم :attribute باید بین :min و :max کیلوبایت باشد.',
        'numeric' => ':attribute باید بین :min و :max باشد.',
        'string' => ':attribute باید بین :min و :max کاراکتر باشد.',
    ],
    'current_password' => 'رمز عبور درست نیست.',
    'digits' => ':attribute باید :digits رقم باشد.',
    'distinct' => ':attribute تکراری است.',
    'mimetypes' => 'قالبِ :attribute پشتیبانی نمی‌شود.',
    'present' => ':attribute باید فرستاده شود.',
    'prohibited' => ':attribute را نمی‌توان فرستاد.',
    'prohibits' => ':attribute را نمی‌توان هم‌زمان با موردِ دیگری فرستاد.',
    'required_if' => 'وقتی :other «:value» است، وارد کردن :attribute الزامی است.',
    'required_unless' => 'جز وقتی :other «:values» است، وارد کردن :attribute الزامی است.',
    'required_with' => 'وقتی :values هست، وارد کردن :attribute الزامی است.',
    // گامِ دومِ سوپر ادمین: کدِ برنامه یا کدِ بازیابی (۲۰۲۶-۱۰-۲۵)
    'required_without' => 'وقتی :values نیست، وارد کردن :attribute الزامی است.',
    'required_without_all' => 'دستِ‌کم یکی از :attribute یا موارد دیگر را بفرستید.',
    'starts_with' => ':attribute باید با یکی از این‌ها شروع شود: :values.',
    'url' => 'قالب :attribute معتبر نیست.',
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

        // پیام‌رسان («چند نکته»، بندِ ۴) — هیچ نامِ فنیِ فیلدی به خطای قابلِ‌دیدنِ کاربر نمی‌رسد
        'body' => 'متنِ پیام',
        'attachment' => 'پیوست',
        'voice' => 'پیامِ صوتی',
        'video_message' => 'پیامِ ویدیویی',
        'client_id' => 'شناسه‌ی ارسال',
        'reply_to_id' => 'پیامِ پاسخ‌داده‌شده',
        'message_id' => 'پیام',
        'pinned' => 'سنجاق',
        'emoji' => 'واکنش',
        'reason' => 'دلیلِ گزارش',
        'resolution' => 'نتیجه‌ی بررسی',
        'up_to' => 'آخرین پیامِ خوانده‌شده',
        'title' => 'عنوان',
        'type' => 'نوع',
        'user_id' => 'عضو',
        'unit_ids' => 'واحدها',
        'unit_ids.*' => 'واحد',
        'members_can_post' => 'نوشتنِ اعضا',
        'moderator' => 'ناظر',
        'audience' => 'مخاطبان',
        'poll_question' => 'پرسشِ نظرسنجی',
        'poll_options' => 'گزینه‌های نظرسنجی',
        'poll_options.*' => 'گزینه‌ی نظرسنجی',
        'poll_max_selections' => 'حداکثر گزینه‌ی قابلِ انتخاب',
        'poll_voter_scope' => 'رأی‌دهندگان',
        'poll_weight_mode' => 'وزنِ رأی',
        'poll_quorum_percent' => 'حدِ نصاب',
        'poll_closes_at' => 'زمانِ پایانِ نظرسنجی',
        'poll_allow_change' => 'امکانِ تغییرِ رأی',
    ],
];
