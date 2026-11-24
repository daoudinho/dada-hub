@extends('coach::layout')

@section('title', ($activity->name ?: 'Course').' · Dada Coach')
@section('page-class', 'narrow')

@php use App\Modules\Coach\Services\Duration; @endphp

@section('content')
<p><a class="small" href="{{ route('coach.running.activities.index') }}">← Historique</a></p>
<h1>{{ $activity->name ?: ($activity->session?->title ?? 'Course') }}</h1>
<p class="muted">
  {{ ucfirst($activity->started_at->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm')) }}
  · {{ $activity->sourceLabel() }}
  @if ($activity->externalUrl())
    · <a href="{{ $activity->externalUrl() }}" target="_blank" rel="noopener">voir sur {{ $activity->sourceLabel() }}</a>
  @endif
</p>

@if ($activity->polyline && count($activity->polyline) > 1)
  <section class="card section map-card">
    <div id="map" class="map" role="img" aria-label="Tracé du parcours"></div>
  </section>
  @push('head')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
  @endpush
  @push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <script>
    (() => {
      const points = @json($activity->polyline);
      const map = L.map('map', { scrollWheelZoom: false });
      // Tuiles OpenStreetMap (gratuites, sans clé), assombries en CSS (.map .leaflet-tile-pane) pour le thème noir.
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      }).addTo(map);
      const line = L.polyline(points, { color: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#e8692c', weight: 4 }).addTo(map);
      L.circleMarker(points[0], { radius: 6, color: '#3ecf7a', fillOpacity: 1 }).addTo(map).bindTooltip('Départ');
      L.circleMarker(points[points.length - 1], { radius: 6, color: '#ff6b5b', fillOpacity: 1 }).addTo(map).bindTooltip('Arrivée');
      map.fitBounds(line.getBounds(), { padding: [16, 16] });
    })();
    </script>
  @endpush
@endif

<section class="card section">
  <div class="stats">
    <div><span class="stat">{{ number_format($activity->distanceKm(), 2, ',', '') }}</span> km</div>
    <div><span class="stat">{{ Duration::format($activity->moving_seconds) }}</span></div>
    @if ($activity->pace())<div><span class="stat">{{ str_replace(' /km', '', $activity->pace()) }}</span> /km</div>@endif
    @if ($activity->avg_hr)<div><span class="stat">{{ $activity->avg_hr }}</span> bpm moy.@if ($activity->max_hr) <span class="muted small">(max {{ $activity->max_hr }})</span>@endif</div>@endif
  </div>
  <p class="small muted">
    {{ collect([
        $activity->avg_cadence ? 'Cadence '.$activity->avg_cadence.' pas/min' : null,
        $activity->elevation_gain_m ? 'D+ '.$activity->elevation_gain_m.' m' : null,
        $activity->elapsed_seconds && $activity->elapsed_seconds > $activity->moving_seconds + 60 ? 'Temps total '.Duration::format($activity->elapsed_seconds) : null,
        $gear ? 'Chaussures : '.$gear->name.' ('.$gear->km().' km)' : null,
    ])->filter()->implode(' · ') }}
  </p>
  @if ($activity->notes)<p class="desc">« {{ $activity->notes }} »</p>@endif
  @include('coach::partials.record-badges', ['activity' => $activity])
  @include('coach::partials.session-suggestion', ['activity' => $activity])
</section>

@if ($activity->session)
  <section class="card section">
    <h2>Séance prévue</h2>
    <p><strong>{{ $activity->session->title }}</strong> · {{ $activity->session->typeLabel() }}@if ($activity->session->targets()) · cible {{ $activity->session->targets() }}@endif</p>
    @if ($activity->session->description)<p class="desc small muted">{{ $activity->session->description }}</p>@endif
  </section>
@endif

<section class="card section" id="ressenti">
  <h2>Ton ressenti</h2>
  @forelse ($feedbacks as $feedback)
    <p class="small">
      <strong>{{ $feedback->source === 'form' ? 'Saisie rapide' : 'Débrief' }}</strong> :
      {{ collect([$feedback->scoresLabel(), $feedback->painsLabel()])->filter()->implode(' · ') }}
      @if ($feedback->summary)<br><span class="muted">{{ $feedback->summary }}</span>@endif
    </p>
  @empty
    <form method="POST" action="{{ route('coach.running.feedback.store', $activity) }}" class="form">
      @csrf
      @include('coach::partials.quick-feedback', ['required' => true, 'measuredSleep' => $measuredSleep])
      <div><button class="btn">Noter</button></div>
    </form>
  @endforelse
</section>

@if ($findings || $context)
  <section class="card section">
    @if ($glossary)
      <details class="glossary">
        <summary><h2>Analyse</h2><span class="info-btn" title="Comprendre les métriques">@include('coach::partials.icon', ['name' => 'info'])<span class="sr-only">Comprendre les métriques</span></span></summary>
        <dl>
          @foreach ($glossary as $term => $explanation)
            <dt>{{ $term }}</dt><dd>{{ $explanation }}</dd>
          @endforeach
        </dl>
      </details>
    @else
      <h2>Analyse</h2>
    @endif
    @if ($findings)
      <ul class="findings">
        @foreach ($findings as $finding)<li>{{ $finding }}</li>@endforeach
      </ul>
    @endif
    @if ($context)
      <h3 class="subhead">Contexte</h3>
      <ul class="findings">
        @foreach ($context as $line)<li>{{ $line }}</li>@endforeach
      </ul>
    @endif
  </section>
@endif

<section class="card section review" id="bilan">
  <h2>Bilan du coach</h2>
  @if ($review = $activity->review)
    <p><span class="verdict verdict-{{ $review['verdict'] }}">{{ $activity->verdictLabel() }}</span></p>
    <p>{{ $review['summary'] }}</p>
    @if ($review['strengths'])
      <h3 class="subhead">Points forts</h3>
      <ul class="findings">@foreach ($review['strengths'] as $strength)<li>{{ $strength }}</li>@endforeach</ul>
    @endif
    @if ($review['watchpoints'])
      <h3 class="subhead">Points de vigilance</h3>
      <ul class="findings">@foreach ($review['watchpoints'] as $watchpoint)<li>{{ $watchpoint }}</li>@endforeach</ul>
    @endif
    <h3 class="subhead">Pour la suite</h3>
    <p>{{ $review['next'] }}</p>
    @if ($debrief)
      <a class="btn ghost small" href="{{ route('coach.chat.show', $debrief) }}">Revoir le débrief</a>
    @endif
  @elseif ($feedbacks->isEmpty())
    <p class="small muted">Donne d'abord ton ressenti ci-dessus : tu pourras ensuite débriefer ta séance avec le coach, qui rédigera le bilan ici.</p>
  @elseif ($debrief && $debrief->status === 'closed')
    <p class="small muted">Le débrief est terminé, mais le bilan n'a pas été rédigé : demande-le au coach.</p>
    <form method="POST" action="{{ route('coach.running.activities.debrief', $activity) }}">@csrf<button class="btn small">Reprendre le débrief</button></form>
  @else
    <p>Fais le point avec ton coach : quelques questions sur tes sensations, puis il rédige le bilan ici.</p>
    <form method="POST" action="{{ route('coach.running.activities.debrief', $activity) }}">@csrf<button class="btn accent">{{ $debrief && $debrief->status === 'open' ? 'Reprendre le débrief' : 'Débriefer avec le coach' }}</button></form>
    @if ($autoReviewAt)
      <p class="small muted">Sans débrief, le coach rédigera le bilan à partir de tes données {{ $autoReviewAt->locale('fr')->isoFormat('dddd D MMMM [vers] H [h]') }}.</p>
    @endif
  @endif
</section>

@if ($reps = $activity->analysis['intervals']['reps'] ?? null)
  <section class="card section">
    <h2>Fractions</h2>
    <table class="table">
      <thead><tr><th>#</th><th>Durée</th><th>Distance</th><th>Allure</th><th>FC moy.</th>@if (isset($reps[0]['gap']))<th>Cible</th>@endif</tr></thead>
      <tbody>
        @foreach ($reps as $index => $rep)
          <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ Duration::format($rep['seconds']) }}</td>
            <td>{{ $rep['distance_m'] }} m</td>
            <td>{{ Duration::format($rep['pace']) }} /km</td>
            <td>{{ $rep['avg_hr'] ?? '—' }}</td>
            @isset($rep['gap'])
              <td class="{{ $rep['gap'] === 0 ? 'on-target' : 'off-target' }}">{{ $rep['gap'] === 0 ? '✓' : ($rep['gap'] > 0 ? '+' : '').$rep['gap'].' s' }}</td>
            @endisset
          </tr>
        @endforeach
      </tbody>
    </table>
  </section>
@endif

@if ($activity->laps && count($activity->laps) > 1)
  <section class="card section">
    <h2>Tours</h2>
    <table class="table">
      <thead><tr><th>#</th><th>Distance</th><th>Temps</th><th>Allure</th><th>FC moy.</th></tr></thead>
      <tbody>
        @foreach ($activity->laps as $index => $lap)
          <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ number_format($lap['distance_m'] / 1000, 2, ',', '') }} km</td>
            <td>{{ Duration::format($lap['moving_s']) }}</td>
            <td>{{ ($pace = Duration::fromSpeed($lap['avg_speed'])) ? $pace.' /km' : '—' }}</td>
            <td>{{ $lap['avg_hr'] ?? '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </section>
@endif

@if ($bestEfforts->isNotEmpty())
  <section class="card section">
    <h2>Meilleurs temps dans cette course</h2>
    <p>{{ $bestEfforts->map(fn ($effort) => $effort->name.' en '.Duration::format($effort->elapsed_seconds))->implode(' · ') }}</p>
  </section>
@endif
@endsection
