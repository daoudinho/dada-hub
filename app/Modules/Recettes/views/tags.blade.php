@extends('recettes::layout')

@section('title', 'Étiquettes · Mes Recettes')

@section('content')
<div class="page narrow">
  @include('recettes::partials.topbar', ['backUrl' => route('recettes.index'), 'backLabel' => 'Recettes'])

  <h1 class="page-title script">Étiquettes</h1>

  <form method="POST" action="{{ route('recettes.tags.store') }}" class="panel">
    @csrf
    <h2>Nouvelle étiquette</h2>
    <p class="hint">Elles servent de filtres dans la liste. Lors d'un import, l'IA choisit parmi elles.</p>
    @if ($errors->create->any())
      <p class="error" role="alert">{{ $errors->create->first() }}</p>
    @endif
    <div class="tag-row new">
      <input class="row-input emoji-input" name="emoji" value="{{ old('emoji') }}" maxlength="16" placeholder="☀️" aria-label="Emoji">
      <input class="row-input" name="name" value="{{ old('name') }}" required maxlength="40" placeholder="Été" aria-label="Nom">
      <button class="btn small">Ajouter</button>
    </div>
  </form>

  <div class="tag-list">
    @forelse ($tags as $tag)
      <form method="POST" action="{{ route('recettes.tags.update', $tag) }}" class="tag-row">
        @csrf @method('PATCH')
        <input class="row-input emoji-input" name="emoji" value="{{ $tag->emoji }}" maxlength="16" aria-label="Emoji de « {{ $tag->name }} »">
        <input class="row-input" name="name" value="{{ $tag->name }}" required maxlength="40" aria-label="Nom">
        <span class="count">{{ $tag->recipes_count }} recette{{ $tag->recipes_count > 1 ? 's' : '' }}</span>
        <span class="tag-actions">
          <button class="del" title="Enregistrer">@include('recettes::partials.icon', ['name' => 'check', 'size' => 18])<span class="sr-only">Enregistrer « {{ $tag->name }} »</span></button>
          <button class="del" form="delete-tag-{{ $tag->id }}" title="Supprimer">@include('recettes::partials.icon', ['name' => 'trash', 'size' => 18])<span class="sr-only">Supprimer « {{ $tag->name }} »</span></button>
        </span>
        @if ($errors->{'edit-'.$tag->id}->any())
          <p class="error">{{ $errors->{'edit-'.$tag->id}->first() }}</p>
        @endif
      </form>
      <form method="POST" action="{{ route('recettes.tags.destroy', $tag) }}" id="delete-tag-{{ $tag->id }}" hidden onsubmit="return confirm('Supprimer l\'étiquette « {{ addslashes($tag->name) }} » ? Les recettes sont conservées.')">
        @csrf @method('DELETE')
      </form>
    @empty
      <p class="empty">Aucune étiquette.</p>
    @endforelse
  </div>
</div>
@endsection
