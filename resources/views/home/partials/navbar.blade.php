@php
    $links = [
        ['label' => 'ویژگی‌ها', 'href' => '#features'],
        ['label' => 'گالری', 'href' => '#gallery'],
        ['label' => 'نظرات', 'href' => '#testimonials'],
        ['label' => 'تماس', 'href' => '#contact'],
    ];
@endphp

{{-- کلاس is-scrolled را app.js پس از ۲۴ پیکسل اسکرول اضافه می‌کند --}}
<header
    data-sticky-navbar
    class="fixed inset-x-0 top-0 z-50 transition-all duration-300 [&.is-scrolled]:border-b [&.is-scrolled]:border-line [&.is-scrolled]:bg-surface/85 [&.is-scrolled]:backdrop-blur-xl"
>
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <x-logo :size="34" :href="route('home')" />

        <nav class="hidden items-center gap-7 md:flex">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" class="text-[13.5px] font-medium text-muted transition-colors hover:text-ink">
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="hidden items-center gap-3 md:flex">
            <x-theme-toggle />
            <x-button :href="route('login')" icon="logout">ورود به پنل</x-button>
        </div>

        <button type="button" onclick="toggleHomeMenu()" id="home-menu-button"
                class="flex h-9 w-9 items-center justify-center rounded-xl text-ink md:hidden focus-ring"
                aria-label="منو" aria-expanded="false" aria-controls="home-menu">
            <span data-menu-open><x-icon name="menu" :size="20" /></span>
            <span data-menu-close class="hidden"><x-icon name="close" :size="20" /></span>
        </button>
    </div>

    <div id="home-menu" class="hidden border-t border-line bg-surface px-4 pb-5 pt-3 md:hidden">
        <div class="flex flex-col gap-3">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" onclick="toggleHomeMenu()" class="text-sm font-medium text-muted">
                    {{ $link['label'] }}
                </a>
            @endforeach

            <div class="mt-2 flex items-center gap-2">
                <x-button :href="route('login')" class="flex-1">ورود به پنل</x-button>
                <x-theme-toggle />
            </div>
        </div>
    </div>
</header>

<script>
    function toggleHomeMenu() {
        const menu = document.getElementById('home-menu');
        const button = document.getElementById('home-menu-button');
        const open = menu.classList.toggle('hidden') === false;

        button.setAttribute('aria-expanded', String(open));
        button.querySelector('[data-menu-open]').classList.toggle('hidden', open);
        button.querySelector('[data-menu-close]').classList.toggle('hidden', !open);
    }
</script>
