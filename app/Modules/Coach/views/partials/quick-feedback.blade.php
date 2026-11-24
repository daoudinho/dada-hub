{{-- Champs du ressenti rapide (effort, fatigue, sommeil, douleur). Paramètres : $required ; $measuredSleep (DailyWellness de la nuit
     précédant la course, mesurée par la montre) : la question du sommeil disparaît alors au profit de la mesure. --}}
@php
  $measuredSleep = $measuredSleep ?? null;
  $scales = array_filter([
      'rpe' => ['Effort ressenti', range(1, 10), '1 très facile · 10 maximal'],
      'fatigue' => ['Forme avant la séance', range(1, 5), '1 en pleine forme · 5 épuisé·e'],
      'sleep' => $measuredSleep ? null : ['Sommeil des dernières nuits', range(1, 5), '1 très mauvais · 5 excellent'],
  ]);
@endphp
<div class="quick">
  @foreach ($scales as $name => [$label, $values, $hint])
    <fieldset class="field" style="border:0;padding:0">
      <legend class="small" style="padding:0;font-weight:700;color:var(--muted)">{{ $label }} <span class="muted" style="font-weight:400">({{ $hint }})</span></legend>
      <div class="scale">
        @foreach ($values as $value)
          <label>
            <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked((int) old($name) === $value) @if (($required ?? false) && $name !== 'sleep') required @endif>
            <span>{{ $value }}</span>
          </label>
        @endforeach
      </div>
    </fieldset>
  @endforeach
  @if ($measuredSleep)
    <p class="small muted">Sommeil mesuré par ta montre : <strong>{{ $measuredSleep->sleepLabel() }}</strong>@if ($measuredSleep->sleep_score) (score {{ $measuredSleep->sleep_score }})@endif</p>
  @endif
  <div class="row">
    <label class="field"><span>Douleur ou gêne ? Où</span><input name="pain_zone" maxlength="60" placeholder="Mollet gauche…" value="{{ old('pain_zone') }}"></label>
    <label class="field"><span>Intensité (1-10)</span><input type="number" name="pain_intensity" min="1" max="10" value="{{ old('pain_intensity') }}"></label>
  </div>
</div>
