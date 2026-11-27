@extends('coach::layout')

@section('title', 'Programme running · Dada Coach')

@section('content')
<div class="cal-nav">
  @if ($isCurrent)
    <span></span>
  @else
    <a class="btn ghost small" href="{{ route('coach.running.calendar', ['semaine' => $previous]) }}" aria-label="Semaines précédentes">←</a>
  @endif
  <div class="cal-title">
    <h1>{{ $start->locale('fr')->isoFormat('D MMM') }} – {{ $end->locale('fr')->isoFormat('D MMM YYYY') }}</h1>
    @unless ($isCurrent)
      <a class="small" href="{{ route('coach.running.calendar') }}">Revenir à cette semaine</a>
    @endunless
  </div>
  <a class="btn ghost small" href="{{ route('coach.running.calendar', ['semaine' => $next]) }}" aria-label="Semaines suivantes">→</a>
</div>

@if ($plan)
  <details class="card" style="margin-bottom:12px">
    <summary><strong>Programme</strong>@if ($goal) · {{ $goal->title }}@endif
      @if ($plan->feasibility) <span class="pill">{{ \App\Modules\Coach\Models\RunningPlan::FEASIBILITY[$plan->feasibility] ?? '' }}</span>@endif
    </summary>
    <p class="desc">{{ $plan->summary }}</p>
    @if ($plan->rationale)<p class="desc muted small">{{ $plan->rationale }}</p>@endif
  </details>
@else
  <p class="card muted">Pas encore de programme : il sera proposé par <a href="{{ route('coach.chat.index') }}">ton coach</a> après votre premier échange.</p>
@endif

<div class="cal">
  @foreach (['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'] as $dow)
    <div class="dow">{{ $dow }}</div>
  @endforeach

  @foreach ($days as $day)
    @php
      $key = $day->toDateString();
      $daySessions = $sessions->get($key, collect());
      $unavailable = $unavailabilities->first(fn ($period) => $period->covers($day));
    @endphp
    <div @class(['day', 'past' => $day->isBefore(today()), 'today' => $day->isToday(), 'unavailable' => $unavailable,
        'empty-day' => $daySessions->isEmpty() && ! $unavailable])>
      <span class="num">{{ ucfirst($day->locale('fr')->isoFormat($loop->first || $day->day === 1 ? 'ddd D MMM' : 'ddd D')) }}</span>
      @isset($periodDays[$key])
        <span class="cycle-mark">{{ $periodDays[$key] }}</span>
      @endisset
      @if ($unavailable)
        <span class="unavail">{{ $unavailable->reason ?: \App\Modules\Coach\Models\Unavailability::KINDS[$unavailable->kind] }}</span>
      @endif
      @foreach ($daySessions as $session)
        <button type="button" @class(['sess', 't-'.$session->type, $session->status]) data-dialog="session-{{ $session->id }}">
          <strong>{{ $session->title }}</strong>
          <span>{{ $session->typeLabel() }}@if ($session->duration_minutes) · {{ $session->duration_minutes }} min @endif</span>
        </button>
      @endforeach
    </div>
  @endforeach
</div>

@foreach ($sessions->flatten() as $session)
  <dialog id="session-{{ $session->id }}" aria-labelledby="session-{{ $session->id }}-title">
    <h2 id="session-{{ $session->id }}-title">{{ $session->title }}</h2>
    <p class="muted">{{ ucfirst($session->scheduled_on->locale('fr')->isoFormat('dddd D MMMM')) }} · {{ $session->typeLabel() }}</p>
    @if ($session->targets())<p><strong>{{ $session->targets() }}</strong></p>@endif
    @if ($session->description)<p class="desc">{{ $session->description }}</p>@endif

    @if ($session->status === 'done' && $session->activity)
      <p class="pill">Faite</p>
      <p class="small">{{ $session->activity->metrics() }}</p>
      <a class="btn ghost small" href="{{ route('coach.running.activities.show', $session->activity) }}">Voir la course</a>
    @elseif ($session->status === 'cancelled')
      <p class="pill">Annulée</p>
      @if ($session->cancel_reason)<p class="small muted">« {{ $session->cancel_reason }} »</p>@endif
    @else
      @if ($autoSync)
        <p class="small muted">Rien à faire : dès que ta course arrive de ta montre, la séance est validée et ton coach reçoit toutes les données.</p>
        <div class="actions">
          <a class="btn ghost small" href="{{ route('coach.running.activities.create', ['seance' => $session->id]) }}">Saisir à la main</a>
        </div>
      @else
        <div class="actions">
          <a class="btn" href="{{ route('coach.running.activities.create', ['seance' => $session->id]) }}">Je l'ai faite</a>
        </div>
      @endif
      <form method="POST" action="{{ route('coach.running.sessions.cancel', $session) }}" class="form" style="margin-top:12px" {{ $session->scheduled_on->isToday() ? 'data-motivation' : '' }}>
        @csrf
        <label class="field"><span>Annuler la séance (raison facultative)</span><input name="reason" maxlength="500" placeholder="Fatigue, douleur, imprévu…"></label>
        <div><button class="btn danger small">Annuler la séance</button></div>
      </form>
    @endif

    <form method="dialog" class="actions"><button class="btn ghost small">Fermer</button></form>
  </dialog>
@endforeach

{{-- Annuler la séance du jour : une vidéo de motivation d'abord, l'annulation seulement si on insiste. --}}
<dialog id="motivation" class="motivation" aria-labelledby="motivation-title">
  <h2 id="motivation-title">Attends, avant d'annuler…</h2>
  <video src="{{ asset('videos/motivation.mp4') }}" playsinline controls preload="none"></video>
  <div class="actions">
    <button type="button" class="btn" data-motivation-go>OK, j'y vais !</button>
    <button type="button" class="btn danger small" data-motivation-cancel>Annuler quand même</button>
  </div>
</dialog>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-dialog]').forEach((button) => {
  button.addEventListener('click', () => document.getElementById(button.dataset.dialog)?.showModal());
});
// Séance du jour : le bouton « Annuler » montre d'abord la vidéo de motivation.
const motivation = document.getElementById('motivation');
const motivationVideo = motivation.querySelector('video');
let cancelForm = null;
document.querySelectorAll('form[data-motivation]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (form.dataset.confirmed) {
      return;
    }
    event.preventDefault();
    cancelForm = form;
    motivation.showModal();
    motivationVideo.currentTime = 0;
    motivationVideo.play().catch(() => {});
  });
});
motivation.addEventListener('close', () => motivationVideo.pause());
motivation.querySelector('[data-motivation-go]').addEventListener('click', () => {
  motivation.close();
  cancelForm?.closest('dialog')?.close();
});
motivation.querySelector('[data-motivation-cancel]').addEventListener('click', () => {
  motivation.close();
  cancelForm.dataset.confirmed = '1';
  cancelForm.requestSubmit();
});
// Arrivée depuis l'Accueil (?seance=12) : le détail de la séance s'ouvre directement.
const requested = new URLSearchParams(location.search).get('seance');
if (requested) {
  document.getElementById('session-' + requested)?.showModal();
}
</script>
@endpush
