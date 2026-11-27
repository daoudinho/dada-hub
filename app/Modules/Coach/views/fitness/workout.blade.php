@extends('coach::layout')

@section('title', 'Séance du '.$workout->performed_on->format('d/m').' · Dada Coach')
@section('page-class', 'narrow')

@section('content')
<p><a href="{{ route('coach.fitness.index') }}" class="small">← Toutes les séances</a></p>
<h1>{{ ucfirst($workout->performed_on->locale('fr')->isoFormat('dddd D MMMM YYYY')) }}</h1>

<section class="card section">
  <div class="tile-stats">
    <div><span class="muted small">Soulevé</span><strong>{{ $workout->totalLoadLabel() }}</strong></div>
    @if ($workout->duration_minutes)<div><span class="muted small">Durée</span><strong>{{ $workout->duration_minutes }} min</strong></div>@endif
    @if ($workout->rpe)<div><span class="muted small">Effort</span><strong>{{ $workout->rpe }}/10</strong></div>@endif
    <div><span class="muted small">Séries</span><strong>{{ $workout->sets->count() }}</strong></div>
  </div>
  @if ($workout->muscles()->isNotEmpty())
    <div class="muscles">@foreach ($workout->muscles() as $label)<span class="muscle">{{ $label }}</span>@endforeach</div>
  @endif
  @if ($workout->notes)<p class="desc">{{ $workout->notes }}</p>@endif
</section>

<div class="stack">
  @foreach ($workout->blocks() as $block)
    <section class="card">
      <div class="card-head">
        <h2>{{ $block['exercise']->name }}</h2>
        @if ($block['load_kg'])<strong>{{ number_format($block['load_kg'], 0, ',', ' ') }} kg</strong>@endif
      </div>
      <p class="muted small">{{ $block['exercise']->equipment?->name ?? 'Poids du corps' }} · {{ $block['exercise']->musclesLabel() }}{{ $block['exercise']->unilateral ? ' · de chaque côté' : '' }}</p>
      <table class="table">
        <thead><tr><th>Série</th><th>{{ $block['exercise']->measure === 'duration' ? 'Durée' : 'Répétitions' }}</th>@if ($block['sets']->contains(fn ($set) => $set->load_kg))<th>Charge</th>@endif</tr></thead>
        <tbody>
          @foreach ($block['sets'] as $set)
            <tr>
              <td>{{ $loop->iteration }}</td>
              <td>{{ $set->duration_seconds ? $set->duration_seconds.' s' : $set->reps }}</td>
              @if ($block['sets']->contains(fn ($set) => $set->load_kg))<td>{{ $set->load_kg ? \App\Modules\Coach\Models\WorkoutSet::kg($set->load_kg) : '—' }}</td>@endif
            </tr>
          @endforeach
        </tbody>
      </table>
    </section>
  @endforeach
</div>

<form method="POST" action="{{ route('coach.fitness.workouts.destroy', $workout) }}" class="actions" onsubmit="return confirm('Supprimer cette séance ?')">
  @csrf
  @method('DELETE')
  <button class="btn danger small">Supprimer la séance</button>
</form>
@endsection
