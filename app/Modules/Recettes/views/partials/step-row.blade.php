{{-- Ligne d'étape du formulaire. Paramètres : $index, $step. --}}
<div class="step-row" data-row>
  <span class="num" aria-hidden="true">{{ is_int($index) ? $index + 1 : '' }}</span>
  <textarea class="row-input" name="steps[{{ $index }}]" maxlength="2000" placeholder="Lavez et coupez les tomates en tranches." aria-label="Étape">{{ $step }}</textarea>
  <button type="button" class="del" data-remove title="Retirer l'étape">@include('recettes::partials.icon', ['name' => 'close', 'size' => 18])<span class="sr-only">Retirer l'étape</span></button>
</div>
