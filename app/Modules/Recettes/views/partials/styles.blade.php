{{-- Styles des pages Recettes (inclus dans la balise <style> du layout). --}}
:root {
  --bg: #fbf3e9;
  --surface: #fffaf4;
  --card: #ffffff;
  --fg: #2a221d;
  --muted: #7c6f65;
  --line: #ecdfcf;
  --chip: #f3e6d5;
  --chip-fg: #3b3029;
  --green: #2f4a3b;
  --green-fg: #ffffff;
  --accent: #e2763c;
  --danger: #b8432f;
  --shadow: 0 1px 2px rgba(60, 40, 20, .06), 0 6px 18px rgba(60, 40, 20, .08);
  --placeholder: linear-gradient(135deg, #f6e3c8, #efd2ae);
  --title: "Kalam", "Segoe Print", cursive;
  --font: "Nunito", system-ui, -apple-system, "Segoe UI", sans-serif;
  --radius: 20px;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #191512; --surface: #221d19; --card: #2b2520; --fg: #f4ece2; --muted: #b6a899; --line: #3d352e;
    --chip: #3a3029; --chip-fg: #f0e4d6; --green: #9cc3a3; --green-fg: #142019;
    --accent: #f08d55; --danger: #ee8173; --shadow: 0 1px 2px rgba(0,0,0,.3), 0 6px 18px rgba(0,0,0,.25);
    --placeholder: linear-gradient(135deg, #3a2f26, #2c241e);
    color-scheme: dark;
  }
}

* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body { margin: 0; background: var(--bg); color: var(--fg); font-family: var(--font); font-size: 16px; line-height: 1.45; }
a { color: inherit; }
img { display: block; max-width: 100%; }
button, input, select, textarea { font: inherit; color: inherit; }
.icon { flex: none; }
[hidden] { display: none !important; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
.page { max-width: 1180px; margin: 0 auto; padding: 12px 16px 96px; }
.page.narrow { max-width: 760px; }

/* Barre du haut */
.topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 44px; }
.topbar .back { display: inline-flex; align-items: center; gap: 4px; color: var(--muted); font-weight: 700; font-size: 14px; text-decoration: none; }
.topbar .back:hover { color: var(--fg); }
.topbar nav { display: flex; gap: 6px; }
.round { position: relative; display: inline-grid; place-items: center; width: 42px; height: 42px; border-radius: 50%; border: 0; background: var(--card); color: var(--fg); box-shadow: var(--shadow); cursor: pointer; text-decoration: none; }
.round:hover { background: var(--chip); }
.round.on { background: var(--green); color: var(--green-fg); }
.badge { position: absolute; top: -3px; right: -3px; min-width: 19px; height: 19px; padding: 0 5px; border-radius: 10px; background: var(--accent); color: #fff; font-size: 11px; font-weight: 800; display: grid; place-items: center; }

/* Titres manuscrits */
.brand { margin: 6px 0 16px; }
.brand-title { display: flex; align-items: center; justify-content: center; gap: 12px; margin: 0; width: 100%; }
.brand-tools { display: none; }
.brand-title .icon { color: var(--fg); }
.script { font-family: var(--title); font-weight: 700; letter-spacing: -.01em; line-height: 1.1; }
.brand h1 { font-size: clamp(34px, 12vw, 46px); white-space: nowrap; margin: 0; position: relative; padding-bottom: 6px; }
.brand h1::after { content: ""; position: absolute; left: 12%; right: 8%; bottom: -2px; height: 7px; background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 8' preserveAspectRatio='none'%3E%3Cpath d='M2 6C30 1 80 0 118 4' fill='none' stroke='%23e2763c' stroke-width='3' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat center / 100% 100%; }
h2.section { display: flex; align-items: center; gap: 8px; font-size: 25px; margin: 26px 0 10px; }
h2.section .icon { color: var(--green); }

/* Recherche */
.search { display: none; align-items: center; gap: 8px; background: var(--card); border-radius: 999px; padding: 0 14px; box-shadow: var(--shadow); margin-bottom: 12px; }
.search.open { display: flex; }
.search input { flex: 1; min-width: 0; border: 0; background: transparent; padding: 12px 0; font-size: 16px; outline: none; }
.search .icon { color: var(--muted); }

/* Filtres par étiquette */
.chips { display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 10px; margin: 0 -16px 6px; padding-inline: 16px; scrollbar-width: none; }
.chips::-webkit-scrollbar { display: none; }
.chip { flex: none; border: 0; border-radius: 999px; padding: 9px 16px; background: var(--chip); color: var(--chip-fg); font-weight: 700; font-size: 15px; cursor: pointer; white-space: nowrap; }
.chip[aria-pressed="true"] { background: var(--green); color: var(--green-fg); }

/* Grille de recettes */
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.card { position: relative; background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; display: flex; flex-direction: column; }
.card.off { opacity: .6; }
.card > a { color: inherit; text-decoration: none; display: flex; flex-direction: column; flex: 1; }
.card > a:focus-visible { outline-offset: -3px; border-radius: var(--radius); }
.thumb { position: relative; aspect-ratio: 1 / .86; background: var(--placeholder); display: grid; place-items: center; font-size: 44px; overflow: hidden; }
.thumb img { width: 100%; height: 100%; object-fit: cover; transition: transform .35s; }
.card:hover .thumb img { transform: scale(1.04); }
.pill { display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 4px 10px; background: var(--surface); color: var(--fg); font-size: 13px; font-weight: 700; white-space: nowrap; }
.thumb .pill { position: absolute; top: 10px; left: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
.card .body { padding: 10px 12px 12px; display: grid; gap: 6px; }
.card h3 { margin: 0; font-size: 16px; font-weight: 800; line-height: 1.25; }
.meta { display: flex; flex-wrap: wrap; gap: 4px 12px; color: var(--muted); font-size: 13px; font-weight: 600; }
.meta span { display: inline-flex; align-items: center; gap: 4px; }
.card .cart-btn { position: absolute; top: 8px; right: 8px; width: 38px; height: 38px; }
.status-line { font-size: 13px; font-weight: 700; color: var(--accent); }
.status-line.bad { color: var(--danger); }
.empty { text-align: center; color: var(--muted); padding: 48px 16px; }
.empty .big { font-size: 48px; }
.foot-link { display: block; text-align: center; margin: 28px 0 0; color: var(--muted); font-weight: 700; font-size: 14px; }

/* Boutons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 999px; padding: 11px 20px; font-weight: 800; font-size: 15px; cursor: pointer; text-decoration: none; background: var(--green); color: var(--green-fg); }
.btn:hover { filter: brightness(1.08); }
.btn.ghost { background: var(--card); color: var(--fg); box-shadow: inset 0 0 0 1.5px var(--line); }
.btn.danger { background: transparent; color: var(--danger); box-shadow: inset 0 0 0 1.5px var(--line); }
.btn.wide { width: 100%; padding: 15px 20px; font-size: 16px; }
.btn.small { padding: 7px 14px; font-size: 14px; }
/* Bouton d'ajout en bas d'écran, pleine largeur (mobile) */
.add-bar { position: fixed; left: 16px; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom)); padding: 15px 20px; font-size: 16px; z-index: 5; box-shadow: 0 8px 22px rgba(47, 74, 59, .35); }
.link-btn { border: 0; background: none; padding: 0; color: var(--muted); font-weight: 700; cursor: pointer; text-decoration: underline; }

/* Fiche recette */
.hero { position: relative; aspect-ratio: 4 / 3; max-height: 52vh; width: 100%; background: var(--placeholder); display: grid; place-items: center; font-size: 72px; overflow: hidden; }
.hero img { width: 100%; height: 100%; object-fit: cover; }
.hero-actions { position: absolute; inset: 14px 16px auto; display: flex; justify-content: space-between; }
.sheet { position: relative; background: var(--surface); border-radius: 28px 28px 0 0; margin-top: -28px; padding: 24px 20px 32px; }
.sheet .leaf { position: absolute; right: 20px; top: 26px; color: #6d9a5b; transform: rotate(-12deg); }
.sheet h1 { font-size: 34px; margin: 0 48px 12px 0; }
.facts { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin-bottom: 14px; }
.fact { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; }
.stepper { display: inline-flex; align-items: center; gap: 2px; background: var(--chip); border-radius: 999px; padding: 3px; }
.stepper button { width: 30px; height: 30px; border-radius: 50%; border: 0; background: var(--card); cursor: pointer; display: grid; place-items: center; }
.stepper button:disabled { opacity: .4; cursor: default; }
.stepper .value { min-width: 64px; text-align: center; font-weight: 800; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; gap: 5px; }
.tag-pill { background: var(--chip); color: var(--chip-fg); }
.lead { color: var(--fg); opacity: .85; margin: 0 0 6px; font-size: 16px; }
.ingredients { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
.ingredients li { display: grid; grid-template-columns: 34px 1fr; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px dashed var(--line); }
.ingredients li:last-child { border-bottom: 0; }
.ing-emoji { font-size: 22px; text-align: center; }
.ing-amount { font-weight: 800; }
.ing-note { color: var(--muted); }
.steps { list-style: none; counter-reset: step; margin: 0; padding: 0; display: grid; gap: 14px; }
.steps li { counter-increment: step; display: grid; grid-template-columns: 30px 1fr; gap: 12px; align-items: start; }
.steps li::before { content: counter(step); width: 28px; height: 28px; border-radius: 50%; background: var(--green); color: var(--green-fg); display: grid; place-items: center; font-weight: 800; font-size: 14px; margin-top: 1px; }
.actions-bar { position: sticky; bottom: 0; padding: 14px 0 calc(14px + env(safe-area-inset-bottom)); background: linear-gradient(to bottom, transparent, var(--surface) 30%); display: grid; gap: 10px; margin-top: 20px; }
.actions-row { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
.notice { border-radius: 16px; padding: 14px 16px; background: var(--chip); margin: 12px 0; display: grid; gap: 8px; }
.notice.bad { background: color-mix(in srgb, var(--danger) 14%, var(--surface)); }
.spinner { width: 18px; height: 18px; border-radius: 50%; border: 3px solid var(--line); border-top-color: var(--accent); animation: spin .9s linear infinite; display: inline-block; vertical-align: -3px; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Formulaires */
.page-title { font-size: 34px; margin: 4px 0 16px; }
.tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; background: var(--chip); padding: 4px; border-radius: 999px; margin-bottom: 18px; }
.tabs a { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 10px; border-radius: 999px; text-decoration: none; font-weight: 800; font-size: 15px; color: var(--chip-fg); }
.tabs a[aria-current="page"] { background: var(--card); color: var(--fg); box-shadow: var(--shadow); }
.panel { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); padding: 18px 16px; margin-bottom: 14px; display: grid; gap: 14px; }
.panel h2 { margin: 0; font-size: 18px; font-weight: 800; }
.panel .hint { margin: -6px 0 0; color: var(--muted); font-size: 14px; }
.field { display: grid; gap: 6px; min-width: 0; }
.field > span { font-size: 13px; font-weight: 800; color: var(--muted); }
.field input, .field textarea, .field select, .row-input { width: 100%; min-width: 0; border: 1.5px solid var(--line); border-radius: 12px; background: var(--surface); padding: 10px 12px; font-size: 16px; }
.field textarea { resize: vertical; min-height: 84px; }
.field input:focus, .field textarea:focus, .row-input:focus { outline: none; border-color: var(--green); }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.error { color: var(--danger); font-size: 14px; font-weight: 700; }
.errors { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: var(--danger); border-radius: 14px; padding: 12px 16px 12px 34px; margin: 0 0 14px; font-weight: 700; font-size: 14px; }
.check-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.check-chips label { position: relative; }
.check-chips input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
.check-chips span { display: inline-block; border-radius: 999px; padding: 8px 14px; background: var(--chip); color: var(--chip-fg); font-weight: 700; font-size: 14px; }
.check-chips input:checked + span { background: var(--green); color: var(--green-fg); }
.check-chips input:focus-visible + span { outline: 3px solid var(--accent); outline-offset: 2px; }
.rows { display: grid; gap: 10px; }
.ing-row { display: grid; grid-template-columns: 52px 74px 1fr 40px; grid-template-areas: "emoji qty unit del" "name name note note"; gap: 6px; padding-bottom: 10px; border-bottom: 1px dashed var(--line); }
.ing-row .r-emoji { grid-area: emoji; text-align: center; padding-inline: 4px; }
.ing-row .r-qty { grid-area: qty; }
.ing-row .r-unit { grid-area: unit; }
.ing-row .r-name { grid-area: name; }
.ing-row .r-note { grid-area: note; }
.ing-row .r-del { grid-area: del; }
.step-row { display: grid; grid-template-columns: 28px 1fr 40px; gap: 8px; align-items: start; }
.step-row .num { width: 28px; height: 28px; margin-top: 8px; border-radius: 50%; background: var(--green); color: var(--green-fg); display: grid; place-items: center; font-weight: 800; font-size: 13px; }
.step-row textarea { min-height: 64px; resize: vertical; }
.del { width: 40px; height: 40px; border-radius: 12px; border: 0; background: transparent; color: var(--muted); cursor: pointer; display: grid; place-items: center; }
.del:hover { color: var(--danger); background: var(--chip); }
.add-row { justify-self: start; }
.photo { display: flex; gap: 14px; align-items: center; }
.photo img { width: 96px; height: 96px; object-fit: cover; border-radius: 14px; }
.switch-line { display: flex; align-items: center; gap: 10px; font-weight: 700; }
.switch-line input { width: 20px; height: 20px; accent-color: var(--green); }
.form-actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
.danger-zone { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; margin-top: 8px; }

/* Panier */
.cart-layout { display: grid; gap: 18px; }
.cart-items { display: grid; gap: 10px; }
.cart-item { display: grid; grid-template-columns: 64px 1fr auto; gap: 12px; align-items: center; background: var(--card); border-radius: 18px; padding: 10px; box-shadow: var(--shadow); }
.cart-item .mini { width: 64px; height: 64px; border-radius: 12px; overflow: hidden; background: var(--placeholder); display: grid; place-items: center; font-size: 26px; }
.cart-item .mini img { width: 100%; height: 100%; object-fit: cover; }
.cart-item h3 { margin: 0 0 6px; font-size: 15px; font-weight: 800; line-height: 1.25; }
.cart-item h3 a { text-decoration: none; }
.shopping { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); padding: 6px 14px; }
.shopping ul { list-style: none; margin: 0; padding: 0; }
.shopping li { border-bottom: 1px dashed var(--line); }
.shopping li:last-child { border-bottom: 0; }
.shopping label { display: grid; grid-template-columns: 22px 30px 1fr auto; gap: 10px; align-items: center; padding: 11px 0; cursor: pointer; }
.shopping input { width: 20px; height: 20px; accent-color: var(--green); }
.shopping .what { display: grid; }
.shopping .what strong { font-weight: 800; }
.shopping .what small { color: var(--muted); font-size: 12px; }
.shopping .qty { font-weight: 800; white-space: nowrap; text-align: right; }
.shopping input:checked ~ .what, .shopping input:checked ~ .qty { text-decoration: line-through; opacity: .45; }
.shopping-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; }

/* Étiquettes */
.tag-list { display: grid; gap: 10px; }
.tag-row { display: grid; grid-template-columns: 64px 1fr auto auto; gap: 8px; align-items: center; background: var(--card); border-radius: 16px; padding: 10px; box-shadow: var(--shadow); }
.tag-row .count { color: var(--muted); font-size: 13px; font-weight: 700; white-space: nowrap; }
.tag-row.new { grid-template-columns: 64px 1fr auto; padding: 0; box-shadow: none; }
.tag-row .emoji-input { text-align: center; }
.tag-row .error { grid-column: 1 / -1; margin: 0; }
.tag-actions { display: flex; gap: 4px; }

/* Message de confirmation */
.toast { position: fixed; left: 50%; bottom: 92px; transform: translateX(-50%); background: var(--fg); color: var(--bg); padding: 11px 18px; border-radius: 999px; font-weight: 700; font-size: 14px; box-shadow: var(--shadow); z-index: 10; max-width: calc(100vw - 32px); text-align: center; animation: toast 4s forwards; }
@keyframes toast { 0%, 85% { opacity: 1; } 100% { opacity: 0; visibility: hidden; } }

@media (prefers-reduced-motion: reduce) {
  .thumb img, .spinner, .toast { transition: none; animation: none; }
}

/* Tablette et ordinateur */
@media (min-width: 720px) {
  .page { padding-inline: 28px; }
  .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; }
  .brand h1 { font-size: 46px; }
  .search { display: flex; width: 300px; margin: 0; }
  .search-toggle, .add-bar { display: none; }
  .brand { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
  .brand-title { width: auto; justify-content: flex-start; }
  .brand-tools { display: flex; align-items: center; gap: 12px; }
  .card h3 { font-size: 17px; }
  .ing-row { grid-template-columns: 56px 80px 120px 1fr 1fr 40px; grid-template-areas: "emoji qty unit name note del"; padding-bottom: 0; border-bottom: 0; }
  .chips { margin-inline: 0; padding-inline: 2px; flex-wrap: wrap; }
  .toast { bottom: 28px; }
}
@media (min-width: 1040px) {
  .grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .recipe-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 40px; max-width: 1180px; margin: 0 auto; padding: 16px 28px 40px; align-items: start; }
  .recipe-layout .hero { position: sticky; top: 16px; aspect-ratio: auto; height: calc(100vh - 32px); max-height: 760px; border-radius: 28px; }
  .recipe-layout .sheet { margin-top: 0; border-radius: 28px; padding: 32px 36px; }
  .recipe-layout .sheet h1 { font-size: 44px; }
  .actions-bar { position: static; background: none; padding-bottom: 0; }
  .cart-layout { grid-template-columns: minmax(0, 5fr) minmax(0, 6fr); align-items: start; gap: 28px; }
  .cart-layout .shopping-col { position: sticky; top: 16px; }
}
