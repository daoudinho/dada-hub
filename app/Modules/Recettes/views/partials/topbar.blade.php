{{-- Barre du haut. Paramètres : $backUrl, $backLabel, $withSearch (loupe qui ouvre #search, mobile). Le nombre au panier ($cartCount) vient de RecettesModule. --}}
<div class="topbar">
  <a class="back" href="{{ $backUrl }}">@include('recettes::partials.icon', ['name' => 'back', 'size' => 18]) {{ $backLabel }}</a>
  <nav aria-label="Recettes">
    @if ($withSearch ?? false)
      <button type="button" class="round search-toggle" id="searchToggle" aria-controls="search" aria-expanded="false" title="Rechercher">
        @include('recettes::partials.icon', ['name' => 'search'])<span class="sr-only">Rechercher</span>
      </button>
    @endif
    <a class="round" href="{{ route('recettes.tags.index') }}" title="Étiquettes">
      @include('recettes::partials.icon', ['name' => 'tag'])<span class="sr-only">Étiquettes</span>
    </a>
    <a class="round" href="{{ route('recettes.cart.index') }}" title="Panier">
      @include('recettes::partials.icon', ['name' => 'basket'])<span class="sr-only">Panier</span>
      @if ($cartCount)<span class="badge">{{ $cartCount }}</span>@endif
    </a>
  </nav>
</div>
