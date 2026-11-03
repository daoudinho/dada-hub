@extends('recettes::layout')

@section('title', $showDisabled ? 'Recettes masquées' : 'Mes Recettes')

@section('content')
<div class="page">
  @include('recettes::partials.topbar', ($showDisabled
      ? ['backUrl' => route('recettes.index'), 'backLabel' => 'Recettes']
      : ['backUrl' => route('dashboard'), 'backLabel' => 'Hub']) + ['withSearch' => true])

  <header class="brand">
    <div class="brand-title">
      @include('recettes::partials.icon', ['name' => 'chef', 'size' => 40])
      <h1 class="script">{{ $showDisabled ? 'Masquées' : 'Mes Recettes' }}</h1>
    </div>
    <div class="brand-tools">
      <a class="btn" href="{{ route('recettes.create') }}">@include('recettes::partials.icon', ['name' => 'plus', 'size' => 18]) Ajouter une recette</a>
    </div>
  </header>

  <label class="search" id="search">
    @include('recettes::partials.icon', ['name' => 'search', 'size' => 18])
    <span class="sr-only">Rechercher une recette ou un ingrédient</span>
    <input type="search" id="searchInput" placeholder="Une recette, un ingrédient…" autocomplete="off">
  </label>

  @if ($tags->isNotEmpty())
    <div class="chips" role="group" aria-label="Filtrer par étiquette">
      <button type="button" class="chip" data-tag="" aria-pressed="true">Toutes</button>
      @foreach ($tags as $tag)
        <button type="button" class="chip" data-tag="{{ $tag->id }}" aria-pressed="false">{{ $tag->label() }}</button>
      @endforeach
    </div>
  @endif

  @if ($recipes->isEmpty())
    <div class="empty">
      <div class="big" aria-hidden="true">🥗</div>
      @if ($showDisabled)
        <p>Aucune recette masquée.</p>
      @else
        <p>Aucune recette pour l'instant.</p>
        <a class="btn" href="{{ route('recettes.create') }}">Ajouter la première</a>
      @endif
    </div>
  @else
    <div class="grid" id="grid">
      @foreach ($recipes as $recipe)
        @include('recettes::partials.card', ['recipe' => $recipe])
      @endforeach
    </div>
    <p class="empty" id="noMatch" hidden>Aucune recette ne correspond.</p>
  @endif

  @if ($showDisabled)
    <a class="foot-link" href="{{ route('recettes.index') }}">← Retour aux recettes</a>
  @elseif ($disabledCount)
    <a class="foot-link" href="{{ route('recettes.index', ['masquees' => 1]) }}">Recettes masquées ({{ $disabledCount }})</a>
  @endif
</div>

<a class="btn add-bar" href="{{ route('recettes.create') }}">
  @include('recettes::partials.icon', ['name' => 'plus', 'size' => 20]) Ajouter une recette
</a>
@endsection

@push('scripts')
<script>
(() => {
  const cards = [...document.querySelectorAll('[data-recipe]')];
  const chips = [...document.querySelectorAll('.chip')];
  const input = document.getElementById('searchInput');
  const search = document.getElementById('search');
  const toggle = document.getElementById('searchToggle');
  const noMatch = document.getElementById('noMatch');
  const normalize = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  let tag = '';

  const apply = () => {
    const words = normalize(input.value).split(/\s+/).filter(Boolean);
    let visible = 0;
    for (const card of cards) {
      const show = (!tag || card.dataset.tags.split(' ').includes(tag))
        && words.every((word) => card.dataset.search.includes(word));
      card.hidden = !show;
      visible += show ? 1 : 0;
    }
    if (noMatch) noMatch.hidden = visible > 0;
  };

  for (const chip of chips) {
    chip.addEventListener('click', () => {
      tag = chip.dataset.tag;
      chips.forEach((other) => other.setAttribute('aria-pressed', String(other === chip)));
      apply();
    });
  }

  input.addEventListener('input', apply);
  toggle.addEventListener('click', () => {
    const open = search.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(open));
    if (open) input.focus();
  });
})();
</script>
@endpush
