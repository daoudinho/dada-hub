@extends('coach::layout')

@section('title', 'Ajouter une course · Dada Coach')
@section('page-class', 'narrow')

@section('content')
<h1>Ajouter une course</h1>
<form method="POST" action="{{ route('coach.running.activities.store') }}" class="card form">
  @csrf

  @if ($errors->any())
    <div class="errors" role="alert">
      @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
  @endif

  <label class="field">
    <span>Séance du programme</span>
    <select name="session_id">
      <option value="">Course hors programme</option>
      @foreach ($plannedSessions as $planned)
        <option value="{{ $planned->id }}" @selected((int) old('session_id', $session?->id) === $planned->id)>
          {{ ucfirst($planned->scheduled_on->locale('fr')->isoFormat('ddd D/MM')) }} · {{ $planned->title }}
        </option>
      @endforeach
    </select>
  </label>

  <div class="row">
    <label class="field"><span>Date</span><input type="date" name="date" required max="{{ today()->toDateString() }}" value="{{ old('date', $session && $session->scheduled_on->lte(today()) ? $session->scheduled_on->toDateString() : today()->toDateString()) }}"></label>
    <label class="field"><span>Heure</span><input type="time" name="time" value="{{ old('time') }}"></label>
  </div>

  <div class="row">
    <label class="field"><span>Distance (km)</span><input name="distance_km" inputmode="decimal" required placeholder="8,5" value="{{ old('distance_km') }}"></label>
    <label class="field"><span>Durée</span><input name="duration" required placeholder="45:30 ou 1:02:10" value="{{ old('duration') }}"></label>
  </div>

  <div class="row">
    <label class="field"><span>FC moyenne</span><input type="number" name="avg_hr" min="40" max="230" value="{{ old('avg_hr') }}"></label>
    <label class="field"><span>FC max</span><input type="number" name="max_hr" min="40" max="240" value="{{ old('max_hr') }}"></label>
    <label class="field"><span>Dénivelé + (m)</span><input type="number" name="elevation_gain_m" min="0" value="{{ old('elevation_gain_m') }}"></label>
  </div>

  <h2 style="margin:8px 0 0">Ton ressenti</h2>
  @include('coach::partials.quick-feedback', ['required' => false])

  <label class="field"><span>Un mot sur la course (facultatif)</span><textarea name="notes" maxlength="2000">{{ old('notes') }}</textarea></label>

  <p class="muted small">Le coach t'attendra ensuite pour le débrief. Avec Strava relié (dans ton profil), tes courses arrivent toutes seules.</p>
  <div class="actions"><button class="btn">Enregistrer</button></div>
</form>
@endsection
