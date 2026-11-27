@extends('coach::layout')

@section('title', 'Nouvelle séance · Dada Coach')
@section('page-class', 'narrow')

@php
  // Lignes à afficher : celles déjà saisies (après une erreur), sinon trois lignes vides.
  $rows = array_values(old('entries', [])) ?: array_fill(0, 3, []);
@endphp

@section('content')
<h1>Nouvelle séance</h1>
@include('coach::fitness.subnav')

@if ($exercises->isEmpty())
  <p class="card muted">Ton catalogue est vide : ajoute d'abord des exercices dans <a href="{{ route('coach.fitness.gym') }}">Ma salle</a>, ou demande à ton coach de t'en proposer.</p>
@else
<form method="POST" action="{{ route('coach.fitness.workouts.store') }}" class="card form">
  @csrf

  @if ($errors->any())
    <div class="errors" role="alert">
      @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
  @endif

  <div class="row">
    <label class="field"><span>Date</span><input type="date" name="performed_on" required max="{{ today()->toDateString() }}" value="{{ old('performed_on', today()->toDateString()) }}"></label>
    <label class="field"><span>Durée (min)</span><input type="number" name="duration_minutes" min="5" max="300" value="{{ old('duration_minutes') }}"></label>
    <label class="field"><span>Effort ressenti (1 à 10)</span><input type="number" name="rpe" min="1" max="10" value="{{ old('rpe') }}"></label>
  </div>

  <h2 style="margin:8px 0 0">Exercices</h2>
  <p class="muted small">Une ligne par exercice ; des séries différentes (10 à 100 kg puis 8 à 105 kg) font deux lignes.</p>
  <div class="entries" id="entries">
    @foreach ($rows as $i => $row)
      @include('coach::fitness.entry-row', ['index' => $i, 'row' => $row])
    @endforeach
  </div>
  <template id="entry-template">@include('coach::fitness.entry-row', ['index' => '__INDEX__', 'row' => []])</template>
  <div><button type="button" class="btn ghost small" id="add-entry">+ Ajouter un exercice</button></div>

  <label class="field"><span>Un mot sur la séance (facultatif)</span><textarea name="notes" maxlength="2000">{{ old('notes') }}</textarea></label>

  <div class="actions"><button class="btn">Enregistrer</button></div>
</form>
@endif
@endsection

@push('scripts')
<script>
(() => {
  const list = document.getElementById('entries');
  if (!list) return;
  // Champs utiles selon ce que mesure l'exercice : charge et répétitions, répétitions seules, ou durée.
  const sync = (row) => {
    const measure = row.querySelector('select').selectedOptions[0]?.dataset.measure || 'load_reps';
    row.querySelector('[data-field="reps"]').hidden = measure === 'duration';
    row.querySelector('[data-field="load"]').hidden = measure === 'reps';
    row.querySelector('[data-field="duration"]').hidden = measure !== 'duration';
  };
  list.addEventListener('change', (event) => { if (event.target.matches('select')) sync(event.target.closest('.entry')); });
  list.querySelectorAll('.entry').forEach(sync);
  let next = list.children.length;
  document.getElementById('add-entry').addEventListener('click', () => {
    const html = document.getElementById('entry-template').innerHTML.replaceAll('__INDEX__', next++);
    list.insertAdjacentHTML('beforeend', html);
    sync(list.lastElementChild);
    list.lastElementChild.querySelector('select').focus();
  });
})();
</script>
@endpush
