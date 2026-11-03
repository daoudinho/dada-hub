@extends('recettes::layout')

@section('title', 'Modifier · '.$recipe->name)

@section('content')
<div class="page narrow">
  @include('recettes::partials.topbar', ['backUrl' => route('recettes.show', $recipe), 'backLabel' => 'Recette'])

  <h1 class="page-title script">Modifier la recette</h1>

  @include('recettes::partials.form', ['action' => route('recettes.update', $recipe), 'method' => 'PUT'])

  <div class="danger-zone">
    @if ($recipe->source_url)
      <form method="POST" action="{{ route('recettes.import.retry', $recipe) }}" onsubmit="return confirm('Remplacer le contenu par celui de la page, relu par l\'IA ?')">
        @csrf
        <button class="btn small ghost">@include('recettes::partials.icon', ['name' => 'refresh', 'size' => 16]) Ré-importer depuis le lien</button>
      </form>
    @endif
    <form method="POST" action="{{ route('recettes.destroy', $recipe) }}" onsubmit="return confirm('Supprimer définitivement « {{ addslashes($recipe->name) }} » ?')">
      @csrf @method('DELETE')
      <button class="btn small danger">@include('recettes::partials.icon', ['name' => 'trash', 'size' => 16]) Supprimer</button>
    </form>
  </div>
</div>
@endsection
