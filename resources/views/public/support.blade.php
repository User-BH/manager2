{{-- صفحه‌ی پشتیبانی (island) + داده‌ی ساخت‌یافته‌ی پرسش‌های متداول. --}}
@extends('layouts.public')

@push('jsonld')
    @php
        /*
         * FAQPage از **همان** فایلی که صفحه نشان می‌دهد (مرحلهٔ ۷ · فاز ۴).
         *
         * 🔴 پیش از این پنج پرسش اینجا جدا نوشته شده بود که هیچ‌کدام روی صفحه
         * نبودند — و قاعده‌ی گوگل همین است که دادهٔ ساخت‌یافته باید همان محتوای
         * دیدنیِ صفحه باشد. چند ادعایش هم درست نبود («راه‌اندازیِ پنل رایگان
         * است»؛ مدیر با تهیه‌ی اشتراک مجتمع می‌سازد).
         */
        $content = json_decode((string) file_get_contents(resource_path('content/support-topics.json')), true);
        $faqTopic = collect($content['topics'] ?? [])->firstWhere('id', 'faq');
        $faqPage = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $entry) => [
                '@type' => 'Question',
                'name' => $entry['question'],
                // `**پررنگ**` نشانه‌ی نمایشِ صفحه است، نه بخشی از متن
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => str_replace('**', '', $entry['answer'])],
            ], $faqTopic['entries'] ?? []),
        ];
    @endphp
    <script type="application/ld+json">{!! \App\Support\Json::forScript($faqPage) !!}</script>
@endpush
