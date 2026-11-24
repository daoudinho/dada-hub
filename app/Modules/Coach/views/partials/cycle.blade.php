{{-- Suivi du cycle : état estimé, saisie d'un début de règles, dates notées. Paramètres : $cycle (CycleTracker::status), $entries (facultatif). --}}
<div class="card-head">
  <h2>Mon cycle</h2>
  @if ($cycle)
    <span class="muted small">cycle moyen {{ $cycle['average_length'] }} j</span>
  @endif
</div>
@if ($cycle)
  <p><strong>Jour {{ $cycle['day'] }}</strong> · {{ $cycle['phase'] }}</p>
  <p class="muted small">Prochaines règles attendues vers le {{ $cycle['next_expected']->locale('fr')->isoFormat('D MMMM') }}.</p>
@else
  <p class="muted">Note le premier jour de tes règles pour démarrer le suivi.</p>
@endif
<form method="POST" action="{{ route('coach.cycle.store') }}" class="weight-form">
  @csrf
  <label class="field"><span>Premier jour des règles</span><input type="date" name="started_on" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
  <button class="btn">Noter</button>
</form>
@if (! empty($entries) && $entries->isNotEmpty())
  <details class="chart-data">
    <summary>Dates notées</summary>
    <table>
      <tbody>
        @foreach ($entries as $entry)
          <tr>
            <td>{{ $entry->started_on->format('d/m/Y') }}</td>
            <td>
              <form method="POST" action="{{ route('coach.cycle.destroy', $entry) }}" class="inline">@csrf @method('DELETE')<button class="btn danger small">Retirer</button></form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </details>
@endif
