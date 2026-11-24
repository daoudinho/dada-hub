{{-- Tuile d'une course, façon Strava : type, date, nom, chiffres clés, carte du parcours. Paramètre : $activity. --}}
@php($route = \App\Modules\Coach\Services\RouteSvg::path($activity->polyline, 600, 200))
<a class="tile" href="{{ route('coach.running.activities.show', $activity) }}">
  <div class="tile-head">
    <span class="tile-icon">@include('coach::partials.icon', ['name' => 'run'])</span>
    <span>
      <span class="tile-type">{{ $activity->session?->typeLabel() ?? $activity->typeLabel() }}</span>
      <span class="muted small">{{ ucfirst($activity->started_at->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm')) }}</span>
    </span>
  </div>
  <h3>{{ $activity->name ?: ($activity->session?->title ?? $activity->typeLabel()) }}</h3>
  @include('coach::partials.record-badges', ['activity' => $activity])
  <div class="tile-stats">
    <div><span class="muted small">Distance</span><strong>{{ number_format($activity->distanceKm(), 2, ',', ' ') }} km</strong></div>
    <div><span class="muted small">Temps</span><strong>{{ \App\Modules\Coach\Services\Duration::format($activity->moving_seconds) }}</strong></div>
    @if ($activity->pace())<div><span class="muted small">Allure</span><strong>{{ $activity->pace() }}</strong></div>@endif
  </div>
  @if ($route)
    {{-- Mini-carte chargée à l'affichage ; le tracé seul reste visible si elle ne se charge pas. --}}
    <div class="tile-map" data-route='@json($activity->polyline)'>
      <svg class="tile-route" viewBox="0 0 600 200" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Tracé du parcours">
        <path d="{{ $route }}" />
      </svg>
    </div>
  @endif
</a>

@once
  @push('head')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
  @endpush
  @push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <script>
    (() => {
      if (!window.L) return;
      const color = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#fc5a1e';
      const draw = (box) => {
        const points = JSON.parse(box.dataset.route || '[]');
        if (points.length < 2) return;
        box.innerHTML = '';
        box.classList.add('map');
        const map = L.map(box, {
          zoomControl: false, attributionControl: false, dragging: false, scrollWheelZoom: false,
          doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false, tap: false,
        });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
        const line = L.polyline(points, { color, weight: 3 }).addTo(map);
        map.fitBounds(line.getBounds(), { padding: [12, 12] });
      };
      const observer = new IntersectionObserver((entries) => entries.forEach((entry) => {
        if (entry.isIntersecting) { observer.unobserve(entry.target); draw(entry.target); }
      }), { rootMargin: '200px' });
      document.querySelectorAll('.tile-map[data-route]').forEach((box) => observer.observe(box));
    })();
    </script>
  @endpush
@endonce
