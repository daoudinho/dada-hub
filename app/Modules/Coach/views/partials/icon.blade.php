{{-- Icône au trait (24×24). Paramètre : $name. --}}
@php
  $paths = [
      'back' => '<path d="M15 18l-6-6 6-6"/>',
      'refresh' => '<path d="M20 11a8 8 0 0 0-14.9-3.9M4 5v4h4"/><path d="M4 13a8 8 0 0 0 14.9 3.9M20 19v-4h-4"/>',
      'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
      'chat' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
      'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
      'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
      'trophy' => '<path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0V4z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
      'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
      'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
      'dumbbell' => '<path d="M6.5 6.5v11M17.5 6.5v11M3.5 9v6M20.5 9v6M6.5 12h11"/>',
      'run' => '<circle cx="15" cy="4.5" r="1.8"/><path d="M8 21l3-6 3 2.5V22M6 12l3-3.5 4 1.5 2.5 3H19M11 15l-1.5-4"/>',
  ];
@endphp
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $paths[$name] ?? '' !!}</svg>
