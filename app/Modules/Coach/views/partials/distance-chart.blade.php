{{-- Barres des kilomètres par semaine (semaine en cours en couleur d'accent). Paramètre : $chart (Charts::distance). --}}
<div class="chart-box">
  <svg class="chart" viewBox="0 0 640 220" role="img" aria-label="Kilomètres courus par semaine">
    @foreach ($chart['ticks'] as $tick)
      <line class="grid-line" x1="44" x2="628" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" />
      <text class="axis-label" x="36" y="{{ $tick['y'] + 4 }}" text-anchor="end">{{ $tick['label'] }}</text>
    @endforeach
    @foreach ($chart['bars'] as $index => $bar)
      @if ($bar['height'] > 0)
        <path @class(['bar', 'current' => $bar['current']])
              d="M{{ $bar['x'] }} {{ $bar['y'] + $bar['height'] }} V{{ $bar['y'] + min(4, $bar['height']) }} Q{{ $bar['x'] }} {{ $bar['y'] }} {{ $bar['x'] + min(4, $bar['width'] / 2) }} {{ $bar['y'] }} H{{ $bar['x'] + $bar['width'] - min(4, $bar['width'] / 2) }} Q{{ $bar['x'] + $bar['width'] }} {{ $bar['y'] }} {{ $bar['x'] + $bar['width'] }} {{ $bar['y'] + min(4, $bar['height']) }} V{{ $bar['y'] + $bar['height'] }} Z" />
      @endif
      <rect class="hit" x="{{ $bar['x'] - 3 }}" y="12" width="{{ $bar['width'] + 6 }}" height="180" data-tip="Semaine du {{ $bar['label'] }} · {{ $bar['value'] }}" />
      @if ($index % 2 === (count($chart['bars']) - 1) % 2)
        <text class="axis-label" x="{{ $bar['x'] + $bar['width'] / 2 }}" y="212" text-anchor="middle">{{ $bar['label'] }}</text>
      @endif
    @endforeach
  </svg>
</div>
<details class="chart-data">
  <summary>Voir les chiffres</summary>
  <table>
    <thead><tr><th>Semaine du</th><th>Distance</th></tr></thead>
    <tbody>
      @foreach (array_reverse($chart['bars']) as $bar)
        <tr><td>{{ $bar['label'] }}</td><td>{{ $bar['value'] }}</td></tr>
      @endforeach
    </tbody>
  </table>
</details>
