{{-- Proposition du coach (programme ou ajustement) avec ses boutons. Paramètre : $proposal. --}}
@php
  $payload = $proposal->payload;
  $types = \App\Modules\Coach\Models\RunningSession::TYPES;
  // Séance proposée affichée comme une séance du calendrier (mêmes libellés de cibles).
  $line = fn (array $s) => collect([
      isset($s['date']) ? \Illuminate\Support\Carbon::parse($s['date'])->locale('fr')->isoFormat('ddd D/MM') : null,
      $types[$s['type'] ?? ''] ?? null,
      $s['title'] ?? null,
      (new \App\Modules\Coach\Models\RunningSession(collect($s)->only(['duration_minutes', 'distance_km', 'pace_from', 'pace_to', 'hr_from', 'hr_to'])->all()))->targets(),
  ])->filter()->implode(' · ');
@endphp
<div @class(['bubble', 'proposal', $proposal->status])>
  <strong>{{ $proposal->kind === 'plan' ? 'Programme proposé' : 'Ajustement proposé' }}</strong>
  @if ($proposal->kind === 'plan')
    @if (! empty($payload['feasibility']))
      <span class="pill">{{ \App\Modules\Coach\Models\RunningPlan::FEASIBILITY[$payload['feasibility']] ?? '' }}</span>
    @endif
    <p class="desc small">{{ $payload['summary'] }}</p>
    <details>
      <summary class="small">{{ count($payload['sessions']) }} séances</summary>
      <ul>@foreach ($payload['sessions'] as $session)<li>{{ $line($session) }}</li>@endforeach</ul>
    </details>
  @else
    <p class="small">{{ $proposal->reason }}</p>
    <ul>
      @foreach ($payload['add'] ?? [] as $session)<li>➕ {{ $line($session) }}</li>@endforeach
      @foreach ($payload['update'] ?? [] as $session)<li>✏️ Séance #{{ $session['id'] }} → {{ $line(collect($session)->except('id')->all()) ?: 'modifiée' }}</li>@endforeach
      @foreach ($payload['remove'] ?? [] as $id)<li>➖ Séance #{{ $id }} retirée</li>@endforeach
    </ul>
  @endif

  @if ($proposal->isPending())
    <div class="actions">
      <form method="POST" action="{{ route('coach.proposals.accept', $proposal) }}" class="inline">@csrf<button class="btn small">Accepter</button></form>
      <form method="POST" action="{{ route('coach.proposals.refuse', $proposal) }}" class="inline">@csrf<button class="btn ghost small">Refuser</button></form>
    </div>
  @else
    <p class="small muted">{{ $proposal->status === 'accepted' ? '✓ Acceptée' : 'Refusée' }}</p>
  @endif
</div>
