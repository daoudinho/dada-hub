@extends('recettes::layout')

@section('title', $recipe->name.' · Mes Recettes')

@if ($recipe->isImporting())
  @push('head')<meta http-equiv="refresh" content="5">@endpush
@endif

@php($servings = $recipe->cartItem?->servings ?? $recipe->servings)

@section('content')
<div class="recipe-layout">
  <div class="hero">
    @if ($recipe->imageUrl())
      <img src="{{ $recipe->imageUrl() }}" alt="{{ $recipe->name }}">
    @else
      <span aria-hidden="true">{{ $recipe->isImporting() ? '⏳' : '🍽️' }}</span>
    @endif
    <div class="hero-actions">
      <a class="round" href="{{ route('recettes.index') }}" title="Retour aux recettes">
        @include('recettes::partials.icon', ['name' => 'back'])<span class="sr-only">Retour aux recettes</span>
      </a>
      @unless ($recipe->isImporting())
        @include('recettes::partials.cart-toggle', ['recipe' => $recipe])
      @endunless
    </div>
  </div>

  <main class="sheet">
    <span class="leaf">@include('recettes::partials.icon', ['name' => 'leaf', 'size' => 34])</span>
    <h1 class="script">{{ $recipe->name }}</h1>

    @unless ($recipe->enabled)
      <div class="notice">Cette recette est masquée : elle n'apparaît plus dans la liste.</div>
    @endunless

    @if ($recipe->isImporting())
      <div class="notice" role="status">
        <strong><span class="spinner"></span> L'IA prépare la recette…</strong>
        <span>Lecture de la page, des ingrédients et des étapes. Cette page se met à jour toute seule.</span>
      </div>
    @elseif ($recipe->importFailed())
      <div class="notice bad" role="alert">
        <strong>L'import n'a pas abouti.</strong>
        <span>{{ $recipe->import_error }}</span>
        <div class="actions-row" style="justify-content: flex-start">
          <form method="POST" action="{{ route('recettes.import.retry', $recipe) }}">
            @csrf
            <button class="btn small">@include('recettes::partials.icon', ['name' => 'refresh', 'size' => 16]) Réessayer</button>
          </form>
          <a class="btn small ghost" href="{{ route('recettes.edit', $recipe) }}">Compléter à la main</a>
        </div>
      </div>
    @endif

    <div class="facts">
      @if ($recipe->durationLabel())
        <span class="fact">@include('recettes::partials.icon', ['name' => 'clock']) {{ $recipe->durationLabel() }}</span>
      @endif
      <span class="stepper" data-stepper>
        <button type="button" data-step="-1" aria-label="Une personne de moins">@include('recettes::partials.icon', ['name' => 'minus', 'size' => 16])</button>
        <span class="value">@include('recettes::partials.icon', ['name' => 'users', 'size' => 16]) <output id="servings" aria-live="polite">{{ $servings }}</output> pers.</span>
        <button type="button" data-step="1" aria-label="Une personne de plus">@include('recettes::partials.icon', ['name' => 'plus', 'size' => 16])</button>
      </span>
      @foreach ($recipe->tags as $tag)
        <span class="pill tag-pill">{{ $tag->label() }}</span>
      @endforeach
    </div>

    @if ($recipe->description)
      <p class="lead">{{ $recipe->description }}</p>
    @endif

    @if ($recipe->ingredients->isNotEmpty())
      <h2 class="section script">@include('recettes::partials.icon', ['name' => 'basket', 'size' => 24]) Ingrédients</h2>
      <ul class="ingredients">
        @foreach ($recipe->ingredients as $ingredient)
          <li>
            <span class="ing-emoji" aria-hidden="true">{{ $ingredient->emoji ?? '•' }}</span>
            <span>
              @if ($ingredient->quantity !== null || $ingredient->unit)
                <span class="ing-amount" @if ($ingredient->quantity !== null) data-qty="{{ $ingredient->quantity }}" data-unit="{{ $ingredient->unit }}" @endif>{{ $ingredient->amountFor($servings, $recipe->servings) }}</span>
              @endif
              {{ $ingredient->name }}
              @if ($ingredient->note)<span class="ing-note">{{ $ingredient->note }}</span>@endif
            </span>
          </li>
        @endforeach
      </ul>
    @endif

    @if ($recipe->steps)
      <h2 class="section script">@include('recettes::partials.icon', ['name' => 'chef', 'size' => 24]) Recette</h2>
      <ol class="steps">
        @foreach ($recipe->steps as $step)
          <li><span>{{ $step }}</span></li>
        @endforeach
      </ol>
    @endif

    <div class="actions-bar">
      @unless ($recipe->isImporting())
        <form method="POST" action="{{ route('recettes.cart.store') }}">
          @csrf
          <input type="hidden" name="recipe_id" value="{{ $recipe->id }}">
          <input type="hidden" name="servings" value="{{ $servings }}" data-servings-input>
          <button class="btn wide">
            @include('recettes::partials.icon', ['name' => 'basket'])
            {{ $recipe->cartItem ? 'Mettre à jour le panier' : 'Ajouter au panier' }} · <span data-servings-label>{{ $servings }}</span> pers.
          </button>
        </form>
      @endunless
      <div class="actions-row">
        <a class="btn small ghost" href="{{ route('recettes.edit', $recipe) }}">@include('recettes::partials.icon', ['name' => 'edit', 'size' => 16]) Modifier</a>
        @if ($recipe->source_url)
          <a class="btn small ghost" href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer">@include('recettes::partials.icon', ['name' => 'link', 'size' => 16]) Source</a>
        @endif
        <form method="POST" action="{{ route('recettes.toggle', $recipe) }}">
          @csrf @method('PATCH')
          <button class="btn small ghost">
            @include('recettes::partials.icon', ['name' => $recipe->enabled ? 'eye-off' : 'eye', 'size' => 16]) {{ $recipe->enabled ? 'Masquer' : 'Réafficher' }}
          </button>
        </form>
      </div>
    </div>
  </main>
</div>
@endsection

@push('scripts')
<script>
(() => {
  const base = {{ $recipe->servings }};
  const output = document.getElementById('servings');
  const fractions = @js(\App\Modules\Recettes\Services\Quantity::FRACTIONS);

  /** Même affichage que App\Modules\Recettes\Services\Quantity::format(). */
  const format = (value) => {
    if (value >= 100) return String(Math.round(value));
    const whole = Math.floor(value);
    const rest = value - whole;
    if (value < 10 && rest > 0.01) {
      const match = fractions.find(([fraction]) => Math.abs(rest - fraction) < 0.02);
      if (match) return (whole > 0 ? whole : '') + match[1];
    }
    if (rest > 0.99) return String(whole + 1);
    return String(Math.round(value * 100) / 100).replace('.', ',');
  };

  const render = (servings) => {
    output.textContent = servings;
    document.querySelectorAll('[data-servings-input]').forEach((input) => { input.value = servings; });
    document.querySelectorAll('[data-servings-label]').forEach((label) => { label.textContent = servings; });
    document.querySelectorAll('.ing-amount[data-qty]').forEach((amount) => {
      const quantity = parseFloat(amount.dataset.qty) * servings / base;
      amount.textContent = (format(quantity) + ' ' + amount.dataset.unit).trim();
    });
    document.querySelector('[data-step="-1"]').disabled = servings <= 1;
  };

  document.querySelectorAll('[data-step]').forEach((button) => {
    button.addEventListener('click', () => {
      render(Math.min(100, Math.max(1, Number(output.textContent) + Number(button.dataset.step))));
    });
  });
  render(Number(output.textContent));
})();
</script>
@endpush
