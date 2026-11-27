@extends('coach::layout')

@section('title', 'Ma salle · Dada Coach')
@section('page-class', 'narrow')

@php
  use App\Modules\Coach\Models\Equipment;
  use App\Modules\Coach\Models\Exercise;
@endphp

@section('content')
<h1>Fitness</h1>
@include('coach::fitness.subnav')

@if ($errors->any())
  <div class="errors section" role="alert">
    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
  </div>
@endif

<section class="card section" id="machines">
  <h2>Machines</h2>
  <p class="muted small">Ton coach les découvre avec toi : quand il propose un exercice, tu lui dis si la machine est dans ta salle.</p>
  @if ($equipment->isEmpty())
    <p class="muted">Aucune machine pour l'instant.</p>
  @else
    <ul class="list">
      @foreach ($equipment as $item)
        <li>
          <span class="what">
            <strong>{{ $item->name }}</strong>
            <span class="muted small">· {{ Equipment::STATUSES[$item->status] }}{{ $item->load_step_kg ? ' · pas de '.\App\Modules\Coach\Models\WorkoutSet::kg($item->load_step_kg) : '' }}</span>
          </span>
          <span class="actions" style="margin:0">
            <form method="POST" action="{{ route('coach.fitness.equipment.update', $item) }}" class="inline">
              @csrf
              @method('PATCH')
              <input type="hidden" name="status" value="{{ $item->isAvailable() ? 'missing' : 'available' }}">
              <input type="hidden" name="load_step_kg" value="{{ $item->load_step_kg }}">
              <button class="btn ghost small">{{ $item->isAvailable() ? 'Pas dans ma salle' : 'Je l\'ai' }}</button>
            </form>
            @if (! $item->exercises_count)
              <form method="POST" action="{{ route('coach.fitness.equipment.destroy', $item) }}" class="inline">
                @csrf
                @method('DELETE')
                <button class="btn danger small" title="Retirer">✕<span class="sr-only"> Retirer {{ $item->name }}</span></button>
              </form>
            @endif
          </span>
        </li>
      @endforeach
    </ul>
  @endif
  <details class="add">
    <summary>Ajouter une machine</summary>
    <form method="POST" action="{{ route('coach.fitness.equipment.store') }}" class="row add-row">
      @csrf
      <label class="field"><span>Nom</span><input name="name" required maxlength="100" placeholder="Presse à cuisses"></label>
      <label class="field"><span>Pas de charge (kg, facultatif)</span><input type="number" name="load_step_kg" min="0.25" max="50" step="0.25" placeholder="5"></label>
      <div><button class="btn small">Ajouter</button></div>
    </form>
  </details>
</section>

<section class="card section" id="exercices">
  <h2>Exercices</h2>
  @if ($exercises->isEmpty())
    <p class="muted">Aucun exercice pour l'instant.</p>
  @else
    <ul class="list">
      @foreach ($exercises as $exercise)
        <li class="exercise-item">
          <details>
            <summary>
              <strong>{{ $exercise->name }}</strong>
              <span class="muted small">· {{ $exercise->equipment?->name ?? 'poids du corps' }} · {{ $exercise->musclesLabel() }}{{ $exercise->unilateral ? ' · par côté' : '' }}</span>
            </summary>
            @if ($exercise->notes)<p class="small desc">{{ $exercise->notes }}</p>@endif
            <form method="POST" action="{{ route('coach.fitness.exercises.update', $exercise) }}" class="form">
              @csrf
              @method('PUT')
              @include('coach::fitness.exercise-fields', ['exercise' => $exercise])
              <div class="actions"><button class="btn small">Enregistrer</button></div>
            </form>
            <form method="POST" action="{{ route('coach.fitness.exercises.destroy', $exercise) }}" class="actions" onsubmit="return confirm('Retirer cet exercice ?')">
              @csrf
              @method('DELETE')
              <button class="btn danger small">Retirer l'exercice</button>
            </form>
          </details>
        </li>
      @endforeach
    </ul>
  @endif
  <details class="add" @if (old('measure')) open @endif>
    <summary>Ajouter un exercice</summary>
    <form method="POST" action="{{ route('coach.fitness.exercises.store') }}" class="form">
      @csrf
      @include('coach::fitness.exercise-fields', ['exercise' => null])
      <div class="actions"><button class="btn small">Ajouter</button></div>
    </form>
  </details>
</section>
@endsection
