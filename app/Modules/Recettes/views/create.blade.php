@extends('recettes::layout')

@section('title', 'Nouvelle recette · Mes Recettes')

@section('content')
<div class="page narrow">
  @include('recettes::partials.topbar', ['backUrl' => route('recettes.index'), 'backLabel' => 'Recettes'])

  <h1 class="page-title script">Nouvelle recette</h1>

  <nav class="tabs" aria-label="Mode d'ajout">
    <a href="{{ route('recettes.create') }}" @if ($mode === 'auto') aria-current="page" @endif>@include('recettes::partials.icon', ['name' => 'sparkles', 'size' => 18]) Depuis un lien</a>
    <a href="{{ route('recettes.create', ['mode' => 'manuel']) }}" @if ($mode === 'manual') aria-current="page" @endif>@include('recettes::partials.icon', ['name' => 'pen', 'size' => 18]) À la main</a>
  </nav>

  @if ($mode === 'auto')
    @include('recettes::partials.errors', ['bag' => $errors->import])

    <form method="POST" action="{{ route('recettes.import.store') }}">
      @csrf
      <section class="panel">
        <h2>Importer depuis une page web</h2>
        <p class="hint">L'IA lit la page et remplit les ingrédients, les étapes, le temps, le nombre de personnes et la photo. Vous pourrez tout modifier ensuite.</p>
        <label class="field"><span>Nom</span>
          <input name="name" value="{{ old('name') }}" required maxlength="150" placeholder="Pâtes à l'ail et parmesan">
        </label>
        <label class="field"><span>Lien de la recette</span>
          <input type="url" name="source_url" value="{{ old('source_url') }}" required maxlength="500" placeholder="https://www.marmiton.org/recettes/…">
        </label>
        @if ($tags->isNotEmpty())
          <div class="field"><span>Étiquettes (l'IA peut en ajouter d'autres parmi celles-ci)</span>
            @include('recettes::partials.tag-chips', ['checked' => old('tag_ids', [])])
          </div>
        @endif
      </section>
      <div class="form-actions">
        <button class="btn">@include('recettes::partials.icon', ['name' => 'sparkles', 'size' => 18]) Importer la recette</button>
      </div>
    </form>
  @else
    @include('recettes::partials.form', ['action' => route('recettes.store'), 'method' => 'POST'])
  @endif
</div>
@endsection
