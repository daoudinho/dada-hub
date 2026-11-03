{{-- Carte d'une recette dans la liste. Paramètre : $recipe (avec tags, ingredients et cartItem chargés). --}}
<article class="card {{ $recipe->enabled ? '' : 'off' }}"
         data-recipe
         data-search="{{ $recipe->searchText() }}"
         data-tags="{{ $recipe->tags->pluck('id')->implode(' ') }}">
  <a href="{{ route('recettes.show', $recipe) }}">
    <div class="thumb">
      @if ($imageUrl = $recipe->imageUrl())
        <img src="{{ $imageUrl }}" alt="" loading="lazy">
      @else
        <span aria-hidden="true">{{ $recipe->isImporting() ? '⏳' : '🍽️' }}</span>
      @endif
      @if ($tag = $recipe->tags->first())
        <span class="pill">{{ $tag->label() }}</span>
      @endif
    </div>
    <div class="body">
      <h3>{{ $recipe->name }}</h3>
      @if ($recipe->isImporting())
        <span class="status-line"><span class="spinner"></span> Import en cours…</span>
      @elseif ($recipe->importFailed())
        <span class="status-line bad">Import échoué</span>
      @else
        <div class="meta">
          @if ($duration = $recipe->durationLabel())
            <span>@include('recettes::partials.icon', ['name' => 'clock', 'size' => 16]) {{ $duration }}</span>
          @endif
          <span>@include('recettes::partials.icon', ['name' => 'users', 'size' => 16]) {{ $recipe->servings }} pers.</span>
        </div>
      @endif
    </div>
  </a>
  @include('recettes::partials.cart-toggle', ['recipe' => $recipe, 'class' => 'cart-btn'])
</article>
