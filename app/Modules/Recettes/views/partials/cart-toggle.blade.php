{{-- Bouton rond qui ajoute la recette au panier ou l'en retire. Paramètres : $recipe (avec cartItem chargé), $class. --}}
@php($inCart = $recipe->cartItem !== null)
<form method="POST" action="{{ $inCart ? route('recettes.cart.destroy', $recipe) : route('recettes.cart.store') }}">
  @csrf
  @if ($inCart)
    @method('DELETE')
  @else
    <input type="hidden" name="recipe_id" value="{{ $recipe->id }}">
  @endif
  <button class="round {{ $class ?? '' }} {{ $inCart ? 'on' : '' }}" title="{{ $inCart ? 'Retirer du panier' : 'Ajouter au panier' }}">
    @include('recettes::partials.icon', ['name' => $inCart ? 'basket-check' : 'basket'])
    <span class="sr-only">{{ $inCart ? 'Retirer « '.$recipe->name.' » du panier' : 'Ajouter « '.$recipe->name.' » au panier' }}</span>
  </button>
</form>
