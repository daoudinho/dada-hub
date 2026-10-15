<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Agenda Barcelone</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap">
<style>
/* Layout: a single narrow column like a phone calendar widget. Date chip on the left, one coloured block per event on the right, grouped by day. */
@include('agenda::partials.theme')
.wrap { max-width: 560px; margin: 0 auto; background: var(--card); border-radius: 32px; padding: 22px 18px 26px; }
.top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 4px 6px 14px; }
.top h1 { font-size: 24px; font-weight: 600; margin: 0; letter-spacing: -0.01em; }
.top small { display: block; color: var(--muted); font-size: 13px; font-weight: 500; margin-top: 2px; }
.add { width: 68px; height: 40px; border: 0; border-radius: 20px; background: var(--btn); color: var(--btn-fg); font-size: 28px; line-height: 1; cursor: pointer; font-family: var(--font); }
.month { margin: 18px 6px 8px; font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
.day { display: flex; flex-direction: column; gap: 8px; padding-block: 8px; }
.date { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: var(--datebg); color: var(--datefg); border-radius: 18px; padding: 12px 18px; font-size: 17px; font-weight: 600; min-height: 48px; font-variant-numeric: tabular-nums; }
button.date { width: 100%; border: 0; text-align: left; font-family: inherit; cursor: pointer; }
.date .meta { display: flex; align-items: center; gap: 10px; }
.date .count { font-size: 13px; font-weight: 600; opacity: .65; }
.date .chev { width: 10px; height: 10px; border-right: 2.5px solid currentColor; border-bottom: 2.5px solid currentColor; transform: rotate(45deg) translate(-2px, -2px); transition: transform .15s; margin-right: 4px; }
.date.closed .chev { transform: rotate(-45deg); }
.blocks[hidden] { display: none; }
@media (prefers-reduced-motion: reduce) { .date .chev { transition: none; } }
.date .pill { font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; background: var(--datefg); color: var(--datebg); border-radius: 12px; padding: 4px 10px; }
.blocks { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.ev { position: relative; display: block; text-decoration: none; background: var(--col, var(--c-fete)); color: var(--on-block); border-radius: 18px; padding: 12px 82px 12px 16px; min-width: 0; }
.ev:hover { filter: brightness(1.06); }
.ev:focus-visible, .add:focus-visible, .x:focus-visible, .fav:focus-visible, button:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
.ev h3 { margin: 0; font-size: 18px; font-weight: 500; line-height: 1.25; overflow-wrap: anywhere; }
.ev p { margin: 3px 0 0; font-size: 14px; opacity: .92; line-height: 1.35; overflow-wrap: anywhere; }
.ev .tag { display: inline-block; margin-top: 6px; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; opacity: .85; }
.x { position: absolute; top: 8px; right: 8px; width: 30px; height: 30px; border: 0; border-radius: 50%; background: rgba(0,0,0,.18); color: inherit; font-size: 18px; line-height: 1; cursor: pointer; font-family: var(--font); }
.x:hover, .fav:hover { background: rgba(0,0,0,.32); }
.fav { position: absolute; top: 8px; right: 44px; width: 30px; height: 30px; border: 0; border-radius: 50%; background: rgba(0,0,0,.18); color: inherit; font-size: 16px; line-height: 1; cursor: pointer; font-family: var(--font); }
.fav[aria-pressed="true"] { background: var(--on-block); color: var(--col, var(--c-fete)); }
.nothing { background: var(--empty); color: var(--fg); border-radius: 18px; padding: 14px 16px; font-size: 17px; }
:root[data-theme="light"] .nothing, :root:not([data-theme]) .nothing { color: var(--fg); }
.none { text-align: center; color: var(--muted); padding: 28px 8px 8px; font-size: 15px; }

form { display: none; margin: 4px 4px 14px; padding: 16px; border-radius: 22px; background: var(--bg); gap: 10px; }
form.open { display: grid; }
form label { display: grid; gap: 4px; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); min-width: 0; }
form input, form select { font: inherit; font-size: 16px; text-transform: none; letter-spacing: 0; font-weight: 400; color: var(--fg); background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; min-width: 0; width: 100%; }
.row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
.actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
.actions button { font: inherit; font-weight: 600; border: 0; border-radius: 20px; padding: 10px 20px; cursor: pointer; }
.ok { background: var(--btn); color: var(--btn-fg); }
.cancel { background: transparent; color: var(--fg); border: 1px solid var(--line) !important; }
.toast { position: fixed; left: 50%; transform: translateX(-50%); bottom: calc(20px + env(safe-area-inset-bottom, 0px)); background: var(--chip); color: var(--chip-fg); border-radius: 22px; padding: 10px 14px 10px 18px; display: flex; gap: 14px; align-items: center; font-size: 15px; max-width: calc(100% - 32px); }
.toast[hidden] { display: none; }
.toast button { font: inherit; font-weight: 700; background: none; border: 0; color: inherit; text-decoration: underline; cursor: pointer; }
.foot { margin: 18px 8px 0; font-size: 12.5px; color: var(--muted); line-height: 1.5; }
.foot a { color: inherit; }

.top-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.scan[aria-pressed="true"] { background: var(--chip); color: var(--chip-fg); border-color: var(--chip); }
.scan { height: 40px; border: 1px solid var(--line); border-radius: 20px; background: transparent; color: var(--fg); font: inherit; font-weight: 600; font-size: 14px; padding: 0 16px; cursor: pointer; }
.scan:disabled { opacity: .6; cursor: progress; }
.overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); display: none; align-items: center; justify-content: center; padding: 16px; z-index: 10; }
.overlay.open { display: flex; }
.modal { background: var(--card); color: var(--fg); border-radius: 28px; padding: 22px; width: 100%; max-width: 420px; display: grid; gap: 14px; }
.modal h2 { margin: 0; font-size: 20px; font-weight: 600; }
.modal p { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.45; }
a.scan { display: inline-flex; align-items: center; text-decoration: none; }
.opts { display: grid; gap: 8px; }
.opts label { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--fg); cursor: pointer; }
.opts input { accent-color: var(--fg); width: 18px; height: 18px; margin: 0; }
.chips { display: flex; flex-wrap: wrap; gap: 8px; }
.chips label { display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--line); border-radius: 20px; padding: 10px 16px; cursor: pointer; font-size: 16px; font-weight: 500; text-transform: none; letter-spacing: 0; color: var(--fg); }
.chips input { accent-color: var(--fg); width: 18px; height: 18px; padding: 0; }
.chips label:has(input:checked) { background: var(--chip); color: var(--chip-fg); border-color: var(--chip); }
.msg { min-height: 20px; font-size: 14px; color: var(--muted); }

.venues { margin: 16px 4px 0; border-radius: 18px; background: var(--bg); padding: 4px 16px 14px; }
.venues summary { cursor: pointer; font-weight: 600; font-size: 16px; padding: 12px 0; list-style: none; display: flex; justify-content: space-between; align-items: center; }
.venues summary::-webkit-details-marker { display: none; }
.venues summary::after { content: "+"; font-size: 22px; font-weight: 500; }
.venues[open] summary::after { content: "–"; }
.venues h4 { margin: 12px 0 8px; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
.vlinks { display: flex; flex-wrap: wrap; gap: 6px; }
.vlinks a { color: var(--fg); text-decoration: none; border: 1px solid var(--line); border-radius: 16px; padding: 6px 12px; font-size: 14px; background: var(--card); }
.vlinks a:hover { border-color: var(--fg); }
.vlinks a:focus-visible, .venues summary:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
</style>
</head>
<body>
<nav class="hub"><a href="{{ route('dashboard') }}">← Hub</a></nav>
<div class="wrap">
  <div class="top">
    <div><h1 id="title">Octobre</h1><small>Barcelone</small></div>
    <div class="top-actions">
      <a class="scan" href="{{ route('agenda.sources.index') }}">Sources</a>
      <button class="scan" id="favBtn" type="button" aria-pressed="false">♥ Favoris</button>
      <button class="scan" id="scanBtn" type="button">Lancer Scan</button>
      <button class="add" id="addBtn" type="button" aria-label="Ajouter un événement" aria-expanded="false">+</button>
    </div>
  </div>

  <form id="form" autocomplete="off">
    <label for="f-title">Titre<input id="f-title" required maxlength="150" placeholder="Nom de l'événement"></label>
    <div class="row">
      <label for="f-date">Date<input id="f-date" type="date" required></label>
      <label for="f-time">Heure (optionnel)<input id="f-time" type="time"></label>
    </div>
    <div class="row">
      <label for="f-end">Jusqu'au (optionnel)<input id="f-end" type="date"></label>
      <label for="f-cat">Catégorie
        <select id="f-cat">
          <option value="fete">Fête / tradition</option>
          <option value="cine">Cinéma</option>
          <option value="musique">Concert / musique</option>
          <option value="scene">Scène</option>
          <option value="expo">Expo art / architecture</option>
          <option value="photo">Expo photo</option>
          <option value="manga">Manga</option>
          <option value="sport">Sport</option>
          <option value="barca">Match du Barça</option>
        </select>
      </label>
    </div>
    <label for="f-place">Lieu (optionnel)<input id="f-place" maxlength="120" placeholder="Quartier, salle…"></label>
    <label for="f-url">Lien vers plus de détails (optionnel)<input id="f-url" type="url" placeholder="https://…"></label>
    <div class="actions">
      <button type="button" class="cancel" id="cancel">Annuler</button>
      <button type="submit" class="ok">Ajouter</button>
    </div>
  </form>

  <div id="list" aria-live="polite"></div>
<details class="venues"><summary>Programmation des lieux</summary>
  <h4>Salles de concert et musique</h4>
  <div class="vlinks"><a href="https://www.palaumusica.cat/ca/temporada-2026-2027_1466030" target="_blank" rel="noopener noreferrer">Palau de la Música Catalana</a><a href="https://www.auditori.cat/en/" target="_blank" rel="noopener noreferrer">L&#x27;Auditori</a><a href="https://www.salarazzmatazz.com/en/" target="_blank" rel="noopener noreferrer">Razzmatazz</a><a href="https://www.sala-apolo.com/en/" target="_blank" rel="noopener noreferrer">Sala Apolo</a><a href="https://palausantjordi.barcelona/en" target="_blank" rel="noopener noreferrer">Palau Sant Jordi</a><a href="https://www.jamboreebarcelona.com/en/" target="_blank" rel="noopener noreferrer">Jamboree</a><a href="https://www.harlemjazzclub.es/" target="_blank" rel="noopener noreferrer">Harlem Jazz Club</a><a href="https://www.sala-bikini.com/en/" target="_blank" rel="noopener noreferrer">Bikini</a><a href="https://www.salabarts.com/en/" target="_blank" rel="noopener noreferrer">Sala Barts</a></div>
  <h4>Opéra et théâtre</h4>
  <div class="vlinks"><a href="https://www.liceubarcelona.cat/en/whats-on" target="_blank" rel="noopener noreferrer">Gran Teatre del Liceu</a><a href="https://www.tnc.cat/en/" target="_blank" rel="noopener noreferrer">TNC</a><a href="https://www.teatrelliure.com/en" target="_blank" rel="noopener noreferrer">Teatre Lliure</a><a href="https://www.teatrepoliorama.com/" target="_blank" rel="noopener noreferrer">Teatre Poliorama</a><a href="https://www.teatreromea.cat/ca/" target="_blank" rel="noopener noreferrer">Teatre Romea</a><a href="https://www.teatretivoli.com/" target="_blank" rel="noopener noreferrer">Teatre Tívoli</a><a href="https://mercatflors.cat/en/season-at-a-glance/" target="_blank" rel="noopener noreferrer">Mercat de les Flors</a><a href="https://www.salabeckett.cat/en/" target="_blank" rel="noopener noreferrer">Sala Beckett</a></div>
  <h4>Musées et expositions</h4>
  <div class="vlinks"><a href="https://museupicasso.bcn.cat/en/exhibitions" target="_blank" rel="noopener noreferrer">Museu Picasso</a><a href="https://www.fundaciomiro-bcn.org/en/exhibitions" target="_blank" rel="noopener noreferrer">Fundació Joan Miró</a><a href="https://www.museunacional.cat/en/exhibitions" target="_blank" rel="noopener noreferrer">MNAC</a><a href="https://www.macba.cat/en/exhibitions-activities/exhibitions" target="_blank" rel="noopener noreferrer">MACBA</a><a href="https://www.cccb.org/en/exhibitions" target="_blank" rel="noopener noreferrer">CCCB</a><a href="https://caixaforum.org/en/barcelona/exposiciones" target="_blank" rel="noopener noreferrer">CaixaForum</a><a href="https://www.fundaciotapies.org/en/exhibitions/" target="_blank" rel="noopener noreferrer">Fundació Tàpies</a><a href="https://www.museumares.bcn.cat/" target="_blank" rel="noopener noreferrer">Museu Frederic Marès</a><a href="https://www.museudeldisseny.barcelona.cat/en/exhibitions" target="_blank" rel="noopener noreferrer">Museu del Disseny</a><a href="https://www.casabatllo.es/en/" target="_blank" rel="noopener noreferrer">Casa Batlló</a><a href="https://www.lapedrera.com/en/exhibitions" target="_blank" rel="noopener noreferrer">La Pedrera</a><a href="https://palauguell.cat/en" target="_blank" rel="noopener noreferrer">Palau Güell</a><a href="https://santpaubarcelona.org/en/activitats/" target="_blank" rel="noopener noreferrer">Hospital de Sant Pau</a></div>
  <h4>Festivals</h4>
  <div class="vlinks"><a href="https://www.jazz.barcelona/" target="_blank" rel="noopener noreferrer">Festival de Jazz</a><a href="https://alternativa.cccb.org/2026/en/" target="_blank" rel="noopener noreferrer">L&#x27;Alternativa</a><a href="https://www.primaverasound.com/" target="_blank" rel="noopener noreferrer">Primavera Sound</a><a href="https://sonar.es/en" target="_blank" rel="noopener noreferrer">Sónar</a><a href="https://cruillabarcelona.com/en/" target="_blank" rel="noopener noreferrer">Cruïlla</a><a href="https://grec.barcelona.cat/en" target="_blank" rel="noopener noreferrer">Grec Festival</a><a href="https://www.barcelona.cat/nitdelsmuseus/en" target="_blank" rel="noopener noreferrer">Nit dels Museus</a></div>
</details>
</div>

<div class="overlay" id="overlay" role="dialog" aria-modal="true" aria-labelledby="mTitle">
  <div class="modal">
    <h2 id="mTitle">Lancer un scan</h2>
    <p>Les pages des sources actives sont récupérées, puis l'IA en extrait les événements des 60 prochains jours. Les pages inchangées depuis le dernier scan ne sont pas ré-analysées.</p>
    <div class="chips" role="group" aria-label="Types d'événements">
      <label><input type="checkbox" name="filter" value="sport"> Sport</label>
      <label><input type="checkbox" name="filter" value="barca"> Barça</label>
      <label><input type="checkbox" name="filter" value="musique"> Concerts</label>
      <label><input type="checkbox" name="filter" value="culturel" checked> Culturel</label>
      <label><input type="checkbox" name="filter" value="expos"> Expos (art, photo, manga)</label>
    </div>
    <div class="opts">
      <label><input type="checkbox" id="mForce"> Tout ré-analyser (même les sources récentes et les pages inchangées)</label>
      @if (config('services.agenda.provider') === 'openai')
        <label><input type="checkbox" id="mBatch"> Moitié prix via l'API Batch (résultats sous 24 h)</label>
      @endif
      <label><input type="checkbox" id="mWeb"> Recherche web libre à la place des sources (plus coûteux)</label>
    </div>
    <div class="msg" id="scanMsg" aria-live="polite"></div>
    <div class="actions">
      <button type="button" class="cancel" id="mClose">Fermer</button>
      <button type="button" class="ok" id="mScan">Scanner</button>
    </div>
  </div>
</div>

<div class="toast" id="toast" hidden><span id="toastMsg"></span><button id="undo" type="button">Annuler</button></div>

<script>
const CATS = { fete: "Fête", cine: "Cinéma", musique: "Concert", scene: "Scène", expo: "Expo art", photo: "Expo photo", manga: "Manga", sport: "Sport", barca: "Barça" };
const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const BASE = @json(route('agenda.index'));
const $ = id => document.getElementById(id);
let events = [], lastRemoved = null, toastTimer = null, favOnly = false;
try { favOnly = localStorage.getItem("agenda-fav-only") === "1"; } catch (e) {}

async function api(path, method = "GET", body) {
  const r = await fetch(BASE + "/" + path, {
    method,
    headers: { "Accept": "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": TOKEN },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || ("HTTP " + r.status));
  return r.json();
}

function ymd(d) { return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); }
function parse(s) { const [y, m, d] = s.slice(0, 10).split("-").map(Number); return new Date(y, m - 1, d); }
const fr = new Intl.DateTimeFormat("fr-FR", { weekday: "short" });
const frDay = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long" });
const frLong = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long" });
const frMonth = new Intl.DateTimeFormat("fr-FR", { month: "long", year: "numeric" });
const cap = s => s.charAt(0).toUpperCase() + s.slice(1);
function el(tag, cls, text) { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
function safeUrl(u) { try { const x = new URL(u); return /^https?:$/.test(x.protocol) ? x.href : ""; } catch (e) { return ""; } }

const collapsed = new Set(); try { JSON.parse(localStorage.getItem("agenda-collapsed") || "[]").forEach(k => collapsed.add(k)); } catch (e) {}

function render() {
  const today = ymd(new Date());
  const list = $("list"); list.textContent = "";
  const byDay = new Map();
  for (const ev of events) {
    if (favOnly && !ev.favorite) continue;
    const start = ev.starts_on.slice(0, 10), end = (ev.ends_on || ev.starts_on).slice(0, 10);
    if (end < today) continue;
    const key = start < today ? today : start;
    if (!byDay.has(key)) byDay.set(key, []);
    byDay.get(key).push(ev);
  }
  if (favOnly && !byDay.size) { list.appendChild(el("div", "none", "Aucun favori à venir.")); return; }
  if (!favOnly && !byDay.has(today)) byDay.set(today, []);
  let month = "";
  for (const key of [...byDay.keys()].sort()) {
    const date = parse(key), m = frMonth.format(date);
    if (month && m !== month) list.appendChild(el("div", "month", cap(m)));
    month = m;
    const row = el("section", "day" + (key === today ? " today" : ""));
    const chip = el("button", "date"); chip.type = "button";
    const meta = el("span", "meta"), count = el("span", "count");
    if (key === today) meta.appendChild(el("span", "pill", "Aujourd'hui"));
    meta.append(count, el("span", "chev"));
    chip.append(el("span", "", cap(frDay.format(date))), meta);
    const blocks = el("div", "blocks");
    const evs = byDay.get(key);
    if (!evs.length) blocks.appendChild(el("div", "nothing", "Rien d'autre aujourd'hui"));
    for (const ev of evs) blocks.appendChild(block(ev, key));
    count.textContent = evs.length ? String(evs.length) : "";
    const setClosed = c => { blocks.hidden = c; chip.classList.toggle("closed", c); chip.setAttribute("aria-expanded", String(!c)); };
    setClosed(collapsed.has(key));
    chip.addEventListener("click", () => {
      const c = !collapsed.has(key);
      if (c) collapsed.add(key); else collapsed.delete(key);
      try { localStorage.setItem("agenda-collapsed", JSON.stringify([...collapsed])); } catch (e) {}
      setClosed(c);
    });
    row.append(chip, blocks); list.appendChild(row);
  }
}

function block(ev, key) {
  const url = safeUrl(ev.url || "");
  const b = el(url ? "a" : "div", "ev");
  if (url) { b.href = url; b.target = "_blank"; b.rel = "noopener noreferrer"; }
  b.style.setProperty("--col", "var(--c-" + (CATS[ev.category] ? ev.category : "fete") + ")");
  b.appendChild(el("h3", "", ev.title));
  const bits = [], start = ev.starts_on.slice(0, 10);
  if (ev.time) bits.push(ev.time);
  if (ev.ends_on) bits.push(start >= key ? "Du " + frLong.format(parse(start)) + " au " + frLong.format(parse(ev.ends_on)) : "Jusqu'au " + frLong.format(parse(ev.ends_on)));
  if (ev.place) bits.push(ev.place);
  if (bits.length) b.appendChild(el("p", "", bits.join(" · ")));
  b.appendChild(el("span", "tag", CATS[ev.category] || "Fête"));
  const x = el("button", "x", "×"); x.type = "button"; x.setAttribute("aria-label", "Retirer " + ev.title);
  x.addEventListener("click", e => { e.preventDefault(); e.stopPropagation(); remove(ev); });
  const fav = el("button", "fav", ev.favorite ? "♥" : "♡"); fav.type = "button";
  fav.setAttribute("aria-pressed", String(!!ev.favorite)); fav.setAttribute("aria-label", "Favori : " + ev.title);
  fav.addEventListener("click", e => { e.preventDefault(); e.stopPropagation(); toggleFavorite(ev); });
  b.append(fav, x);
  return b;
}

async function load() { events = await api("events"); render(); }

async function remove(ev) {
  events = events.filter(e => e.id !== ev.id); render();
  lastRemoved = ev;
  try { await api("events/" + ev.id, "DELETE"); } catch (e) { events.push(ev); render(); return; }
  toast("« " + ev.title + " » retiré");
}
async function undo() {
  if (!lastRemoved) return;
  const ev = lastRemoved; lastRemoved = null; $("toast").hidden = true;
  try {
    await api("events/" + ev.id, "PATCH", { hidden: false });
    await load();
  } catch (e) {}
}
async function toggleFavorite(ev) {
  ev.favorite = !ev.favorite; render();
  try { await api("events/" + ev.id, "PATCH", { favorite: ev.favorite }); } catch (e) { ev.favorite = !ev.favorite; render(); toast("Erreur : " + e.message); }
}
function setFavOnly(on) {
  favOnly = on; $("favBtn").setAttribute("aria-pressed", String(on));
  try { localStorage.setItem("agenda-fav-only", on ? "1" : "0"); } catch (e) {}
  render();
}
$("favBtn").addEventListener("click", () => setFavOnly(!favOnly));
$("favBtn").setAttribute("aria-pressed", String(favOnly));
function toast(msg) {
  $("toastMsg").textContent = msg; $("toast").hidden = false;
  clearTimeout(toastTimer); toastTimer = setTimeout(() => { $("toast").hidden = true; lastRemoved = null; }, 7000);
}

$("addBtn").addEventListener("click", () => {
  const f = $("form"), open = !f.classList.contains("open");
  f.classList.toggle("open", open); $("addBtn").setAttribute("aria-expanded", String(open));
  if (open) { if (!$("f-date").value) $("f-date").value = ymd(new Date()); $("f-title").focus(); }
});
$("cancel").addEventListener("click", () => { $("form").classList.remove("open"); $("addBtn").setAttribute("aria-expanded", "false"); });
$("undo").addEventListener("click", undo);
$("form").addEventListener("submit", async e => {
  e.preventDefault();
  const end = $("f-end").value, d = $("f-date").value;
  try {
    await api("events", "POST", {
      title: $("f-title").value.trim(), starts_on: d, ends_on: end && end > d ? end : null,
      time: $("f-time").value || null, place: $("f-place").value.trim() || null,
      url: safeUrl($("f-url").value.trim()) || null, category: $("f-cat").value,
    });
    $("form").reset(); $("form").classList.remove("open"); $("addBtn").setAttribute("aria-expanded", "false");
    await load();
  } catch (err) { toast("Erreur : " + err.message); }
});

/* Scan */
let polling = null;
function openModal() { $("scanMsg").textContent = ""; $("overlay").classList.add("open"); }
function closeModal() { $("overlay").classList.remove("open"); }
$("scanBtn").addEventListener("click", openModal);
$("mClose").addEventListener("click", closeModal);
$("overlay").addEventListener("click", e => { if (e.target === $("overlay")) closeModal(); });
document.addEventListener("keydown", e => { if (e.key === "Escape") closeModal(); });

$("mScan").addEventListener("click", async () => {
  const filters = [...document.querySelectorAll('input[name="filter"]:checked')].map(i => i.value);
  if (!filters.length) { $("scanMsg").textContent = "Choisis au moins un type."; return; }
  $("mScan").disabled = true; $("scanBtn").disabled = true;
  $("scanMsg").textContent = "Lancement du scan…";
  try {
    const scan = await api("scan", "POST", {
      filters, mode: $("mWeb").checked ? "web" : "sources", force: $("mForce").checked, batch: !!($("mBatch") && $("mBatch").checked),
    });
    if (scan.status === "failed") { finish("Le scan a échoué : " + (scan.error || "erreur inconnue")); return; }
    if (scan.status === "done") { finish(summary(scan)); return; }
    progress(scan);
    poll(scan.id);
  } catch (err) {
    $("scanMsg").textContent = "Erreur : " + err.message;
    $("mScan").disabled = false; $("scanBtn").disabled = false;
  }
});

function finish(msg) {
  clearInterval(polling);
  $("scanMsg").textContent = msg;
  $("mScan").disabled = false; $("scanBtn").disabled = false;
}

function summary(s) {
  if (s.mode === "sources" && !s.sources_total && s.sources_skipped) {
    return "Rien à analyser : les " + s.sources_skipped + " source(s) ont été scannées récemment. Coche « Tout ré-analyser » pour forcer.";
  }
  const cost = s.cost_usd != null ? " · ~" + Number(s.cost_usd).toFixed(4) + " $" : "";
  return s.found + " événement(s) ajouté(s) ou mis à jour. " + s.ai_calls + " appel(s) IA, "
    + (s.input_tokens + s.output_tokens).toLocaleString("fr-FR") + " tokens" + cost
    + (s.mode === "sources" ? ", " + s.firecrawl_credits + " crédit(s) Firecrawl" : "") + ".";
}

function progress(s) {
  if (s.status === "waiting") {
    $("scanMsg").textContent = "Pages récupérées. Extraction envoyée à l'API Batch d'OpenAI : les événements arriveront d'ici quelques heures (24 h max). Tu peux fermer cette fenêtre.";
  } else if (s.status === "pending" && s.age > 15) {
    $("scanMsg").textContent = "En attente… le worker de file d'attente tourne-t-il ? (php artisan queue:work)";
  } else if (s.mode === "sources" && s.sources_total) {
    $("scanMsg").textContent = "Analyse des sources : " + s.sources_done + " / " + s.sources_total + " · " + s.found + " événement(s) trouvé(s)";
  } else {
    $("scanMsg").textContent = "Recherche web en cours… compte 30 à 90 secondes.";
  }
}

function poll(id) {
  clearInterval(polling);
  polling = setInterval(async () => {
    try {
      const s = await api("scan/" + id);
      if (s.status === "done") {
        await load();
        finish(summary(s));
      } else if (s.status === "waiting") {
        progress(s);
        finish($("scanMsg").textContent);
      } else if (s.status === "failed") {
        finish("Le scan a échoué : " + (s.error || "erreur inconnue"));
      } else {
        progress(s);
      }
    } catch (e) {}
  }, 3000);
}

$("title").textContent = cap(new Intl.DateTimeFormat("fr-FR", { month: "long" }).format(new Date()));
load().catch(() => { $("list").appendChild(el("div", "none", "Impossible de charger l'agenda.")); });
</script>
</body>
</html>
