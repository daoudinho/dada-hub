{{-- En-tête et navigation du module (onglets sur ordinateur, barre du bas sur mobile). $awaitingCount, $hasGarmin et $hasGoogleHealth viennent de CoachModule. --}}
<header class="appbar">
  <a class="icon-btn" href="{{ route('coach.index') }}" title="Accueil du coach">@include('coach::partials.icon', ['name' => 'back'])<span class="sr-only">Accueil du coach</span></a>
  <h1>Dada Coach</h1>
  <div class="appbar-actions">
    <a class="icon-btn" href="{{ route('dashboard') }}" title="Accueil du Hub">@include('coach::partials.icon', ['name' => 'home'])<span class="sr-only">Accueil du Hub</span></a>
    @if ($hasGarmin)
      <form method="POST" action="{{ route('coach.garmin.sync') }}">
        @csrf
        <button class="icon-btn sync-btn" title="Synchroniser avec Garmin">@include('coach::partials.icon', ['name' => 'refresh'])<span class="sr-only">Synchroniser avec Garmin</span></button>
      </form>
    @elseif ($hasGoogleHealth)
      <form method="POST" action="{{ route('coach.google-health.sync') }}">
        @csrf
        <button class="icon-btn sync-btn" title="Synchroniser avec Google Health">@include('coach::partials.icon', ['name' => 'refresh'])<span class="sr-only">Synchroniser avec Google Health</span></button>
      </form>
    @endif
  </div>
</header>

@php
  $tabs = [
      ['coach.index', 'coach.index', 'home', 'Accueil'],
      ['coach.chat.index', 'coach.chat.*', 'chat', 'Coach'],
      ['coach.running.calendar', 'coach.running.calendar', 'calendar', 'Calendrier'],
      ['coach.running.activities.index', 'coach.running.activities.*', 'history', 'Historique'],
      ['coach.exploits', 'coach.exploits', 'trophy', 'Exploits'],
      ['coach.profile.edit', 'coach.profile.*', 'user', 'Profil'],
  ];
@endphp
<nav class="tabs" aria-label="Dada Coach">
  @foreach ($tabs as [$route, $active, $icon, $label])
    <a href="{{ route($route) }}" @class(['on' => request()->routeIs($active)]) @if (request()->routeIs($active)) aria-current="page" @endif>
      @include('coach::partials.icon', ['name' => $icon])
      <span>{{ $label }}</span>
      @if ($route === 'coach.chat.index' && $awaitingCount)<span class="badge" aria-label="{{ $awaitingCount }} en attente">{{ $awaitingCount }}</span>@endif
    </a>
  @endforeach
</nav>
