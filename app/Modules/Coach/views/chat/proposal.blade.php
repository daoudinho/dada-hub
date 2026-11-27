{{-- Proposition du coach (programme, ajustement ou exercices) avec ses boutons. Paramètre : $proposal. --}}
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
@if ($proposal->kind === 'exercises')
  {{-- Découverte de la salle : une réponse par exercice. --}}
  <div @class(['bubble', 'proposal', $proposal->status])>
    <strong>Exercices proposés</strong>
    <p class="small">{{ $proposal->reason }}</p>
    <ul class="proposed-exercises">
      @foreach ($payload['items'] as $index => $item)
        <li>
          <span>
            <strong>{{ $item['name'] }}</strong>
            <span class="muted small">· {{ $item['equipment'] ?? 'poids du corps' }} · {{ \App\Modules\Coach\Models\Exercise::muscleLabels($item['muscles'])->implode(', ') }}</span>
            @if (! empty($item['notes']))<span class="small desc">{{ $item['notes'] }}</span>@endif
          </span>
          @if (isset($item['answer']))
            <span class="pill">{{ ['have' => '✓ Ajouté', 'missing' => 'Pas dans ma salle', 'declined' => 'Écarté'][$item['answer']] }}</span>
          @elseif ($proposal->isPending())
            <span class="actions">
              <form method="POST" action="{{ route('coach.proposals.exercise', [$proposal, $index]) }}" class="inline">@csrf<input type="hidden" name="answer" value="have"><button class="btn small">{{ empty($item['equipment']) ? 'Ajouter' : 'J\'ai' }}</button></form>
              <form method="POST" action="{{ route('coach.proposals.exercise', [$proposal, $index]) }}" class="inline">@csrf<input type="hidden" name="answer" value="{{ empty($item['equipment']) ? 'declined' : 'missing' }}"><button class="btn ghost small">{{ empty($item['equipment']) ? 'Non merci' : 'Pas dans ma salle' }}</button></form>
            </span>
          @endif
        </li>
      @endforeach
    </ul>
    @if (! $proposal->isPending())
      <p class="small muted">{{ $proposal->status === 'accepted' ? '✓ Toutes les réponses sont données' : 'Proposition remplacée' }}</p>
    @endif
  </div>
@else
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
@endif
