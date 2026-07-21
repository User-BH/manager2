@props([
    'label' => null,
    'name',
    'options' => [],
    'selected' => null,
    'required' => false,
    'hint' => null,
])

<label class="block">
    @if ($label)
        <span class="mb-1.5 block text-sm font-medium text-muted">
            {{ $label }}@if ($required)<span class="text-danger"> *</span>@endif
        </span>
    @endif

    <select
        name="{{ $name }}"
        @if ($required) required @endif
        {{ $attributes->merge(['class' => 'w-full rounded-xl border border-line bg-sunken px-3 py-2.5 text-sm text-ink outline-none transition-colors duration-200 focus:border-brand-400 focus:bg-surface focus-ring']) }}
    >
        @foreach ($options as $val => $text)
            <option value="{{ $val }}" @selected((string) old($name, $selected) === (string) $val)>{{ $text }}</option>
        @endforeach
    </select>

    @error($name)
        <span class="mt-1 block text-xs text-danger">{{ $message }}</span>
    @else
        @if ($hint)
            <span class="mt-1 block text-xs text-faint">{{ $hint }}</span>
        @endif
    @enderror
</label>
