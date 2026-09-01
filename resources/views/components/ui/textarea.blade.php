@props([
    'invalid' => false,
    'rows' => 4,
])

<textarea
    rows="{{ $rows }}"
    @if ($invalid) aria-invalid="true" @endif
    {{ $attributes->class([
        'field-control resize-y',
        'field-control-invalid' => $invalid,
    ]) }}
>{{ $slot }}</textarea>
