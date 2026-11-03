{{-- Ligne d'ingrédient du formulaire. Paramètres : $index, $row (emoji, quantity, unit, name, note). --}}
@php($quantity = $row['quantity'] ?? null)
<div class="ing-row" data-row>
  <input class="row-input r-emoji" name="ingredients[{{ $index }}][emoji]" value="{{ $row['emoji'] ?? '' }}" maxlength="16" placeholder="🍅" aria-label="Emoji">
  <input class="row-input r-qty" name="ingredients[{{ $index }}][quantity]" value="{{ is_numeric($quantity) ? str_replace('.', ',', (string) (float) $quantity) : $quantity }}" inputmode="decimal" placeholder="Qté" aria-label="Quantité">
  <input class="row-input r-unit" name="ingredients[{{ $index }}][unit]" value="{{ $row['unit'] ?? '' }}" maxlength="30" list="units" placeholder="Unité" aria-label="Unité">
  <input class="row-input r-name" name="ingredients[{{ $index }}][name]" value="{{ $row['name'] ?? '' }}" maxlength="120" placeholder="Ingrédient" aria-label="Ingrédient">
  <input class="row-input r-note" name="ingredients[{{ $index }}][note]" value="{{ $row['note'] ?? '' }}" maxlength="120" placeholder="Précision (bien mûres…)" aria-label="Précision">
  <button type="button" class="del r-del" data-remove title="Retirer l'ingrédient">@include('recettes::partials.icon', ['name' => 'close', 'size' => 18])<span class="sr-only">Retirer l'ingrédient</span></button>
</div>
