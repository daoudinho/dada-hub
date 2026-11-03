@extends('recettes::layout')

@section('title', 'Panier · Mes Recettes')

@section('content')
<div class="page">
  @include('recettes::partials.topbar', ['backUrl' => route('recettes.index'), 'backLabel' => 'Recettes'])

  <h1 class="page-title script">Mon panier</h1>

  @if ($items->isEmpty())
    <div class="empty">
      <div class="big" aria-hidden="true">🧺</div>
      <p>Le panier est vide. Ajoutez des recettes pour obtenir la liste de courses.</p>
      <a class="btn" href="{{ route('recettes.index') }}">Voir les recettes</a>
    </div>
  @else
    <div class="cart-layout">
      <section>
        <div class="shopping-head">
          <h2 class="section script" style="margin-top: 0">Recettes ({{ $items->count() }})</h2>
          <form method="POST" action="{{ route('recettes.cart.clear') }}" onsubmit="return confirm('Vider le panier ?')">
            @csrf @method('DELETE')
            <button class="link-btn">Vider</button>
          </form>
        </div>
        <div class="cart-items">
          @foreach ($items as $item)
            @php($recipe = $item->recipe)
            <article class="cart-item">
              <a class="mini" href="{{ route('recettes.show', $recipe) }}" tabindex="-1" aria-hidden="true">
                @if ($recipe->imageUrl())<img src="{{ $recipe->imageUrl() }}" alt="" loading="lazy">@else 🍽️ @endif
              </a>
              <div>
                <h3><a href="{{ route('recettes.show', $recipe) }}">{{ $recipe->name }}</a></h3>
                <form method="POST" action="{{ route('recettes.cart.update', $recipe) }}" class="stepper">
                  @csrf @method('PATCH')
                  <button name="servings" value="{{ $item->servings - 1 }}" @disabled($item->servings <= 1) aria-label="Une personne de moins">@include('recettes::partials.icon', ['name' => 'minus', 'size' => 16])</button>
                  <span class="value">@include('recettes::partials.icon', ['name' => 'users', 'size' => 16]) {{ $item->servings }} pers.</span>
                  <button name="servings" value="{{ $item->servings + 1 }}" aria-label="Une personne de plus">@include('recettes::partials.icon', ['name' => 'plus', 'size' => 16])</button>
                </form>
              </div>
              <form method="POST" action="{{ route('recettes.cart.destroy', $recipe) }}">
                @csrf @method('DELETE')
                <button class="del" title="Retirer du panier">@include('recettes::partials.icon', ['name' => 'close', 'size' => 18])<span class="sr-only">Retirer « {{ $recipe->name }} » du panier</span></button>
              </form>
            </article>
          @endforeach
        </div>
      </section>

      <section class="shopping-col">
        <div class="shopping-head">
          <h2 class="section script" style="margin-top: 0">@include('recettes::partials.icon', ['name' => 'basket', 'size' => 24]) Liste de courses</h2>
          <button type="button" class="btn small ghost" id="copyList">@include('recettes::partials.icon', ['name' => 'copy', 'size' => 16]) Copier</button>
        </div>
        @if ($lines->isEmpty())
          <p class="empty">Ces recettes n'ont pas encore d'ingrédients.</p>
        @else
          <div class="shopping">
            <ul id="shoppingList">
              @foreach ($lines as $line)
                <li>
                  <label>
                    <input type="checkbox" data-key="{{ $line['key'] }}">
                    <span class="ing-emoji" aria-hidden="true">{{ $line['emoji'] ?? '•' }}</span>
                    <span class="what">
                      <strong data-name>{{ $line['name'] }}</strong>
                      <small>{{ implode(', ', $line['recipes']) }}</small>
                    </span>
                    <span class="qty" data-amount>{{ $line['amount'] }}</span>
                  </label>
                </li>
              @endforeach
            </ul>
          </div>
        @endif
      </section>
    </div>
  @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
  const list = document.getElementById('shoppingList');
  if (!list) return;
  const storageKey = 'recettes.checked';
  const read = () => { try { return JSON.parse(localStorage.getItem(storageKey) || '[]'); } catch { return []; } };
  const write = (keys) => { try { localStorage.setItem(storageKey, JSON.stringify(keys)); } catch {} };

  // Cases cochées gardées dans ce navigateur, pendant les courses.
  const checked = new Set(read());
  list.querySelectorAll('input[data-key]').forEach((box) => {
    box.checked = checked.has(box.dataset.key);
    box.addEventListener('change', () => {
      box.checked ? checked.add(box.dataset.key) : checked.delete(box.dataset.key);
      write([...checked]);
    });
  });

  document.getElementById('copyList').addEventListener('click', async (event) => {
    const text = [...list.querySelectorAll('li')]
      .filter((li) => !li.querySelector('input').checked)
      .map((li) => '- ' + (li.querySelector('[data-amount]').textContent.trim() + ' ' + li.querySelector('[data-name]').textContent.trim()).trim())
      .join('\n');
    try {
      await navigator.clipboard.writeText(text);
      event.currentTarget.lastChild.textContent = ' Copiée !';
    } catch {
      window.prompt('Liste de courses', text);
    }
  });
})();
</script>
@endpush
