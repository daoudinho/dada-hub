{{-- Ligne d'exercice du formulaire de séance. Paramètres : $index, $row (valeurs déjà saisies), $exercises. --}}
<fieldset class="entry">
  <legend class="sr-only">Exercice</legend>
  <label class="field entry-exercise"><span>Exercice</span>
    <select name="entries[{{ $index }}][exercise_id]">
      <option value="">—</option>
      @foreach ($exercises as $exercise)
        <option value="{{ $exercise->id }}" data-measure="{{ $exercise->measure }}" @selected((int) ($row['exercise_id'] ?? 0) === $exercise->id)>{{ $exercise->name }}{{ $exercise->unilateral ? ' (par côté)' : '' }}</option>
      @endforeach
    </select>
  </label>
  <label class="field"><span>Séries</span><input type="number" name="entries[{{ $index }}][sets]" min="1" max="20" value="{{ $row['sets'] ?? 3 }}"></label>
  <label class="field" data-field="reps"><span>Répétitions</span><input type="number" name="entries[{{ $index }}][reps]" min="1" max="500" value="{{ $row['reps'] ?? '' }}"></label>
  <label class="field" data-field="load"><span>Charge (kg)</span><input name="entries[{{ $index }}][load_kg]" inputmode="decimal" placeholder="—" value="{{ $row['load_kg'] ?? '' }}"></label>
  <label class="field" data-field="duration" hidden><span>Durée (s)</span><input type="number" name="entries[{{ $index }}][duration_seconds]" min="1" max="3600" value="{{ $row['duration_seconds'] ?? '' }}"></label>
</fieldset>
