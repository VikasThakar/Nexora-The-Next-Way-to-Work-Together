@props([
    'label',
    'size' => 'md',
])

<span {{ $attributes->class([
    'inline-flex max-w-full items-center gap-1 rounded-full font-medium whitespace-nowrap ring-1 ring-inset',
    'px-2 py-0.5 text-xs' => $size === 'md',
    'px-1.5 py-px text-[10px]' => $size === 'sm',
    $label->chipClasses(),
]) }}>
    <span class="truncate">{{ $label->name }}</span>
</span>
