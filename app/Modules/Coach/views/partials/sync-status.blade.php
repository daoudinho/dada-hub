{{-- Suivi d'une synchronisation Garmin lancée à $since : l'icône tourne, puis les nouvelles courses sont annoncées. --}}
<div class="toast sync" id="sync-toast" role="status">Synchronisation Garmin…</div>
@push('scripts')
<script>
(() => {
  const box = document.getElementById('sync-toast');
  const icon = document.querySelector('.appbar .sync-btn');
  const url = @json(route('coach.garmin.status', ['depuis' => $since]));
  const profile = @json(route('coach.profile.edit').'#garmin');
  const started = Date.now();
  const escape = (text) => text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const finish = (html) => {
    icon?.classList.remove('spinning');
    box.innerHTML = html;
    box.classList.add('fade');
  };
  icon?.classList.add('spinning');

  async function poll() {
    let data = null;
    try {
      data = await (await fetch(url, { headers: { Accept: 'application/json' } })).json();
    } catch (e) { /* réseau : on réessaie */ }

    if (data?.done) {
      if (data.error) {
        finish(`Synchronisation impossible · <a href="${profile}">voir le détail</a>`);
      } else if (data.runs.length === 0) {
        finish('Aucune nouvelle course.');
      } else {
        finish(data.runs.map((run) => `Nouvelle course : ${escape(run.label)}${run.record ? ' 🏆' : ''} · <a href="${run.url}">Voir</a>`).join('<br>'));
      }
      return;
    }
    if (Date.now() - started > 120000) {
      finish('La synchronisation prend plus de temps que prévu : tes courses arriveront un peu plus tard.');
      return;
    }
    setTimeout(poll, 3000);
  }
  setTimeout(poll, 2000);
})();
</script>
@endpush
