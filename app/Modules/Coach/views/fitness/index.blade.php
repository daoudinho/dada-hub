@extends('coach::layout')

@section('title', 'Fitness · Dada Coach')
@section('page-class', 'narrow')

@section('content')
<div class="card-head">
  <h1>Fitness</h1>
  @if ($hasExercises)
    <a class="btn small" href="{{ route('coach.fitness.workouts.create') }}">Nouvelle séance</a>
  @endif
</div>
@include('coach::fitness.subnav')

@if ($workouts->isEmpty())
  <div class="card">
    @if ($hasExercises)
      <p class="muted">Aucune séance pour l'instant. Ajoute ta première séance, ou raconte-la à ton coach : « aujourd'hui 3 séries de presse à 100 kg ».</p>
    @else
      <p>Ta salle est encore vide.</p>
      <p class="muted">Demande à ton coach de te proposer des exercices : pour chacun, tu lui dis si la machine est dans ta salle. Tu peux aussi les ajouter toi-même dans <a href="{{ route('coach.fitness.gym') }}">Ma salle</a>.</p>
      <div class="actions"><a class="btn small" href="{{ route('coach.chat.index') }}">Parler au coach</a></div>
    @endif
  </div>
@else
  <div class="tiles">
    @foreach ($workouts as $workout)
      @include('coach::fitness.tile', ['workout' => $workout])
    @endforeach
  </div>
  <div class="pager">
    @if ($workouts->previousPageUrl())<a class="btn ghost small" href="{{ $workouts->previousPageUrl() }}">← Plus récentes</a>@endif
    @if ($workouts->nextPageUrl())<a class="btn ghost small" href="{{ $workouts->nextPageUrl() }}">Plus anciennes →</a>@endif
  </div>
@endif
@endsection
