@props([
    'invalid' => false,
    'type' => 'text',
])

<input
    type="{{ $type }}"
    @if ($invalid) aria-invalid="true" @endif
    {{ $attributes->class([
        'field-control',
        'field-control-invalid' => $invalid,
    ]) }}
>
