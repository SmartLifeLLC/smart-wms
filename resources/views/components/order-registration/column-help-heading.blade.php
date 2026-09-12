@props([
    'label',
    'align' => 'left',
])

@php
    $justifyClass = match ($align) {
        'center' => 'justify-center',
        'right' => 'justify-end',
        default => 'justify-start',
    };
@endphp

<button
    type="button"
    x-on:click.stop="openColumnHelp(@js($label))"
    class="inline-flex w-full items-center gap-1 {{ $justifyClass }} rounded px-0.5 py-0.5 text-inherit hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-200 dark:hover:text-blue-300 dark:focus:ring-blue-900"
>
    <span>{{ $label }}</span>
    <span class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-[10px] font-bold leading-none text-slate-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300">?</span>
</button>
