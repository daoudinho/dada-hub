{{-- Records battus par une course (notés à l'import). Paramètre : $activity. --}}
@foreach ($activity->new_records ?? [] as $record)
  <p class="record-badge">🏆 Nouveau record : {{ $record['label'] }} en {{ $record['value'] }} <span class="muted">(ancien {{ $record['previous'] }})</span></p>
@endforeach
