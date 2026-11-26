{{-- Styles des pages Coach (inclus dans la balise <style> du layout). --}}
:root {
  --bg: #0a0b0d;
  --card: #141619;
  --surface: #1c1f24;
  --fg: #eceef1;
  --muted: #8d96a3;
  --line: #2a2e35;
  --brand: #fc5a1e;
  --brand-fg: #ffffff;
  --brand-soft: #2a1a12;
  --accent: #fc5a1e;
  --good: #3ecf7a;
  --danger: #ff6b5b;
  --cycle: #e9799f;
  --chart: #fc7a45;
  --grid: #24282e;
  --shadow: 0 1px 2px rgba(0, 0, 0, .4);
  --font: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif;
  --radius: 14px;
  --bar-height: 64px;
  color-scheme: dark;
}

* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body { margin: 0; background: var(--bg); color: var(--fg); font-family: var(--font); font-size: 16px; line-height: 1.5; }
a { color: var(--brand); }
button, input, select, textarea { font: inherit; color: inherit; }
[hidden] { display: none !important; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
.page { max-width: 1080px; margin: 0 auto; padding: 0 16px 64px; }
.page.narrow { max-width: 720px; }

/* En-tête du module */
.appbar { position: sticky; top: 0; z-index: 20; display: grid; grid-template-columns: 88px 1fr 88px; align-items: center; height: 56px;
  margin: 0 -16px 12px; padding: 0 8px; background: rgba(10, 11, 13, .92); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); }
.appbar h1 { margin: 0; font-size: 17px; text-align: center; letter-spacing: .01em; }
.appbar-actions { display: flex; justify-content: flex-end; align-items: center; }
.icon-btn { display: inline-grid; place-items: center; width: 40px; height: 40px; border: 0; border-radius: 50%; background: transparent; color: var(--fg); cursor: pointer; text-decoration: none; }
.icon-btn:hover { background: var(--surface); }
.icon-btn svg { width: 22px; height: 22px; }
.tabs { display: flex; gap: 4px; margin: -4px 0 16px; overflow-x: auto; }
.tabs a { position: relative; display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 999px; color: var(--muted); font-weight: 600; font-size: 14px; text-decoration: none; white-space: nowrap; }
.tabs a svg { width: 18px; height: 18px; }
.tabs a:hover { background: var(--card); color: var(--fg); }
.tabs a.on { background: var(--surface); color: var(--fg); box-shadow: inset 0 -2px 0 var(--brand); }
.badge { display: inline-grid; place-items: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: var(--accent); color: #fff; font-size: 11px; font-weight: 800; }
@media (max-width: 720px) {
  body { padding-bottom: calc(var(--bar-height) + env(safe-area-inset-bottom)); }
  .tabs { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; margin: 0; gap: 0; justify-content: space-around; overflow: visible;
    height: calc(var(--bar-height) + env(safe-area-inset-bottom)); padding: 6px 2px env(safe-area-inset-bottom);
    background: rgba(14, 15, 18, .97); backdrop-filter: blur(8px); border-top: 1px solid var(--line); }
  .tabs a { flex: 1; flex-direction: column; gap: 2px; padding: 4px 0; border-radius: 10px; font-size: 10.5px; font-weight: 600; }
  .tabs a svg { width: 22px; height: 22px; }
  .tabs a.on { background: transparent; box-shadow: none; color: var(--brand); }
  .tabs .badge { position: absolute; top: 0; right: calc(50% - 20px); min-width: 16px; height: 16px; font-size: 10px; }
  .toast { bottom: calc(var(--bar-height) + 16px); }
  .composer { bottom: calc(var(--bar-height) + env(safe-area-inset-bottom)); }
}

h1 { font-size: 26px; line-height: 1.2; margin: 8px 0 16px; }
h2 { font-size: 17px; margin: 0 0 12px; }
.muted { color: var(--muted); }
.small { font-size: 14px; }

/* Cartes et grilles */
.grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
.card { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); padding: 18px; min-width: 0; }
.card.wide { grid-column: 1 / -1; }
.card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
.card-head h2 { margin: 0; }
.hero { background: linear-gradient(135deg, #2b1308, #141619 70%); border: 1px solid #3a1d10; color: var(--fg); }
.hero .big { font-size: 24px; font-weight: 800; line-height: 1.25; }
.hero .muted { color: var(--muted); }
.hero a { color: var(--fg); }

.alert { border-left: 4px solid var(--accent); }
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.list li { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 12px; background: var(--surface); border-radius: 12px; }
.list .when { font-weight: 700; min-width: 72px; }
.list .what { flex: 1; min-width: 0; text-decoration: none; }

/* Boutons et formulaires */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 0; border-radius: 999px; padding: 10px 18px; font-weight: 700; font-size: 15px; cursor: pointer; text-decoration: none; background: var(--brand); color: var(--brand-fg); }
.btn:hover { filter: brightness(1.08); }
.btn.ghost { background: transparent; color: var(--fg); box-shadow: inset 0 0 0 1.5px var(--line); }
.btn.accent { background: var(--accent); color: #fff; }
.btn.danger { background: transparent; color: var(--danger); box-shadow: inset 0 0 0 1.5px var(--line); }
.btn.small { padding: 6px 12px; font-size: 13px; }
.btn.strava { background: #fc5200; color: #fff; }
.subnav { display: flex; gap: 4px; margin-bottom: 12px; border-bottom: 1.5px solid var(--line); }
.subnav a { padding: 8px 12px; color: var(--muted); font-weight: 700; font-size: 14px; text-decoration: none; border-bottom: 2.5px solid transparent; margin-bottom: -1.5px; }
.subnav a.on { color: var(--fg); border-bottom-color: var(--brand); }
.runs li { align-items: flex-start; }
.runs .what { color: inherit; text-decoration: none; }
.runs .what:hover strong { color: var(--brand); }
.runs .when { display: block; font-size: 13px; color: var(--muted); font-weight: 700; }
.runs .tags { display: flex; flex-wrap: wrap; gap: 4px; justify-content: flex-end; }
.pager { display: flex; justify-content: space-between; margin-top: 12px; }
.stats { display: flex; flex-wrap: wrap; gap: 8px 24px; align-items: baseline; }
.glossary summary { list-style: none; display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 12px; }
.glossary summary::-webkit-details-marker { display: none; }
.glossary summary h2 { margin: 0; }
.glossary .info-btn { display: inline-flex; width: 20px; height: 20px; color: var(--muted); }
.glossary .info-btn svg { width: 20px; height: 20px; }
.glossary[open] .info-btn, .glossary summary:hover .info-btn { color: var(--brand); }
.glossary dl { margin: 0 0 14px; padding: 12px 14px; border-radius: 10px; background: var(--bg); font-size: 13px; display: grid; gap: 4px; }
.glossary dt { font-weight: 700; margin-top: 6px; }
.glossary dt:first-child { margin-top: 0; }
.glossary dd { margin: 0; color: var(--muted); }
.subhead { font-size: 14px; color: var(--muted); margin: 14px 0 6px; }
.on-target { color: var(--good); font-weight: 700; }
.off-target { color: var(--danger); }
.verdict { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; background: var(--surface); }
.verdict-reached { color: var(--good); }
.verdict-partial { color: var(--chart); }
.verdict-missed { color: var(--danger); }
.review .btn { margin-top: 10px; }
.steps { list-style: none; margin: 14px 0 0; padding: 0; display: grid; gap: 14px; }
.step { display: grid; grid-template-columns: 28px 1fr; gap: 12px; align-items: start; }
.step p { margin: 2px 0 0; }
.step .actions { margin-top: 8px; }
.step a { color: var(--fg); }
.step-mark { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; background: var(--surface); color: var(--muted); font-weight: 800; font-size: 14px; }
.step.done .step-mark { background: var(--good); color: #0a0b0d; }
.step.pending .step-mark { box-shadow: inset 0 0 0 2px var(--brand); color: var(--brand); }
.cycle-mark { display: block; font-size: 11px; color: var(--cycle); }
.cycle-mark::before { content: '● '; }
.findings { margin: 0; padding-left: 18px; display: grid; gap: 6px; }
.stack { display: grid; gap: 14px; }
.tiles { display: grid; gap: 12px; }
.tile { display: block; background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); padding: 14px 16px; color: inherit; text-decoration: none; }
a.tile:hover { border-color: #3a3f47; }
.tile-head { display: flex; gap: 10px; align-items: center; }
.tile-head > span:last-child { display: grid; }
.tile-icon { display: inline-grid; place-items: center; width: 36px; height: 36px; border-radius: 50%; background: var(--brand-soft); color: var(--brand); }
.tile-icon svg { width: 20px; height: 20px; }
.tile-type { font-weight: 700; font-size: 14px; }
.tile h3 { margin: 10px 0 8px; font-size: 18px; }
.record-badge { margin: -2px 0 8px; padding: 6px 10px; border-radius: 10px; background: var(--brand-soft); color: var(--fg); font-size: 14px; font-weight: 600; }
.tile-stats { display: flex; gap: 24px; flex-wrap: wrap; }
.tile-stats div { display: grid; }
.tile-stats strong { font-size: 18px; }
.tile-map { height: 180px; margin-top: 12px; border-radius: 10px; overflow: hidden; background: var(--surface); }
.tile-map.map { height: 180px; pointer-events: none; } /* un clic sur la carte ouvre la course (lien de la tuile) */
.tile-map .leaflet-container { background: #1b1e22; }
.tile-route { display: block; width: 100%; height: 100%; background: var(--surface); }
.tile-route path { fill: none; stroke: var(--brand); stroke-width: 3; stroke-linejoin: round; stroke-linecap: round; }
.exploit-group { margin-bottom: 20px; }
.exploit-group h2 { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 8px; }
.exploit-group h2 strong { color: var(--brand); }
.records { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.records a { display: grid; grid-template-columns: 1fr auto; gap: 2px 12px; padding: 10px 12px; background: var(--surface); border-radius: 12px; color: inherit; text-decoration: none; }
.records a:hover { background: #22262c; }
.records strong { color: var(--brand); text-align: right; }
.map-card { padding: 0; overflow: hidden; }
.map { height: 320px; width: 100%; background: #1b1e22; }
/* Carte claire OpenStreetMap passée en sombre : couleurs inversées puis teintes remises en place. */
.map .leaflet-tile-pane { filter: invert(1) hue-rotate(180deg) brightness(.9) contrast(.9) saturate(.6); }
.map .leaflet-control-attribution { background: rgba(20, 22, 25, .8); color: var(--muted); }
.map .leaflet-control-attribution a { color: var(--fg); }
@media (max-width: 720px) { .map { height: 240px; } }
.recovery { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px; }
.recovery div { background: var(--surface); border-radius: 12px; padding: 10px; }
.recovery .stat { font-size: 22px; }
.btn:disabled { opacity: .5; cursor: default; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
form.inline { display: inline; }
.form { display: grid; gap: 14px; }
.row { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
.field { display: grid; gap: 4px; min-width: 0; }
.field > span { font-size: 13px; font-weight: 700; color: var(--muted); }
.field input, .field select, .field textarea { width: 100%; border: 1.5px solid var(--line); border-radius: 10px; background: var(--surface); padding: 9px 11px; font-size: 16px; }
.field textarea { min-height: 80px; resize: vertical; }
.field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--brand); }
.errors { background: #3a1714; color: #ffb4a8; border-radius: 12px; padding: 10px 14px; font-size: 14px; }
.section { margin-bottom: 16px; }
.section h2:not(:first-child) { margin-top: 8px; }
.check { display: inline-flex; align-items: center; gap: 8px; font-size: 15px; cursor: pointer; }
.check input { width: 18px; height: 18px; accent-color: var(--brand); }
.checks { display: flex; flex-wrap: wrap; gap: 8px 18px; }
fieldset { border: 1.5px solid var(--line); border-radius: 12px; padding: 10px 14px; display: grid; gap: 8px; margin: 0; }
legend { font-weight: 700; padding: 0 6px; }
.avail { display: grid; gap: 6px; }
.avail-row { display: grid; grid-template-columns: minmax(110px, 1fr) minmax(90px, 1fr) minmax(110px, 1fr); gap: 8px; align-items: center; }
details.add { margin-top: 12px; }
details.add summary { cursor: pointer; font-weight: 700; color: var(--brand); margin-bottom: 10px; }
.add-row { margin-top: 12px; align-items: end; }
.table { border-collapse: collapse; width: 100%; font-size: 14px; margin: 8px 0; }
.table th, .table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); }
.scale { display: flex; gap: 4px; flex-wrap: wrap; }
.scale label { cursor: pointer; }
.scale input { position: absolute; opacity: 0; pointer-events: none; }
.scale span { display: inline-grid; place-items: center; min-width: 34px; height: 34px; padding: 0 6px; border-radius: 10px; background: var(--surface); border: 1.5px solid var(--line); font-weight: 700; font-size: 14px; }
.scale input:checked + span { background: var(--brand); color: var(--brand-fg); border-color: var(--brand); }
.scale input:focus-visible + span { outline: 3px solid var(--accent); outline-offset: 2px; }
.quick { display: grid; gap: 10px; }
.quick .field > span { margin-bottom: 2px; }
.cal .day.unavailable { background: repeating-linear-gradient(135deg, var(--card), var(--card) 6px, var(--surface) 6px, var(--surface) 12px); }
.cal .unavail { font-size: 11px; color: var(--muted); font-style: italic; }
.weight-form { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; margin-top: 12px; }
.weight-form .field { flex: 1 1 120px; }

/* Graphiques */
.chart { width: 100%; height: auto; display: block; overflow: visible; }
.chart .grid-line { stroke: var(--grid); stroke-width: 1; }
.chart .axis-label { fill: var(--muted); font-size: 11px; }
.chart .line { fill: none; stroke: var(--chart); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
.chart .dot { fill: var(--chart); stroke: var(--card); stroke-width: 2; }
.chart .hit { fill: transparent; cursor: pointer; }
.chart .hit:hover + .dot, .chart .bar:hover { opacity: .8; }
.chart .bar { fill: var(--chart); }
.chart .bar.current { fill: var(--accent); }
.chart-tip { position: absolute; pointer-events: none; background: var(--fg); color: var(--bg); font-size: 12px; font-weight: 600; padding: 4px 8px; border-radius: 6px; white-space: nowrap; transform: translate(-50%, -130%); }
.chart-box { position: relative; }
.chart-data summary { cursor: pointer; font-size: 13px; color: var(--muted); margin-top: 6px; }
.chart-data table { border-collapse: collapse; font-size: 13px; margin-top: 6px; }
.chart-data td, .chart-data th { padding: 2px 10px 2px 0; text-align: left; }
.stat { font-size: 28px; font-weight: 800; }

/* Calendrier */
.cal-nav { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.cal-nav h1 { margin: 0; font-size: 22px; }
.cal-title { text-align: center; }
.cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.cal .dow { font-size: 12px; font-weight: 700; color: var(--muted); text-align: center; text-transform: capitalize; }
.cal .day { background: var(--card); border-radius: 12px; min-height: 110px; padding: 6px; display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.cal .day.past { opacity: .6; }
.cal .day.today { box-shadow: inset 0 0 0 2px var(--accent); }
.cal .num { font-size: 12px; font-weight: 700; color: var(--muted); }
.sess { border: 0; text-align: left; width: 100%; border-radius: 8px; padding: 5px 6px; font-size: 12px; line-height: 1.3; background: var(--brand-soft); color: var(--fg); cursor: pointer; border-left: 3px solid var(--brand); }
.sess strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sess.done { border-left-color: var(--good); }
.sess.cancelled { opacity: .55; text-decoration: line-through; border-left-color: var(--muted); }
.sess.free { display: block; background: var(--surface); border-left-color: var(--good); text-decoration: none; }
.sess.t-intervals, .sess.t-tempo { border-left-color: var(--accent); }
.sess.t-test, .sess.t-race { border-left-color: #8a4fc7; }
@media (max-width: 720px) {
  .cal { grid-template-columns: 1fr; }
  .cal .dow, .cal .day.empty-day { display: none; }
  .cal .day { min-height: 0; flex-direction: row; flex-wrap: wrap; align-items: center; }
  .cal .num { min-width: 64px; }
  .sess { width: auto; flex: 1; }
}
dialog { border: 0; border-radius: var(--radius); padding: 20px; max-width: min(520px, calc(100vw - 32px)); width: 100%; background: var(--card); color: var(--fg); box-shadow: var(--shadow); }
dialog::backdrop { background: rgba(10, 15, 25, .5); }
dialog h2 { margin-top: 0; }
.desc { white-space: pre-line; }

/* Chat */
.chat { display: flex; flex-direction: column; gap: 12px; padding-bottom: 8px; }
.bubble { max-width: 85%; padding: 10px 14px; border-radius: 16px; background: var(--card); box-shadow: var(--shadow); overflow-wrap: anywhere; }
.bubble.user { align-self: flex-end; background: var(--brand); color: var(--brand-fg); border-bottom-right-radius: 4px; white-space: pre-line; }
.bubble.assistant { align-self: flex-start; border-bottom-left-radius: 4px; }
.bubble.assistant p { margin: 0 0 8px; }
.bubble.assistant p:last-child { margin-bottom: 0; }
.bubble.assistant ul, .bubble.assistant ol { margin: 4px 0 8px; padding-left: 20px; }
.proposal { align-self: stretch; border: 2px solid var(--brand); }
.proposal.accepted { border-color: var(--good); }
.proposal.refused { border-color: var(--line); opacity: .7; }
.proposal ul { margin: 8px 0; padding-left: 18px; font-size: 14px; max-height: 260px; overflow: auto; }
.composer { position: sticky; bottom: 0; display: flex; gap: 8px; padding: 12px 0; background: var(--bg); }
.composer textarea { flex: 1; border: 1.5px solid var(--line); border-radius: 14px; background: var(--card); padding: 10px 12px; min-height: 48px; max-height: 200px; resize: vertical; font-size: 16px; }
.typing { align-self: flex-start; color: var(--muted); font-size: 14px; font-style: italic; }
.conv-list a { color: inherit; text-decoration: none; }
.pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--surface); color: var(--muted); }
.pill.pending { background: var(--accent); color: #fff; }

.toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); background: var(--fg); color: var(--bg); padding: 10px 18px; border-radius: 999px; font-weight: 600; font-size: 14px; box-shadow: var(--shadow); z-index: 10; max-width: calc(100vw - 32px); text-align: center; animation: toast 5s forwards; }
.toast.sync { animation: none; }
.toast.sync.fade { animation: toast 10s forwards; }
.toast a { color: inherit; text-decoration: underline; }
.spinning svg { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.suggest { margin-top: 12px; padding: 12px 14px; border-radius: 10px; background: var(--brand-soft); }
.suggest p { margin: 0 0 8px; }
.suggest .actions { display: flex; gap: 8px; margin: 0; }
.after-steps { display: flex; gap: 6px; flex-wrap: wrap; margin: 10px 0 12px; padding: 0; list-style: none; font-size: 13px; }
.after-steps li { padding: 3px 10px; border-radius: 999px; background: var(--surface); color: var(--muted); }
.after-steps li.done { color: var(--good); }
.after-steps li.current { color: var(--fg); box-shadow: inset 0 0 0 1.5px var(--brand); }
@keyframes toast { 0%, 85% { opacity: 1; } 100% { opacity: 0; visibility: hidden; } }
