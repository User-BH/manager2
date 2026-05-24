@php use App\Support\Jalali; @endphp
<div class="mx-auto flex h-[calc(100vh-8rem)] max-w-3xl flex-col">
    <div class="mb-3 flex items-center justify-between">
        <h1 class="text-xl font-bold">پیام‌رسان مجتمع</h1>
        @if ($complex && ! $complex->messenger_enabled)
            <x-badge color="rose">پیام‌رسان بسته است</x-badge>
        @endif
    </div>

    <div class="flex-1 space-y-3 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
        @forelse ($messages as $message)
            @continue($message->is_hidden && ! auth()->user()->isAdmin())
            @php($mine = $message->user_id === auth()->id())
            <div class="flex {{ $mine ? 'justify-start' : 'justify-end' }}">
                <div class="max-w-[80%] rounded-2xl px-4 py-2 text-sm shadow-sm
                    {{ $mine ? 'bg-sky-600 text-white' : 'bg-slate-100 dark:bg-slate-700' }}
                    {{ $message->is_hidden ? 'opacity-50' : '' }}">
                    <div class="mb-1 flex items-center gap-2 text-xs {{ $mine ? 'text-sky-100' : 'text-slate-400' }}">
                        <span class="font-semibold">{{ $message->author_name }}</span>
                        <span>·</span>
                        <span>{{ $message->unit_label }}</span>
                    </div>
                    <p class="whitespace-pre-line">{{ $message->body }}</p>
                    <div class="mt-1 flex items-center gap-2 text-[10px] {{ $mine ? 'text-sky-100' : 'text-slate-400' }}">
                        <span>{{ Jalali::dateTime($message->created_at) }}</span>
                        @if (auth()->user()->isAdmin())
                            <button wire:click="toggleHide({{ $message->id }})" class="underline">
                                {{ $message->is_hidden ? 'نمایش' : 'مخفی کردن' }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <p class="py-12 text-center text-sm text-slate-400">هنوز پیامی ارسال نشده است.</p>
        @endforelse
    </div>

    @if ($canSend)
        <form wire:submit="send" class="mt-3 flex items-end gap-2">
            <div class="flex-1">
                <textarea wire:model="body" rows="1" placeholder="پیام خود را بنویسید…"
                    class="w-full resize-none rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30 dark:border-slate-600 dark:bg-slate-900"></textarea>
                @error('body')<span class="mt-1 block text-xs text-rose-500">{{ $message }}</span>@enderror
            </div>
            <x-button type="submit" variant="primary">ارسال</x-button>
        </form>
    @else
        <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
            امکان ارسال پیام برای شما فعال نیست.
        </div>
    @endif
</div>
