<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sources · Agenda</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap">
<style>
@include('agenda::partials.theme')
:root { --ok: #2f8a4f; --warn: #b7793a; --bad: #c0392b; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --ok: #6cc28c; --warn: #d9a36a; --bad: #ee8173; } }
:root[data-theme="dark"] { --ok: #6cc28c; --warn: #d9a36a; --bad: #ee8173; }
.wrap { max-width: 720px; margin: 0 auto; background: var(--card); border-radius: 32px; padding: 22px 18px 26px; }
.hub { max-width: 720px; display: flex; gap: 16px; }
h1 { font-size: 24px; font-weight: 600; margin: 4px 6px 4px; letter-spacing: -0.01em; }
.sub { color: var(--muted); font-size: 14px; margin: 0 6px 16px; line-height: 1.45; }
.usage { background: var(--bg); border-radius: 18px; margin: 0 4px 14px; padding: 12px 16px; font-size: 14px; line-height: 1.5; }
.usage ul { margin: 6px 0 0; padding-left: 18px; color: var(--muted); }
.flash { background: var(--chip); color: var(--chip-fg); border-radius: 16px; padding: 10px 16px; margin: 0 4px 14px; font-size: 15px; }
.errors { color: var(--bad); font-size: 14px; margin: 6px 0 0; padding-left: 18px; }
details.box { background: var(--bg); border-radius: 18px; margin: 0 4px 14px; padding: 4px 16px; }
details.box > summary { cursor: pointer; font-weight: 600; padding: 12px 0; list-style: none; display: flex; justify-content: space-between; }
details.box > summary::-webkit-details-marker { display: none; }
details.box > summary::after { content: "+"; font-size: 20px; line-height: 1; }
details.box[open] > summary::after { content: "–"; }
form.grid { display: grid; gap: 10px; padding-bottom: 14px; }
.row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
label.f { display: grid; gap: 4px; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); min-width: 0; }
label.f input, label.f select { font: inherit; font-size: 16px; text-transform: none; letter-spacing: 0; font-weight: 400; color: var(--fg); background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; min-width: 0; width: 100%; }
.btn { font: inherit; font-weight: 600; font-size: 14px; border: 0; border-radius: 20px; padding: 9px 18px; cursor: pointer; background: var(--btn); color: var(--btn-fg); justify-self: end; }
.btn.ghost { background: transparent; color: var(--fg); border: 1px solid var(--line); }
.btn.danger { background: transparent; color: var(--bad); border: 1px solid var(--line); }
.list { display: grid; gap: 8px; margin: 0 4px; }
.src { background: var(--datebg); color: var(--datefg); border-radius: 18px; padding: 12px 14px 12px 16px; display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; align-items: center; }
.src.off { opacity: .55; }
.src h3 { margin: 0; font-size: 17px; font-weight: 600; overflow-wrap: anywhere; }
.src h3 a { color: inherit; text-decoration: none; }
.src h3 a:hover { text-decoration: underline; }
.meta { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 6px 12px; font-size: 13px; color: #6b6b78; align-items: center; }
.tag { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #fff; background: var(--col, #6b6b78); border-radius: 10px; padding: 3px 8px; }
.st-ok { color: var(--ok); font-weight: 600; } .st-unchanged { color: #6b6b78; font-weight: 600; } .st-failed { color: var(--bad); font-weight: 600; }
.switch { position: relative; width: 50px; height: 30px; border-radius: 15px; border: 0; cursor: pointer; background: #c9c9d2; padding: 0; }
.switch::after { content: ""; position: absolute; top: 3px; left: 3px; width: 24px; height: 24px; border-radius: 50%; background: #fff; transition: left .15s; box-shadow: 0 1px 2px rgba(0,0,0,.25); }
.switch[aria-checked="true"] { background: var(--ok); }
.switch[aria-checked="true"]::after { left: 23px; }
@media (prefers-reduced-motion: reduce) { .switch::after { transition: none; } }
.edit { grid-column: 1 / -1; }
.edit summary { cursor: pointer; font-size: 13px; color: #6b6b78; list-style: none; display: inline-block; padding: 2px 0; }
.edit summary::-webkit-details-marker { display: none; }
.edit[open] summary { margin-bottom: 8px; }
.edit .actions { display: flex; gap: 8px; justify-content: space-between; flex-wrap: wrap; }
.err-line { grid-column: 1 / -1; font-size: 13px; color: var(--bad); overflow-wrap: anywhere; }
a:focus-visible, button:focus-visible, summary:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
.src a:focus-visible, .src button:focus-visible, .src summary:focus-visible { outline-color: var(--datefg); }
</style>
</head>
<body>
<nav class="hub"><a href="{{ route('dashboard') }}">← Hub</a><a href="{{ route('agenda.index') }}">← Agenda</a></nav>
<div class="wrap">
  <h1>Sources</h1>
  <p class="sub">
    Pages agenda récupérées par Firecrawl lors des scans, puis analysées par l'IA.
    {{ $sources->where('active', true)->count() }} active(s) sur {{ $sources->count() }}.
    Une source sans catégorie est consultée quel que soit le filtre choisi.
  </p>

  @if (session('status'))
    <div class="flash" role="status">{{ session('status') }}</div>
  @endif

  @if ($lastScan)
    <div class="usage">
      <strong>Dernier scan</strong> · {{ $lastScan->created_at->timezone('Europe/Madrid')->format('d/m H:i') }}
      @if ($lastScan->mode === 'web') · recherche web @elseif ($lastScan->batch) · API Batch @endif
      · {{ ['pending' => 'en attente', 'running' => 'en cours', 'waiting' => 'en attente du lot Batch', 'done' => 'terminé', 'failed' => 'échec'][$lastScan->status] ?? $lastScan->status }}
      <ul>
        @if ($lastScan->mode === 'sources')
          <li>{{ $lastScan->sources_done }} / {{ $lastScan->sources_total }} source(s) analysée(s){{ $lastScan->sources_skipped ? ', '.$lastScan->sources_skipped.' récente(s) ignorée(s)' : '' }}</li>
        @endif
        <li>{{ $lastScan->found }} événement(s) ajouté(s) ou mis à jour</li>
        <li>{{ $lastScan->ai_calls }} appel(s) IA · {{ number_format($lastScan->input_tokens, 0, ',', ' ') }} tokens en entrée · {{ number_format($lastScan->output_tokens, 0, ',', ' ') }} en sortie</li>
        <li>{{ $lastScan->firecrawl_credits }} crédit(s) Firecrawl</li>
        <li>Coût IA estimé : {{ $lastScan->cost_usd !== null ? number_format($lastScan->cost_usd, 4, ',', ' ').' $' : ($lastScan->ai_calls ? 'inconnu (prix du modèle non renseigné)' : '0 $') }}</li>
      </ul>
    </div>
  @endif

  <details class="box" @if ($errors->create->any()) open @endif>
    <summary>Ajouter une source</summary>
    <form class="grid" method="POST" action="{{ route('agenda.sources.store') }}">
      @csrf
      <div class="row">
        <label class="f">Nom<input name="name" required maxlength="120" value="{{ old('name') }}" placeholder="Sala Apolo"></label>
        <label class="f">Catégorie
          <select name="category">
            <option value="">Toutes (source généraliste)</option>
            @foreach ($categories as $value => $label)
              <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </label>
      </div>
      <div class="row">
        <label class="f">URL de la page agenda / programmation<input name="url" type="url" required maxlength="500" value="{{ old('url') }}" placeholder="https://…"></label>
        <label class="f">Scanner tous les (jours)<input name="scan_every_days" type="number" min="1" max="365" value="{{ old('scan_every_days', config('agenda.default_scan_every_days')) }}"></label>
      </div>
      @if ($errors->create->any())
        <ul class="errors">@foreach ($errors->create->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
      @endif
      <button class="btn" type="submit">Ajouter</button>
    </form>
  </details>

  <div class="list">
    @forelse ($sources as $source)
      @php($bag = $errors->getBag('edit-'.$source->id))
      <section class="src {{ $source->active ? '' : 'off' }}">
        <h3><a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer">{{ $source->name }}</a></h3>
        <form method="POST" action="{{ route('agenda.sources.update', $source) }}">
          @csrf @method('PATCH')
          <input type="hidden" name="active" value="{{ $source->active ? 0 : 1 }}">
          <button class="switch" type="submit" role="switch" aria-checked="{{ $source->active ? 'true' : 'false' }}"
                  aria-label="{{ $source->active ? 'Désactiver' : 'Activer' }} {{ $source->name }}"></button>
        </form>
        <div class="meta">
          @if ($source->category)
            <span class="tag" style="--col: var(--c-{{ $source->category }})">{{ $categories[$source->category] ?? $source->category }}</span>
          @else
            <span class="tag">Toutes</span>
          @endif
          <span>{{ $source->events_count }} événement(s)</span>
          <span>tous les {{ $source->scan_every_days }} j</span>
          @if ($source->last_status === 'batch')
            <span class="st-unchanged">extraction en attente (API Batch)</span>
          @elseif ($source->last_scanned_at)
            <span>
              Dernier scan {{ $source->last_scanned_at->timezone('Europe/Madrid')->format('d/m H:i') }} :
              @switch($source->last_status)
                @case('ok') <span class="st-ok">{{ $source->last_found }} trouvé(s)</span> · {{ number_format($source->last_input_tokens + $source->last_output_tokens, 0, ',', ' ') }} tokens @break
                @case('unchanged') <span class="st-unchanged">page inchangée</span> @break
                @case('failed') <span class="st-failed">échec{{ $source->failures > 1 ? ' ('.$source->failures.' de suite)' : '' }}</span> @break
              @endswitch
            </span>
          @else
            <span>Jamais scannée</span>
          @endif
        </div>
        @if ($source->last_status === 'failed' && $source->last_error)
          <div class="err-line">{{ $source->last_error }}</div>
        @endif
        <details class="edit" @if ($bag->any()) open @endif>
          <summary>Modifier</summary>
          <form class="grid" method="POST" action="{{ route('agenda.sources.update', $source) }}">
            @csrf @method('PATCH')
            <div class="row">
              <label class="f">Nom<input name="name" required maxlength="120" value="{{ $bag->any() ? old('name') : $source->name }}"></label>
              <label class="f">Catégorie
                <select name="category">
                  <option value="">Toutes (source généraliste)</option>
                  @foreach ($categories as $value => $label)
                    <option value="{{ $value }}" @selected(($bag->any() ? old('category') : $source->category) === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </label>
            </div>
            <div class="row">
              <label class="f">URL<input name="url" type="url" required maxlength="500" value="{{ $bag->any() ? old('url') : $source->url }}"></label>
              <label class="f">Scanner tous les (jours)<input name="scan_every_days" type="number" min="1" max="365" required value="{{ $bag->any() ? old('scan_every_days') : $source->scan_every_days }}"></label>
            </div>
            @if ($bag->any())
              <ul class="errors">@foreach ($bag->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            @endif
            <div class="actions">
              <button class="btn danger" type="submit" form="del-{{ $source->id }}">Supprimer</button>
              <button class="btn" type="submit">Enregistrer</button>
            </div>
          </form>
          <form id="del-{{ $source->id }}" method="POST" action="{{ route('agenda.sources.destroy', $source) }}"
                onsubmit="return confirm('Supprimer « {{ addslashes($source->name) }} » ? Ses événements restent dans l\'agenda.')">
            @csrf @method('DELETE')
          </form>
        </details>
      </section>
    @empty
      <p class="sub">Aucune source. Ajoutez-en une ci-dessus.</p>
    @endforelse
  </div>
</div>
</body>
</html>
