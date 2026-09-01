@props([
    'invalid' => false,
])

<select
    @if ($invalid) aria-invalid="true" @endif
    {{ $attributes->class([
        'field-control pr-9',
        'field-control-invalid' => $invalid,
    ]) }}
>
    {{ $slot }}
</select>
