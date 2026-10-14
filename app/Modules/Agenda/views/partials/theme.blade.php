{{-- Couleurs et bases communes aux pages de l'Agenda (à inclure dans une balise <style>). --}}
:root {
  --bg: #f4f4f7;
  --card: #e9eaf0;
  --datebg: #ffffff;
  --datefg: #1d1d24;
  --fg: #1d1d24;
  --muted: #6b6b78;
  --empty: #e6e6ec;
  --chip: #1d1d24;
  --chip-fg: #ffffff;
  --btn: #1d1d24;
  --btn-fg: #ffffff;
  --line: #d6d6de;
  --c-fete: #6f73b8;
  --c-cine: #b0587a;
  --c-musique: #2f8f9a;
  --c-scene: #b7793a;
  --c-expo: #4f9a6b;
  --c-sport: #7a8a2f;
  --c-photo: #8a62a8;
  --c-manga: #c25a4a;
  --c-barca: #2a52a8;
  --on-block: #ffffff;
  --font: "Figtree", system-ui, -apple-system, "Segoe UI", sans-serif;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --bg: #0d0d12; --card: #303033; --datebg: #f4f4f6; --datefg: #1d1d24; --fg: #e8e8ee; --muted: #a9a9b5; --empty: #5f5f61;
    --chip: #f4f4f6; --chip-fg: #1d1d24; --btn: #f8f8fa; --btn-fg: #4a4a52; --line: #46464c;
    --c-fete: #8589c4; --c-cine: #c47a9a; --c-musique: #5ec0cc; --c-scene: #c99660; --c-expo: #78c093; --c-sport: #b5c65a; --c-photo: #b394d0; --c-manga: #e08070; --c-barca: #6f94e6;
    --on-block: #15151b;
    color-scheme: dark;
  }
}
:root[data-theme="dark"] {
  --bg: #0d0d12; --card: #303033; --datebg: #f4f4f6; --datefg: #1d1d24; --fg: #e8e8ee; --muted: #a9a9b5; --empty: #5f5f61;
  --chip: #f4f4f6; --chip-fg: #1d1d24; --btn: #f8f8fa; --btn-fg: #4a4a52; --line: #46464c;
  --c-fete: #8589c4; --c-cine: #c47a9a; --c-musique: #5ec0cc; --c-scene: #c99660; --c-expo: #78c093; --c-sport: #b5c65a; --c-photo: #b394d0; --c-manga: #e08070; --c-barca: #6f94e6;
  --on-block: #15151b;
  color-scheme: dark;
}
* { box-sizing: border-box; }
body { background: var(--bg); color: var(--fg); font-family: var(--font); padding-inline: 16px; padding-block: 20px 48px; }
.hub { max-width: 560px; margin: 0 auto 12px; }
.hub a { color: var(--muted); text-decoration: none; font-size: 14px; font-weight: 600; }
.hub a:hover { color: var(--fg); }
