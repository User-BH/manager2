{{-- صفحه‌ی دمو (island) + داده‌ی ساخت‌یافته‌ی ویدیو. --}}
@extends('layouts.public')

@push('jsonld')
    @php
        $base = rtrim(config('app.url'), '/');
        $video = [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => 'دموی پنل مدیریت ساختمان ساکنا',
            'description' => 'یک دور کامل داخل پنل ساکنا: داشبورد، صدور قبض و شارژ، پرداخت آنلاین، اطلاعیه‌ها و گزارش مالی.',
            'thumbnailUrl' => [$base.'/images/hero-building-night.webp'],
            'uploadDate' => '2026-01-01',
            'contentUrl' => $base.'/videos/demo.mp4',
            'inLanguage' => 'fa-IR',
            'publisher' => ['@id' => $base.'/#organization'],
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($video, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
