@extends('layouts.guest')

@section('title', config('brand.tagline'))

@section('content')
    {{-- نوار پیشرفت اسکرول --}}
    <div class="fixed inset-x-0 top-0 z-[60] h-0.5 bg-transparent">
        <div data-scroll-progress class="h-full origin-right bg-brand-500" style="transform: scaleX(0)"></div>
    </div>

    @include('home.partials.navbar')

    <main>
        @include('home.partials.hero')
        @include('home.partials.stats')
        @include('home.partials.promos')
        @include('home.partials.features')
        @include('home.partials.gallery')
        @include('home.partials.testimonials')
        @include('home.partials.cta')
    </main>

    @include('home.partials.footer')
@endsection
