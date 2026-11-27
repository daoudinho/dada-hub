@extends('coach::layout')

@section('title', 'Mon profil · Dada Coach')
@section('page-class', 'narrow')

@php
  use App\Modules\Coach\Models\Goal;
  use App\Modules\Coach\Models\Profile;
  use App\Modules\Coach\Models\Unavailability;
  use App\Modules\Coach\Services\Duration;
@endphp

@section('content')
<h1>Mon profil</h1>

@if ($errors->any())
  <div class="errors" role="alert" style="margin-bottom:12px">
    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
  </div>
@endif

{{-- Objectifs --}}
<section class="card section" id="objectifs">
  <h2>Objectifs</h2>
  @if ($goals->isEmpty())
    <p class="muted">Aucun objectif pour l'instant.</p>
  @else
    <ul class="list">
      @foreach ($goals as $goal)
        <li>
          <span class="what">
            <strong>{{ $goal->title }}</strong> <span class="pill">{{ Goal::PRIORITIES[$goal->priority] ?? '' }}</span><br>
            <span class="muted small">
              {{ collect([$goal->event_name, $goal->event_date?->format('d/m/Y'), $goal->distance_km ? $goal->distance_km.' km' : null, $goal->targetLabel()])->filter()->implode(' · ') }}
            </span>
          </span>
          <form method="POST" action="{{ route('coach.goals.update', $goal) }}" class="inline">
            @csrf @method('PATCH')
            <button class="btn small" name="status" value="achieved">Atteint</button>
            <button class="btn ghost small" name="status" value="abandoned">Retirer</button>
          </form>
        </li>
      @endforeach
    </ul>
  @endif
  <details class="add">
    <summary>Ajouter un objectif</summary>
    <form method="POST" action="{{ route('coach.goals.store') }}" class="form">
      @csrf
      <label class="field"><span>Intitulé</span><input name="title" required maxlength="150" placeholder="Semi-marathon de Paris en 1h50"></label>
      <div class="row">
        <label class="field"><span>Course</span><input name="event_name" maxlength="150" placeholder="le semi-marathon de Paris"></label>
        <label class="field"><span>Date</span><input type="date" name="event_date" min="{{ today()->toDateString() }}"></label>
      </div>
      <div class="row">
        <label class="field"><span>Distance (km)</span><input name="distance_km" inputmode="decimal" placeholder="21,1"></label>
        <label class="field"><span>Temps visé</span><input name="target_time" placeholder="1:50:00"></label>
        <label class="field"><span>Type</span>
          <select name="priority">@foreach (Goal::PRIORITIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
        </label>
      </div>
      <label class="check"><input type="checkbox" name="finish_only" value="1"> Objectif « finir », sans temps visé</label>
      <label class="field"><span>Pourquoi cet objectif ? (facultatif)</span><textarea name="notes" maxlength="2000"></textarea></label>
      <div><button class="btn">Ajouter</button></div>
      <p class="muted small">Une nouvelle course principale remplace la précédente ; les courses intermédiaires s'ajoutent.</p>
    </form>
  </details>
</section>

{{-- Profil, historique, contraintes --}}
<form method="POST" action="{{ route('coach.profile.update') }}" class="card section form" id="profil">
  @csrf
  @method('PUT')

  <h2>Profil</h2>
  <div class="row">
    <label class="field">
      <span>Sexe</span>
      <select name="sex" id="sex">
        <option value="">—</option>
        @foreach (Profile::SEXES as $value => $label)
          <option value="{{ $value }}" @selected(old('sex', $profile->sex) === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </label>
    <label class="field"><span>Date de naissance</span><input type="date" name="birth_date" value="{{ old('birth_date', $profile->birth_date?->toDateString()) }}"></label>
    <label class="field"><span>Taille (cm)</span><input type="number" name="height_cm" min="80" max="250" value="{{ old('height_cm', $profile->height_cm) }}"></label>
  </div>
  <div class="row">
    <label class="field"><span>FC au repos (bpm)</span><input type="number" name="resting_hr" min="25" max="120" value="{{ old('resting_hr', $profile->resting_hr) }}"></label>
    <label class="field"><span>FC max (bpm)</span><input type="number" name="max_hr" min="100" max="240" value="{{ old('max_hr', $profile->max_hr) }}"></label>
    <label class="field"><span>FC au seuil (bpm)</span><input type="number" name="threshold_hr" min="80" max="230" value="{{ old('threshold_hr', $profile->threshold_hr) }}"></label>
    <label class="field"><span>Je cours depuis (année)</span><input type="number" name="running_since" min="1950" max="{{ today()->year }}" value="{{ old('running_since', $profile->running_since) }}"></label>
  </div>
  @unless ($garmin)
    <p class="muted small">Les FC sont facultatives : un test peut les estimer, ou ta montre Garmin les remplira.</p>
  @endunless

  <fieldset id="cycle-settings" @if (old('sex', $profile->sex) !== 'female') hidden @endif>
    <legend>Cycle menstruel</legend>
    <label class="check"><input type="checkbox" name="cycle_tracking" value="1" @checked(old('cycle_tracking', $profile->cycle_tracking))> Suivre mon cycle pour que le coach en tienne compte</label>
    <label class="check"><input type="checkbox" name="hormonal_contraception" value="1" @checked(old('hormonal_contraception', $profile->hormonal_contraception))> Contraception hormonale (pilule, implant, stérilet hormonal…)</label>
    <p class="muted small">Visible de toi seule. Tu notes le début de tes règles ; le coach s'en sert comme d'un élément de contexte, jamais comme d'une règle.</p>
  </fieldset>

  <h2>Disponibilités</h2>
  <div class="avail">
    @foreach (Profile::DAYS as $day => $label)
      @php $value = $profile->dayAvailability($day) ?? ['available' => false, 'max_minutes' => null, 'slot' => null]; @endphp
      <div class="avail-row">
        <label class="check"><input type="checkbox" name="availability[{{ $day }}][available]" value="1" @checked(old("availability.$day.available", $value['available']))> {{ $label }}</label>
        <label class="field"><span class="sr-only">Durée max le {{ mb_strtolower($label) }}</span>
          <input type="number" name="availability[{{ $day }}][max_minutes]" min="10" max="600" placeholder="min max" value="{{ old("availability.$day.max_minutes", $value['max_minutes']) }}">
        </label>
        <label class="field"><span class="sr-only">Créneau le {{ mb_strtolower($label) }}</span>
          <select name="availability[{{ $day }}][slot]">
            <option value="">Créneau</option>
            @foreach (Profile::SLOTS as $slot => $slotLabel)<option value="{{ $slot }}" @selected(old("availability.$day.slot", $value['slot']) === $slot)>{{ $slotLabel }}</option>@endforeach
          </select>
        </label>
      </div>
    @endforeach
  </div>
  <label class="field">
    <span>Jour de sortie longue</span>
    <select name="long_run_day">
      <option value="">—</option>
      @foreach (Profile::DAYS as $day => $label)<option value="{{ $day }}" @selected(old('long_run_day', $profile->long_run_day) === $day)>{{ $label }}</option>@endforeach
    </select>
  </label>
  <label class="field">
    <span>Info complémentaire</span>
    <textarea name="availability_notes" maxlength="1500" placeholder="Horaires décalés une semaine sur deux, garde des enfants le mercredi, déplacements pro fréquents, accès à une piste le jeudi…">{{ old('availability_notes', $profile->availability_notes) }}</textarea>
    <span class="muted small">Tout ce qui peut aider le coach à construire ton programme.</span>
  </label>

  <h2>Autres activités</h2>
  <label class="field">
    <span>Autres sports ou travail physique (avec leur fréquence)</span>
    <textarea name="other_activities" maxlength="1000" placeholder="Vélo 1 h le week-end, travail debout toute la journée…">{{ old('other_activities', $profile->other_activities) }}</textarea>
  </label>

  @if ($fitness)
    <h2 id="fitness">Fitness</h2>
    <div class="row">
      <label class="field">
        <span>Objectif</span>
        <select name="fitness_goal">
          <option value="">—</option>
          @foreach (Profile::FITNESS_GOALS as $value => $label)<option value="{{ $value }}" @selected(old('fitness_goal', $profile->fitness_goal) === $value)>{{ $label }}</option>@endforeach
        </select>
      </label>
      <label class="field">
        <span>Niveau</span>
        <select name="fitness_level">
          <option value="">—</option>
          @foreach (Profile::FITNESS_LEVELS as $value => $label)<option value="{{ $value }}" @selected(old('fitness_level', $profile->fitness_level) === $value)>{{ $label }}</option>@endforeach
        </select>
      </label>
    </div>
    <div class="row">
      <label class="field"><span>Séances par semaine</span><input type="number" name="fitness_sessions_per_week" min="1" max="7" value="{{ old('fitness_sessions_per_week', $profile->fitness_sessions_per_week) }}"></label>
      <label class="field"><span>Durée d'une séance (min)</span><input type="number" name="fitness_session_minutes" min="15" max="240" value="{{ old('fitness_session_minutes', $profile->fitness_session_minutes) }}"></label>
    </div>
    <p class="muted small">Le coach part de là pour te proposer des exercices et, plus tard, un programme. Tes machines, il les découvre avec toi.</p>
  @endif

  <div class="actions"><button class="btn">Enregistrer</button></div>
</form>

{{-- Courses passées --}}
<section class="card section" id="courses">
  <h2>Courses déjà faites</h2>
  <p class="muted small">Avec leurs temps : elles servent à estimer ton niveau et tes allures.</p>
  @if ($pastRaces->isNotEmpty())
    <ul class="list">
      @foreach ($pastRaces as $race)
        <li>
          <span class="what">{{ $race->label() }}</span>
          <form method="POST" action="{{ route('coach.races.destroy', $race) }}" class="inline">@csrf @method('DELETE')<button class="btn danger small">Retirer</button></form>
        </li>
      @endforeach
    </ul>
  @endif
  <form method="POST" action="{{ route('coach.races.store') }}" class="row add-row">
    @csrf
    <label class="field"><span>Date</span><input type="date" name="raced_on" required max="{{ today()->toDateString() }}"></label>
    <label class="field"><span>Nom</span><input name="name" maxlength="150" placeholder="10 km de Nantes"></label>
    <label class="field"><span>Distance (km)</span><input name="distance_km" inputmode="decimal" required placeholder="10"></label>
    <label class="field"><span>Temps</span><input name="time" required placeholder="52:10"></label>
    <div class="field"><span>&nbsp;</span><button class="btn">Ajouter</button></div>
  </form>
</section>

{{-- Blessures --}}
<section class="card section" id="blessures">
  <h2>Blessures et fragilités</h2>
  @if ($injuries->isNotEmpty())
    <ul class="list">
      @foreach ($injuries as $injury)
        <li>
          <span class="what">{{ $injury->label() }}</span>
          <form method="POST" action="{{ route('coach.injuries.toggle', $injury) }}" class="inline">@csrf @method('PATCH')
            <button class="btn ghost small">{{ $injury->current ? 'Guérie' : 'Ça revient' }}</button>
          </form>
          <form method="POST" action="{{ route('coach.injuries.destroy', $injury) }}" class="inline">@csrf @method('DELETE')<button class="btn danger small">Retirer</button></form>
        </li>
      @endforeach
    </ul>
  @endif
  <form method="POST" action="{{ route('coach.injuries.store') }}" class="form add-row">
    @csrf
    <div class="row">
      <label class="field"><span>Zone</span><input name="zone" required maxlength="80" placeholder="Genou droit, tibias, tendon d'Achille…"></label>
      <label class="field"><span>Quand</span><input name="period" maxlength="40" placeholder="2024, l'an dernier…"></label>
    </div>
    <label class="field"><span>Détails (facultatif)</span><input name="description" maxlength="1000" placeholder="Syndrome de l'essuie-glace, 6 semaines d'arrêt"></label>
    <div class="checks">
      <label class="check"><input type="checkbox" name="recurrent" value="1"> Revient régulièrement</label>
      <label class="check"><input type="checkbox" name="current" value="1"> Encore gênante aujourd'hui</label>
    </div>
    <div><button class="btn">Ajouter</button></div>
  </form>
</section>

{{-- Indisponibilités --}}
<section class="card section" id="indisponibilites">
  <h2>Indisponibilités à venir</h2>
  <p class="muted small">Vacances, déplacement, semaine chargée : le coach adapte le programme à l'avance.</p>
  @if ($unavailabilities->isNotEmpty())
    <ul class="list">
      @foreach ($unavailabilities as $unavailability)
        <li>
          <span class="what">{{ ucfirst($unavailability->label()) }}</span>
          <form method="POST" action="{{ route('coach.unavailabilities.destroy', $unavailability) }}" class="inline">@csrf @method('DELETE')<button class="btn danger small">Retirer</button></form>
        </li>
      @endforeach
    </ul>
  @endif
  <form method="POST" action="{{ route('coach.unavailabilities.store') }}" class="row add-row">
    @csrf
    <label class="field"><span>Du</span><input type="date" name="starts_on" required min="{{ today()->toDateString() }}"></label>
    <label class="field"><span>Au</span><input type="date" name="ends_on" required min="{{ today()->toDateString() }}"></label>
    <label class="field"><span>Type</span>
      <select name="kind">@foreach (Unavailability::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
    </label>
    <label class="field"><span>Raison</span><input name="reason" maxlength="150" placeholder="Vacances"></label>
    <div class="field"><span>&nbsp;</span><button class="btn">Ajouter</button></div>
  </form>
</section>

@if ($profile->tracksCycle())
  <section class="card section" id="cycle">
    @include('coach::partials.cycle', ['cycle' => $cycle, 'entries' => $cycleEntries])
  </section>
@endif

{{-- Garmin --}}
<section class="card section" id="garmin">
  <h2>Garmin</h2>
  @if ($garmin)
    <p>Clé enregistrée <span class="muted small">({{ $garmin->keyHint() }}, le {{ $garmin->updated_at->format('d/m/Y') }})</span>.</p>
    @if ($garmin->keyExpired())
      <p class="errors">Ta clé a expiré le {{ $garmin->key_expires_on->format('d/m/Y') }} : crée-en une nouvelle dans Garmin Connect et colle-la ci-dessous.</p>
    @elseif ($garmin->keyExpiresSoon())
      <p class="errors">Ta clé expire le {{ $garmin->key_expires_on->format('d/m/Y') }} : pense à la renouveler dans Garmin Connect.</p>
    @elseif ($garmin->key_expires_on)
      <p class="muted small">Elle expire le {{ $garmin->key_expires_on->format('d/m/Y') }}.</p>
    @endif
    @if ($garmin->last_error)
      <p class="errors">Dernière synchronisation impossible : {{ $garmin->last_error }}</p>
    @endif
    <p class="muted small">
      @if ($garmin->last_synced_at)
        Dernière synchronisation {{ $garmin->last_synced_at->locale('fr')->diffForHumans() }} : courses, zones cardiaques, VO2 max et récupération (FC de repos, Body Battery, sommeil, HRV).
      @elseif (! $garmin->last_error)
        Import de tes courses des 2 dernières années en cours…
      @endif
    </p>
    <form method="POST" action="{{ route('coach.garmin.sync') }}" class="actions">@csrf<button class="btn ghost small">Synchroniser</button></form>
  @else
    <p class="muted">Pour que tes courses arrivent depuis ta montre : dans Garmin Connect (version web), ouvre les paramètres → <strong>Personal API Key</strong>,
      crée une clé, puis colle-la ici. Elle reste chiffrée et n'est plus jamais affichée.</p>
  @endif

  <details @class(['add']) @if (! $garmin || $errors->has('api_key')) open @endif>
    <summary>{{ $garmin ? 'Remplacer la clé' : 'Ajouter ma clé' }}</summary>
    <form method="POST" action="{{ route('coach.garmin.store') }}" class="form">
      @csrf @method('PUT')
      <label class="field"><span>Clé d'API personnelle Garmin</span>
        <input type="password" name="api_key" required autocomplete="off" spellcheck="false" placeholder="Colle ta clé ici">
      </label>
      @error('api_key')<p class="errors">{{ $message }}</p>@enderror
      <label class="field"><span>Date d'expiration choisie chez Garmin (facultatif, pour être prévenu)</span>
        <input type="date" name="key_expires_on" min="{{ today()->toDateString() }}" value="{{ old('key_expires_on', $garmin?->key_expires_on?->toDateString()) }}">
      </label>
      <div><button class="btn">Enregistrer</button></div>
    </form>
  </details>

  @if ($garmin)
    <form method="POST" action="{{ route('coach.garmin.destroy') }}" class="actions" onsubmit="return confirm('Supprimer ta clé Garmin ?')">
      @csrf @method('DELETE')<button class="btn danger small">Supprimer la clé</button>
    </form>
  @endif
</section>

{{-- Strava --}}
<section class="card section" id="strava">
  <h2>Strava <span class="pill">optionnel</span></h2>
  @if ($strava)
    <p>Relié au compte <strong>{{ $strava->athlete_name ?? 'Strava' }}</strong>.
      <span class="muted small">
        @if ($strava->last_synced_at) Dernière synchronisation {{ $strava->last_synced_at->locale('fr')->diffForHumans() }}.@else Import de l'historique en cours…@endif
      </span>
    </p>
    <p class="muted small">Tes nouvelles courses arrivent automatiquement, avec leurs données détaillées (FC, allure, tours).</p>
    @if ($gear->isNotEmpty())
      <p class="small">Chaussures : {{ $gear->map(fn ($shoe) => $shoe->name.' ('.$shoe->km().' km'.($shoe->isWorn() ? ', usées' : '').')')->implode(' · ') }}</p>
    @endif
    <div class="actions">
      <form method="POST" action="{{ route('coach.strava.sync') }}" class="inline">@csrf<button class="btn ghost small">Synchroniser</button></form>
      <form method="POST" action="{{ route('coach.strava.disconnect') }}" class="inline" onsubmit="return confirm('Déconnecter Strava ? Tes courses déjà importées restent.')">
        @csrf @method('DELETE')<button class="btn danger small">Déconnecter</button>
      </form>
    </div>
  @else
    <p class="muted">Relie ton compte pour que chaque course arrive automatiquement chez ton coach, avec la fréquence cardiaque, les allures et les tours.</p>
    <a class="btn strava" href="{{ route('coach.strava.connect') }}">Relier Strava</a>
  @endif
</section>

{{-- Google Health --}}
<section class="card section" id="google-health">
  <h2>Google Health</h2>
  @if ($googleHealth)
    @if ($googleHealth->needsReconnect())
      <p class="errors">L'autorisation Google a expiré : relie ton compte de nouveau pour que tes courses continuent d'arriver.</p>
      <a class="btn" href="{{ route('coach.google-health.connect') }}">Relier de nouveau</a>
    @else
      <p>Compte Google relié.
        <span class="muted small">
          @if ($googleHealth->last_synced_at) Dernière synchronisation {{ $googleHealth->last_synced_at->locale('fr')->diffForHumans() }}.@elseif (! $googleHealth->last_error) Import de l'historique en cours…@endif
        </span>
      </p>
      @if ($googleHealth->last_error)
        <p class="errors">Dernière synchronisation impossible : {{ $googleHealth->last_error }}</p>
      @endif
      <p class="muted small">Tes courses arrivent depuis ta montre (Samsung Health → Health Connect → Google Health) : distance, durée, allure, FC moyenne{{ $googleHealth->canReadHeartRate() ? ' et courbe cardiaque' : '' }}.</p>
    @endif
    <div class="actions">
      @unless ($googleHealth->needsReconnect())
        <form method="POST" action="{{ route('coach.google-health.sync') }}" class="inline">@csrf<button class="btn ghost small">Synchroniser</button></form>
      @endunless
      <form method="POST" action="{{ route('coach.google-health.disconnect') }}" class="inline" onsubmit="return confirm('Déconnecter Google Health ? Tes courses déjà importées restent.')">
        @csrf @method('DELETE')<button class="btn danger small">Déconnecter</button>
      </form>
    </div>
  @else
    <p class="muted">Montre Samsung ou autre : si tes courses apparaissent dans l'app Google Health de ton téléphone, relie ton compte Google pour qu'elles arrivent chez ton coach.</p>
    <a class="btn" href="{{ route('coach.google-health.connect') }}">Relier Google Health</a>
  @endif
</section>

{{-- Repères calculés --}}
<section class="card section" id="reperes">
  <h2>Tes repères</h2>
  @if ($references['vdot'])
    <p>Niveau <strong>VDOT {{ number_format($references['vdot'], 1, ',', '') }}</strong> <span class="muted small">— d'après la {{ $references['source'] }}</span></p>
    <table class="table">
      <thead><tr><th>Allure</th><th>Par km</th></tr></thead>
      <tbody>
        @foreach ($references['paces'] as $pace)
          <tr><td>{{ $pace['label'] }}</td><td>{{ Duration::format($pace['from']) }} – {{ Duration::format($pace['to']) }}</td></tr>
        @endforeach
      </tbody>
    </table>
    <p class="small">Temps prévisibles : {{ collect($references['predictions'])->map(fn ($seconds, $label) => $label.' '.Duration::format($seconds))->implode(' · ') }}</p>
  @else
    <p class="muted">Ajoute une course récente avec son temps, ou fais une séance de test proposée par le coach, pour calculer tes allures.</p>
  @endif
  @if ($references['zones'])
    <p class="small"><strong>Zones cardiaques</strong> <span class="muted">({{ $references['zones_basis'] }})</span> :
      {{ collect($references['zones'])->map(fn ($zone, $label) => $label.' '.$zone['from'].'-'.$zone['to'])->implode(' · ') }}</p>
  @else
    <p class="muted small">Renseigne ta FC max (et ta FC de repos) pour calculer tes zones cardiaques.</p>
  @endif
</section>
@endsection

@push('scripts')
<script>
(() => {
  const sex = document.getElementById('sex');
  const cycle = document.getElementById('cycle-settings');
  sex.addEventListener('change', () => { cycle.hidden = sex.value !== 'female'; });
})();
</script>
@endpush
