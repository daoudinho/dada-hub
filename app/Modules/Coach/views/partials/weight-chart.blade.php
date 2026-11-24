{{-- Courbe de poids. Paramètres : $chart (Charts::weight), $entries (pesées, pour le tableau et la suppression). --}}
<div class="chart-box">
  <svg class="chart" viewBox="0 0 640 220" role="img" aria-label="Évolution du poids">
    @foreach ($chart['ticks'] as $tick)
      <line class="grid-line" x1="44" x2="628" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" />
      <text class="axis-label" x="36" y="{{ $tick['y'] + 4 }}" text-anchor="end">{{ $tick['label'] }}</text>
    @endforeach
    @foreach ($chart['xLabels'] as $label)
      <text class="axis-label" x="{{ $label['x'] }}" y="212" text-anchor="middle">{{ $label['label'] }}</text>
    @endforeach
    <path class="line" d="{{ $chart['path'] }}" />
    @foreach ($chart['points'] as $point)
      <circle class="hit" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="12" data-tip="{{ $point['label'] }} · {{ $point['value'] }}" />
      <circle class="dot" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="{{ count($chart['points']) > 40 ? 2.5 : 4 }}" />
    @endforeach
  </svg>
</div>
<details class="chart-data">
  <summary>Voir les pesées</summary>
  <table>
    <thead><tr><th>Date</th><th>Poids</th><th><span class="sr-only">Supprimer</span></th></tr></thead>
    <tbody>
      @foreach ($entries->sortByDesc('measured_on') as $entry)
        <tr>
          <td>{{ $entry->measured_on->format('d/m/Y') }}</td>
          <td>{{ number_format($entry->weight_kg, 1, ',', '') }} kg</td>
          <td>
            <form method="POST" action="{{ route('coach.weights.destroy', $entry) }}" class="inline" onsubmit="return confirm('Supprimer cette pesée ?')">
              @csrf @method('DELETE')
              <button class="btn danger small">Supprimer</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</details>
