{{-- Tuile d'une semaine (exploit « Semaine la plus prolifique ») : période, kilomètres, séances. Paramètre : $week. --}}
<div class="tile">
  <div class="tile-head">
    <span class="tile-icon">@include('coach::partials.icon', ['name' => 'calendar'])</span>
    <span>
      <span class="tile-type">Semaine</span>
      <span class="muted small">Du {{ $week['start']->locale('fr')->isoFormat('dddd D MMMM') }} au {{ $week['end']->locale('fr')->isoFormat('dddd D MMMM YYYY') }}</span>
    </span>
  </div>
  <div class="tile-stats" style="margin-top:12px">
    <div><span class="muted small">Distance totale</span><strong>{{ number_format($week['km'], 1, ',', ' ') }} km</strong></div>
    <div><span class="muted small">Séances</span><strong>{{ $week['sessions'] }}</strong></div>
  </div>
</div>
