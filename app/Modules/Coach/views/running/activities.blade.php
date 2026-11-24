@extends('coach::layout')

@section('title', 'Historique · Dada Coach')
@section('page-class', 'narrow')

@section('content')
<div class="card-head">
  <h1>Historique</h1>
  @if ($running)
    <a class="btn small" href="{{ route('coach.running.activities.create') }}">Ajouter une course</a>
  @endif
</div>

@if (! $running)
  <p class="card muted">Aucun sous-module ouvert pour l'instant : demande à l'administrateur d'activer Running ou Fitness.</p>
@elseif ($activities->isEmpty())
  <p class="card muted">Aucune séance pour l'instant. Ajoute ta clé Garmin dans ton profil, ou une course à la main.</p>
@else
  <div class="tiles">
    @foreach ($activities as $activity)
      @include('coach::running.tile', ['activity' => $activity])
    @endforeach
  </div>
  <div class="pager">
    @if ($activities->previousPageUrl())<a class="btn ghost small" href="{{ $activities->previousPageUrl() }}">← Plus récentes</a>@endif
    @if ($activities->nextPageUrl())<a class="btn ghost small" href="{{ $activities->nextPageUrl() }}">Plus anciennes →</a>@endif
  </div>
@endif
@endsection
