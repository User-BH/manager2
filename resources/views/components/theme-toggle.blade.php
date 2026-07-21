{{-- دکمهٔ تعویض تم؛ آیکون خورشید در تم روشن و ماه در تم تاریک نمایش داده می‌شود --}}
<x-icon-button
    variant="outline"
    onclick="toggleTheme()"
    aria-label="تغییر حالت روشن و تاریک"
    title="تغییر حالت روشن و تاریک"
    class="overflow-hidden"
>
    <span class="text-accent-500 dark:hidden">
        <x-icon name="sun" :size="18" />
    </span>
    <span class="hidden text-brand-300 dark:block">
        <x-icon name="moon" :size="17" />
    </span>
</x-icon-button>
