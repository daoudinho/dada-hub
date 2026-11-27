@extends('coach::layout')

@section('title', 'Accueil · Dada Coach')
@section('page-class', 'narrow')

@php use App\Modules\Coach\Services\Duration; @endphp

@section('content')
<div class="stack">
  @if ($fitness)
    {{-- Fitness : raccourci vers l'onglet, avec la dernière séance --}}
    <a class="card fitness-link" href="{{ route('coach.fitness.index') }}">
      <span class="tile-icon">@include('coach::partials.icon', ['name' => 'dumbbell'])</span>
      <span>
        <strong>Fitness</strong><br>
        <span class="muted small">
          @if ($lastWorkout)
            Dernière séance {{ $lastWorkout->performed_on->locale('fr')->isoFormat('ddd D MMM') }} · {{ $lastWorkout->totalLoadLabel() }} soulevés
          @else
            Ta salle, tes exercices et tes séances
          @endif
        </span>
      </span>
      <span class="fitness-arrow" aria-hidden="true">›</span>
    </a>
  @endif

  {{-- Objectif --}}
  <section class="card hero" aria-labelledby="goal-title">
    @if ($goal)
      <p class="muted small" id="goal-title">Objectif</p>
      <p class="big">{{ $goal->countdown() }}</p>
      <p class="muted">
        {{ $goal->title }}@if ($goal->event_date) · {{ $goal->event_date->locale('fr')->isoFormat('dddd D MMMM YYYY') }}@endif
      </p>
      @foreach ($intermediateGoals as $intermediate)
        <p class="small muted">Étape : {{ $intermediate->countdown() }}</p>
      @endforeach
    @elseif ($start)
      <p class="big" id="goal-title">Bienvenue !</p>
      <p class="muted">Trois étapes pour que ton coach te prépare un programme sur mesure.</p>
      <ol class="steps">
        <li class="step {{ $start['profile']['done'] ? 'done' : '' }}">
          <span class="step-mark" aria-hidden="true">{{ $start['profile']['done'] ? '✓' : '1' }}</span>
          <div>
            <a href="{{ route('coach.profile.edit') }}#profil"><strong>Remplis ton profil</strong></a>
            <p class="small muted">{{ $start['profile']['done'] ? 'C\'est fait. Tu peux le compléter à tout moment (courses passées, blessures…).' : 'Il manque : '.implode(', ', $start['profile']['missing']).'.' }}</p>
          </div>
        </li>
        <li class="step {{ $start['watch']['state'] === 'done' ? 'done' : '' }} {{ $start['watch']['state'] === 'pending' ? 'pending' : '' }}">
          <span class="step-mark" aria-hidden="true">{{ $start['watch']['state'] === 'done' ? '✓' : '2' }}</span>
          <div>
            <a href="{{ route('coach.profile.edit') }}#garmin"><strong>Relie ta montre</strong></a>
            <p class="small muted">{{ $start['watch']['detail'] }}</p>
          </div>
        </li>
        <li class="step">
          <span class="step-mark" aria-hidden="true">3</span>
          <div>
            <strong>Fais connaissance avec ton coach</strong>
            <p class="small muted">Il te posera quelques questions, t'aidera à fixer un objectif, puis te proposera des tests et un programme.</p>
            <form method="POST" action="{{ route('coach.chat.store') }}" class="actions">
              @csrf
              <button class="btn {{ $start['ready'] ? 'accent' : 'ghost' }}">Commencer</button>
            </form>
            @unless ($start['ready'])
              <p class="small muted">Tu peux commencer dès maintenant, mais le coach en saura moins sur toi.</p>
            @endunless
          </div>
        </li>
      </ol>
      @if ($start['watch']['state'] === 'pending')
        @push('scripts')
          <script>setTimeout(() => location.reload(), 20000);</script>
        @endpush
      @endif
    @else
      <p class="big" id="goal-title">Pas encore d'objectif</p>
      <p class="muted">Reprends la discussion avec ton coach pour le définir.</p>
      <div class="actions"><a class="btn accent" href="{{ route('coach.chat.index') }}">Parler au coach</a></div>
    @endif
  </section>

  {{-- Dernière course : ressenti → débrief → bilan --}}
  @if ($lastRun)
    <section class="card {{ $lastRun->review ? '' : 'alert' }}" aria-labelledby="last-run-title">
      <div class="card-head">
        <h2 id="last-run-title">Ta dernière course</h2>
        <a class="small" href="{{ route('coach.running.activities.show', $lastRun) }}">Voir la fiche</a>
      </div>
      <p>
        <strong>{{ number_format($lastRun->distanceKm(), 1, ',', '') }} km</strong> · {{ Duration::format($lastRun->moving_seconds) }}@if ($lastRun->pace()) · {{ $lastRun->pace() }}@endif
        <span class="muted small">· {{ ucfirst($lastRun->started_at->locale('fr')->isoFormat('ddd D MMM')) }}@if ($lastRun->session) · {{ $lastRun->session->title }}@endif</span>
      </p>
      @include('coach::partials.record-badges', ['activity' => $lastRun])
      @include('coach::partials.session-suggestion', ['activity' => $lastRun])
      @php($debriefDone = $lastRun->review || $lastRun->debrief?->status === 'closed')
      <ol class="after-steps" aria-label="Après la course">
        <li class="{{ $lastRunHasFeedback ? 'done' : 'current' }}">{{ $lastRunHasFeedback ? '✓ ' : '' }}Ressenti</li>
        <li class="{{ $debriefDone ? 'done' : ($lastRunHasFeedback ? 'current' : '') }}">{{ $debriefDone ? '✓ ' : '' }}Débrief</li>
        <li class="{{ $lastRun->review ? 'done' : '' }}">{{ $lastRun->review ? '✓ ' : '' }}Bilan</li>
      </ol>
      @if (! $lastRunHasFeedback)
        <form method="POST" action="{{ route('coach.running.feedback.store', $lastRun) }}" class="form">
          @csrf
          @include('coach::partials.quick-feedback', ['required' => true, 'measuredSleep' => $lastRunNight])
          <div><button class="btn">Noter</button></div>
        </form>
      @elseif (! $lastRun->review)
        <p class="muted">Fais le point avec ton coach : quelques questions sur tes sensations, puis il rédige le bilan.</p>
        <form method="POST" action="{{ route('coach.running.activities.debrief', $lastRun) }}" class="actions">
          @csrf
          <button class="btn accent">{{ $lastRun->debrief?->status === 'open' ? 'Reprendre le débrief' : 'Débriefer avec le coach' }}</button>
        </form>
      @else
        <p><span class="verdict verdict-{{ $lastRun->review['verdict'] }}">{{ $lastRun->verdictLabel() }}</span></p>
        <p class="muted">{{ $lastRun->review['summary'] }}</p>
      @endif
    </section>
  @endif

  {{-- Ce qui attend une action --}}
  @foreach ($awaiting as $conversation)
    <section class="card alert">
      <div class="card-head">
        <h2>{{ $conversation->kind === 'debrief' ? 'Débrief en attente' : 'Retour sur une séance annulée' }}</h2>
        <span class="muted small">{{ $conversation->created_at->format('d/m') }}</span>
      </div>
      <p class="muted">{{ $conversation->title }}</p>
      <a class="btn" href="{{ route('coach.chat.show', $conversation) }}">{{ $conversation->status === 'pending' ? 'Faire le point avec le coach' : 'Reprendre' }}</a>
    </section>
  @endforeach

  @if (! $start && (! $profile || empty($profile->availability)))
    <section class="card">
      <h2>Complète ton profil</h2>
      <p class="muted">Historique, courses passées, blessures, disponibilités : ton coach s'en sert pour bâtir un programme à ta mesure.</p>
      <a class="btn" href="{{ route('coach.profile.edit') }}">Remplir mon profil</a>
    </section>
  @endif

  @if ($running)
    {{-- Prochaines séances (7 jours) : un clic ouvre le détail dans le calendrier --}}
    <section class="card">
      <div class="card-head">
        <h2>Prochaines séances</h2>
        <a class="small" href="{{ route('coach.running.calendar') }}">Calendrier</a>
      </div>
      @if ($upcoming->isEmpty())
        <p class="muted">Aucune séance prévue.</p>
      @else
        <ul class="list">
          @foreach ($upcoming as $session)
            <li>
              <span class="when">{{ $session->scheduled_on->isToday() ? 'Auj.' : ucfirst($session->scheduled_on->locale('fr')->isoFormat('ddd D')) }}</span>
              <a class="what" href="{{ route('coach.running.calendar', ['seance' => $session->id]) }}"><strong>{{ $session->title }}</strong><br><span class="muted small">{{ $session->typeLabel() }}@if ($session->targets()) · {{ $session->targets() }}@endif</span></a>
              @if (! $autoSync && $session->scheduled_on->lte(today()))
                <a class="btn small" href="{{ route('coach.running.activities.create', ['seance' => $session->id]) }}">Faite</a>
              @endif
            </li>
          @endforeach
        </ul>
      @endif
    </section>

    {{-- Historique --}}
    <section class="card">
      <div class="card-head">
        <h2>Historique</h2>
        @if ($recentRuns->isNotEmpty())
          <a class="small" href="{{ route('coach.running.activities.index') }}">Voir plus</a>
        @endif
      </div>
      @if ($recentRuns->isEmpty())
        <p class="muted">Aucune séance pour l'instant.</p>
      @else
        <ul class="list runs">
          @foreach ($recentRuns as $run)
            <li>
              <a class="what" href="{{ route('coach.running.activities.show', $run) }}">
                <span class="when">{{ ucfirst($run->started_at->locale('fr')->isoFormat('ddd D MMM')) }}</span>
                <strong>{{ $run->name ?: ($run->session?->title ?? $run->typeLabel()) }}</strong>
                @if ($run->new_records)<span class="pill pending" title="Nouveau record">🏆 Record</span>@endif<br>
                <span class="muted small">{{ number_format($run->distanceKm(), 1, ',', '') }} km · {{ Duration::format($run->moving_seconds) }}@if ($run->pace()) · {{ $run->pace() }}@endif</span>
              </a>
            </li>
          @endforeach
        </ul>
      @endif
    </section>

    {{-- Exploits --}}
    @if ($exploits)
      <section class="card">
        <div class="card-head">
          <h2>Exploits</h2>
          <a class="small" href="{{ route('coach.exploits') }}">Voir tout</a>
        </div>
        <ul class="records">
          @foreach ($exploits as $exploit)
            <li>
              <a href="{{ $exploit['activity'] ? route('coach.running.activities.show', $exploit['activity']) : route('coach.exploits') }}">
                <span>{{ $exploit['label'] }}</span>
                <strong>{{ $exploit['value'] }}</strong>
                <span class="muted small">{{ $exploit['week']
                    ? 'Semaine du '.$exploit['week']['start']->locale('fr')->isoFormat('D MMM YYYY')
                    : $exploit['activity']->started_at->locale('fr')->isoFormat('D MMM YYYY') }}</span>
                <span class="muted small" style="text-align:right">{{ $exploit['detail'] }}</span>
              </a>
            </li>
          @endforeach
        </ul>
      </section>
    @endif

    {{-- Kilomètres par semaine --}}
    <section class="card">
      <div class="card-head">
        <h2>Kilomètres par semaine</h2>
        <span class="muted small">{{ number_format($distanceChart['total'], 1, ',', '') }} km en {{ count($distanceChart['bars']) }} semaines</span>
      </div>
      @include('coach::partials.distance-chart', ['chart' => $distanceChart])
    </section>
  @endif

  @if ($fitness)
    {{-- Poids (le reste du Fitness viendra plus tard) --}}
    <section class="card">
      <div class="card-head">
        <h2>Poids</h2>
        @if ($latestWeight)
          <span><span class="stat">{{ number_format($latestWeight->weight_kg, 1, ',', '') }}</span> kg <span class="muted small">le {{ $latestWeight->measured_on->format('d/m') }}</span></span>
        @endif
      </div>
      @if ($weightChart)
        @include('coach::partials.weight-chart', ['chart' => $weightChart, 'entries' => $weights])
      @else
        <p class="muted">Ajoute ta première pesée pour voir ta courbe.</p>
      @endif
      <form method="POST" action="{{ route('coach.weights.store') }}" class="weight-form">
        @csrf
        <label class="field"><span>Poids (kg)</span><input name="weight_kg" inputmode="decimal" required placeholder="72,4"></label>
        <label class="field"><span>Date</span><input type="date" name="measured_on" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}"></label>
        <button class="btn">Ajouter</button>
      </form>
      @error('weight_kg')<p class="errors">{{ $message }}</p>@enderror
    </section>
  @endif

  {{-- Récupération (montre) et cycle --}}
  @if ($wellness)
    <section @class(['card', 'alert' => $recoverySignals])>
      <div class="card-head">
        <h2>Récupération</h2>
        <span class="muted small">{{ $wellness->day->isToday() ? 'aujourd\'hui' : ucfirst($wellness->day->locale('fr')->isoFormat('dddd D')) }} · montre</span>
      </div>
      <div class="recovery">
        @if ($wellness->resting_hr)<div><span class="stat">{{ $wellness->resting_hr }}</span><br><span class="muted small">FC de repos</span></div>@endif
        @if ($wellness->body_battery_max !== null)<div><span class="stat">{{ $wellness->body_battery_max }}</span><br><span class="muted small">Body Battery max</span></div>@endif
        @if ($wellness->sleepLabel())<div><span class="stat">{{ $wellness->sleepLabel() }}</span><br><span class="muted small">sommeil {{ $wellness->sleep_score ? '· score '.$wellness->sleep_score : '' }}</span></div>@endif
        @if ($wellness->hrv_last_night)<div><span class="stat">{{ $wellness->hrv_last_night }}</span><br><span class="muted small">HRV nuit</span></div>@endif
        @if ($wellness->stress_avg !== null)<div><span class="stat">{{ $wellness->stress_avg }}</span><br><span class="muted small">stress moyen</span></div>@endif
      </div>
      @foreach ($recoverySignals as $signal)
        <p class="small">⚠ {{ $signal }}</p>
      @endforeach
    </section>
  @endif

  @if ($profile?->tracksCycle())
    <section class="card">
      @include('coach::partials.cycle', ['cycle' => $cycle])
    </section>
  @endif
</div>
@endsection

@push('scripts')
@include('coach::partials.chart-script')
@endpush
