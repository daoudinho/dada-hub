{{-- Course tombée un autre jour que la séance prévue : l'athlète confirme (ou non) qu'il s'agissait de cette séance. Paramètre : $activity. --}}
@if (($suggested = $activity->suggestedSession) && $suggested->status === 'planned')
  <div class="suggest">
    <p>C'était ta séance <strong>« {{ $suggested->title }} »</strong> prévue {{ $suggested->scheduled_on->locale('fr')->isoFormat('dddd D MMMM') }} ?</p>
    <div class="actions">
      <form method="POST" action="{{ route('coach.running.activities.session.confirm', $activity) }}">@csrf<button class="btn small">Oui</button></form>
      <form method="POST" action="{{ route('coach.running.activities.session.dismiss', $activity) }}">@csrf @method('DELETE')<button class="btn ghost small">Non, course libre</button></form>
    </div>
  </div>
@endif
