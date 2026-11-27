{{-- Tuile d'une séance de fitness : date, durée, effort, poids total soulevé, muscles travaillés. Paramètre : $workout (séries et exercices chargés). --}}
<a class="tile" href="{{ route('coach.fitness.workouts.show', $workout) }}">
  <div class="tile-head">
    <span class="tile-icon">@include('coach::partials.icon', ['name' => 'dumbbell'])</span>
    <span>
      <span class="tile-type">Séance de salle</span>
      <span class="muted small">{{ ucfirst($workout->performed_on->locale('fr')->isoFormat('dddd D MMMM YYYY')) }}</span>
    </span>
  </div>
  <div class="tile-stats">
    <div><span class="muted small">Soulevé</span><strong>{{ $workout->totalLoadLabel() }}</strong></div>
    @if ($workout->duration_minutes)<div><span class="muted small">Durée</span><strong>{{ $workout->duration_minutes }} min</strong></div>@endif
    @if ($workout->rpe)<div><span class="muted small">Effort</span><strong>{{ $workout->rpe }}/10</strong></div>@endif
    <div><span class="muted small">Séries</span><strong>{{ $workout->sets->count() }}</strong></div>
  </div>
  @if ($workout->muscles()->isNotEmpty())
    <div class="muscles">@foreach ($workout->muscles() as $label)<span class="muscle">{{ $label }}</span>@endforeach</div>
  @endif
</a>
