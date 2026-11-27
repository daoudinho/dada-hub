{{-- Sous-onglets de l'onglet Fitness. --}}
<nav class="subnav" aria-label="Fitness">
  <a href="{{ route('coach.fitness.index') }}" @class(['on' => request()->routeIs('coach.fitness.index', 'coach.fitness.workouts.*')])>Séances</a>
  <a href="{{ route('coach.fitness.gym') }}" @class(['on' => request()->routeIs('coach.fitness.gym')])>Ma salle</a>
</nav>
