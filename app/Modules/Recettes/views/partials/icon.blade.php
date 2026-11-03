{{-- Icône SVG en trait, couleur héritée du texte. Paramètres : $name, $size (px, 20 par défaut). --}}
@php($size ??= 20)
<svg class="icon" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
  @case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
  @case('users')<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="17" cy="9" r="2.4"/><path d="M16.5 14.2c2 .3 3.5 1.8 4 4.3"/>@break
  @case('search')<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>@break
  @case('basket')<path d="M3 10h18l-1.6 8.4a2 2 0 0 1-2 1.6H6.6a2 2 0 0 1-2-1.6Z"/><path d="m8 10 3-6M16 10l-3-6"/><path d="M9 14v2.5M12 14v2.5M15 14v2.5"/>@break
  @case('basket-check')<path d="M3 10h18l-1.6 8.4a2 2 0 0 1-2 1.6H6.6a2 2 0 0 1-2-1.6Z"/><path d="m8 10 3-6M16 10l-3-6"/><path d="m9 15 2 2 4-4"/>@break
  @case('check')<path d="m5 12.5 4.5 4.5L19 7.5"/>@break
  @case('back')<path d="M15 5l-7 7 7 7"/>@break
  @case('plus')<path d="M12 5v14M5 12h14"/>@break
  @case('minus')<path d="M5 12h14"/>@break
  @case('close')<path d="M6 6l12 12M18 6 6 18"/>@break
  @case('edit')<path d="M4 20h4L19 9l-4-4L4 16Z"/><path d="m13.5 6.5 4 4"/>@break
  @case('link')<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>@break
  @case('tag')<path d="M3 12V4h8l9 9-8 8Z"/><circle cx="7.5" cy="8.5" r="1.4"/>@break
  @case('eye-off')<path d="M3 3l18 18"/><path d="M10.6 6.1A9.8 9.8 0 0 1 12 6c5 0 8.5 4.2 9.5 6-.5.9-1.6 2.4-3.2 3.7M6.6 7.6C4.6 8.9 3.1 10.8 2.5 12c1 1.8 4.5 6 9.5 6 1.6 0 3-.4 4.3-1"/><path d="M9.9 10a3 3 0 0 0 4.1 4.1"/>@break
  @case('eye')<path d="M2.5 12C3.5 10.2 7 6 12 6s8.5 4.2 9.5 6c-1 1.8-4.5 6-9.5 6s-8.5-4.2-9.5-6Z"/><circle cx="12" cy="12" r="3"/>@break
  @case('trash')<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>@break
  @case('sparkles')<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8Z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8Z"/>@break
  @case('pen')<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>@break
  @case('copy')<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>@break
  @case('refresh')<path d="M20 11a8 8 0 0 0-14.8-4M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.8 4M20 20v-4h-4"/>@break
  @case('chef')<path d="M7 14.5V20h10v-5.5"/><path d="M7 14.5A4 4 0 0 1 6.2 6.6 4.5 4.5 0 0 1 12 4a4.5 4.5 0 0 1 5.8 2.6 4 4 0 0 1-.8 7.9Z"/><path d="M7 17h10"/>@break
  @case('leaf')<path d="M5 19C5 10 11 5 20 4c0 9-5 15-14 15"/><path d="M5 19 13 11"/><path d="M9 15.5 8 12.5M12 12.5 11.5 9.5M9 15.5 12 15.5M12 12.5l3 .2"/>@break
@endswitch
</svg>
