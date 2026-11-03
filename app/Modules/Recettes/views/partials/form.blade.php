{{-- Formulaire complet d'une recette (création et modification). Paramètres : $recipe, $tags, $action, $method. --}}
@php
  $ingredientRows = old('ingredients', $recipe->ingredients->map->only(['emoji', 'quantity', 'unit', 'name', 'note'])->all());
  $ingredientRows = $ingredientRows ?: array_fill(0, 3, []);
  $stepRows = old('steps', $recipe->steps ?: ['']);
@endphp

@include('recettes::partials.errors')

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" id="recipeForm">
  @csrf
  @method($method)

  <section class="panel">
    <h2>L'essentiel</h2>
    <label class="field"><span>Nom</span>
      <input name="name" value="{{ old('name', $recipe->name) }}" required maxlength="150" placeholder="Salade tomates burrata">
    </label>
    <label class="field"><span>Description</span>
      <textarea name="description" maxlength="2000" placeholder="Une salade fraîche et gourmande, parfaite pour les beaux jours !">{{ old('description', $recipe->description) }}</textarea>
    </label>
    <div class="cols">
      <label class="field"><span>Personnes</span>
        <input type="number" name="servings" value="{{ old('servings', $recipe->servings) }}" min="1" max="100" required inputmode="numeric">
      </label>
      <label class="field"><span>Temps total (min)</span>
        <input type="number" name="total_minutes" value="{{ old('total_minutes', $recipe->total_minutes) }}" min="1" max="10000" inputmode="numeric" placeholder="45">
      </label>
    </div>
    <label class="field"><span>Lien de la recette (facultatif)</span>
      <input type="url" name="source_url" value="{{ old('source_url', $recipe->source_url) }}" maxlength="500" placeholder="https://…">
    </label>
  </section>

  <section class="panel">
    <h2>Photo</h2>
    <div class="photo">
      @if ($recipe->imageUrl())
        <img src="{{ $recipe->imageUrl() }}" alt="Photo actuelle">
      @endif
      <div class="field" style="flex: 1">
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" aria-label="Choisir une photo">
        @if ($recipe->imageUrl())
          <label class="switch-line"><input type="checkbox" name="remove_image" value="1"> Retirer la photo</label>
        @endif
      </div>
    </div>
  </section>

  @if ($tags->isNotEmpty())
    <section class="panel">
      <h2>Étiquettes</h2>
      @include('recettes::partials.tag-chips', ['checked' => old('tag_ids', $recipe->tags->pluck('id')->all())])
    </section>
  @endif

  <section class="panel">
    <h2>Ingrédients</h2>
    <p class="hint">Pour une quantité non chiffrée (« sel, poivre »), laissez la quantité vide. Une unité vide compte des pièces : « 4 tomates ».</p>
    <div class="rows" id="ingredientRows">
      @foreach ($ingredientRows as $i => $row)
        @include('recettes::partials.ingredient-row', ['index' => $i, 'row' => $row])
      @endforeach
    </div>
    <button type="button" class="btn small ghost add-row" data-add="ingredient">@include('recettes::partials.icon', ['name' => 'plus', 'size' => 16]) Ajouter un ingrédient</button>
  </section>

  <section class="panel">
    <h2>Étapes</h2>
    <div class="rows" id="stepRows">
      @foreach ($stepRows as $i => $step)
        @include('recettes::partials.step-row', ['index' => $i, 'step' => $step])
      @endforeach
    </div>
    <button type="button" class="btn small ghost add-row" data-add="step">@include('recettes::partials.icon', ['name' => 'plus', 'size' => 16]) Ajouter une étape</button>
  </section>

  <section class="panel">
    <input type="hidden" name="enabled" value="0">
    <label class="switch-line"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $recipe->enabled))> Afficher dans la liste des recettes</label>
  </section>

  <div class="form-actions">
    <a class="btn ghost" href="{{ $recipe->exists ? route('recettes.show', $recipe) : route('recettes.index') }}">Annuler</a>
    <button class="btn">Enregistrer</button>
  </div>
</form>

<template id="ingredientTemplate">@include('recettes::partials.ingredient-row', ['index' => '__INDEX__', 'row' => []])</template>
<template id="stepTemplate">@include('recettes::partials.step-row', ['index' => '__INDEX__', 'step' => ''])</template>

<datalist id="units">
  @foreach (['g', 'kg', 'ml', 'cl', 'l', 'c. à soupe', 'c. à café', 'pincée', 'gousse', 'tranche', 'botte', 'boîte', 'sachet', 'quelques feuilles', 'un filet'] as $unit)
    <option value="{{ $unit }}">
  @endforeach
</datalist>

@push('scripts')
<script>
(() => {
  const lists = { ingredient: document.getElementById('ingredientRows'), step: document.getElementById('stepRows') };
  let next = Date.now();

  const renumber = () => {
    lists.step.querySelectorAll('.num').forEach((num, i) => { num.textContent = i + 1; });
  };

  document.querySelectorAll('[data-add]').forEach((button) => {
    button.addEventListener('click', () => {
      const type = button.dataset.add;
      const html = document.getElementById(type + 'Template').innerHTML.replaceAll('__INDEX__', String(next++));
      lists[type].insertAdjacentHTML('beforeend', html);
      lists[type].lastElementChild.querySelector('input:not([type=hidden]), textarea')?.focus();
      renumber();
    });
  });

  document.getElementById('recipeForm').addEventListener('click', (event) => {
    const remove = event.target.closest('[data-remove]');
    if (!remove) return;
    remove.closest('[data-row]').remove();
    renumber();
  });
})();
</script>
@endpush
