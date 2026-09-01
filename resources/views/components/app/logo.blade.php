@props(['class' => 'size-8'])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 32 32" fill="none" aria-hidden="true">
    <rect width="32" height="32" rx="8" class="fill-brand-600" />
    <path d="M8 21.5 13.2 10h2.2l5.2 11.5h-2.4l-1.1-2.6h-5.6l-1.1 2.6H8Zm4.4-4.5h4l-2-4.7-2 4.7Z" fill="white" />
    <path d="M22 21.5V10h2.2v11.5H22Z" fill="white" fill-opacity="0.6" />
</svg>
