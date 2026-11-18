@props(['name' => 'puzzle', 'class' => 'h-5 w-5', 'title' => null])

@php
    $iconName = \App\Core\Plugins\PluginIconRegistry::resolve('custom', 'general', (string) $name);
    $paths = [
        'graduation-cap' => '<path d="m2 10 10-5 10 5-10 5-10-5Z"/><path d="M6 12v5c3.5 2.5 8.5 2.5 12 0v-5"/><path d="M22 10v6"/>',
        'book-open' => '<path d="M12 7v14"/><path d="M3 18V5a2 2 0 0 1 2-2h3a4 4 0 0 1 4 4 4 4 0 0 1 4-4h3a2 2 0 0 1 2 2v13h-5a4 4 0 0 0-4 3 4 4 0 0 0-4-3H3Z"/>',
        'clipboard-check' => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4.5V3h6v1.5M8 13l2.5 2.5L16 10"/>',
        'calendar-check' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 15l2.5 2.5L16 12"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8a3 3 0 0 1-2 2.83M20 21v-2a4 4 0 0 0-3-3.87"/>',
        'building-2' => '<path d="M3 21h18M5 21V5l7-3 7 3v16M9 9h1m4 0h1m-6 4h1m4 0h1m-5 8v-4h4v4"/>',
        'credit-card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M7 15h3"/>',
        'wallet' => '<path d="M4 6h15a2 2 0 0 1 2 2v11H5a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h13"/><path d="M21 11h-5a2 2 0 0 0 0 4h5"/>',
        'receipt' => '<path d="M4 3v18l4-2 4 2 4-2 4 2V3l-4 2-4-2-4 2-4-2Z"/><path d="M8 9h8M8 13h6"/>',
        'video' => '<rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 10 5-3v10l-5-3"/>',
        'play-circle' => '<circle cx="12" cy="12" r="10"/><path d="m10 8 6 4-6 4V8Z"/>',
        'messages-square' => '<path d="M21 11.5a8.5 8.5 0 0 1-9 8.5 9 9 0 0 1-4-.9L3 21l1.9-4.2A8.5 8.5 0 1 1 21 11.5Z"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 7L2 7"/>',
        'file-check' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 15l2 2 5-5"/>',
        'images' => '<rect x="3" y="3" width="18" height="14" rx="2"/><circle cx="8.5" cy="8" r="1.5"/><path d="m21 13-5-5L5 17M7 21h14"/>',
        'panels-top-left' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'layout-template' => '<rect x="3" y="3" width="8" height="7" rx="1"/><rect x="13" y="3" width="8" height="4" rx="1"/><rect x="13" y="9" width="8" height="12" rx="1"/><rect x="3" y="12" width="8" height="9" rx="1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.6.9L16 21h-3l-.3-2.1a8 8 0 0 1-1.6-.9l-1.7.6L8 16.2l1.4-1.1a8 8 0 0 1 0-1.9L8 12.1l1.4-2.4 1.7.6a8 8 0 0 1 1.6-.9L13 7h3l.3 2.1a8 8 0 0 1 1.6.9l1.7-.6 1.4 2.4-1.4 1.1a8 8 0 0 1-.2 2.1Z"/>',
        'shield-check' => '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/>',
        'chart-no-axes-combined' => '<path d="M3 3v18h18M7 14l4-4 4 4 6-7"/>',
        'headset' => '<path d="M3 13v-2a9 9 0 0 1 18 0v2M3 13h4v7H5a2 2 0 0 1-2-2v-5ZM21 13h-4v7h2a2 2 0 0 0 2-2v-5Z"/>',
        'award' => '<circle cx="12" cy="8" r="6"/><path d="m8 13-1 9 5-3 5 3-1-9"/>',
        'brain' => '<path d="M12 18V5a3 3 0 0 0-5.8-1A4 4 0 0 0 4 11a4 4 0 0 0 2 7 3 3 0 0 0 6 0ZM12 18V5a3 3 0 0 1 5.8-1A4 4 0 0 1 20 11a4 4 0 0 1-2 7 3 3 0 0 1-6 0Z"/>',
        'shopping-bag' => '<path d="M5 8h14l1 13H4L5 8ZM9 8a3 3 0 0 1 6 0"/>',
        'shopping-cart' => '<path d="M3 3h2l2 13h12l3-9H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>',
        'tag' => '<path d="M20 13 11 22l-9-9V2h11l7 7a3 3 0 0 1 0 4Z"/><circle cx="7" cy="7" r="1"/>',
        'megaphone' => '<path d="m3 11 18-5v12L3 13v-2ZM11 14l2 7H8l-2-8"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
        'languages' => '<path d="m5 8 6 6M4 14l6-6 2-3M2 5h12M7 2v3M14 21l5-12 5 12M16 17h6"/>',
        'trophy' => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4ZM7 6H3v2a5 5 0 0 0 5 5M17 6h4v2a5 5 0 0 1-5 5"/>',
        'sparkles' => '<path d="m12 3 1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5L12 3ZM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16Z"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
        'puzzle' => '<path d="M19 13h-2a2 2 0 1 1-4 0V5h-3v2a2 2 0 1 1-4 0V5H4v14h6v-2a2 2 0 1 1 4 0v2h5v-6Z"/>',
    ];
    $svg = $paths[$iconName] ?? $paths['puzzle'];
@endphp
<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" @if($title) role="img" aria-label="{{ $title }}" @else aria-hidden="true" @endif>
    {!! $svg !!}
</svg>
