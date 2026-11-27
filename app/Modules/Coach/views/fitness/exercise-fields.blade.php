{{-- Champs d'un exercice (ajout ou modification). Paramètres : $exercise (null pour un ajout), $equipment. --}}
@php
  use App\Modules\Coach\Models\Exercise;
  // Après une erreur, seules les valeurs du formulaire d'ajout sont reprises.
  $value = fn (string $field, $default = null) => $exercise ? $exercise->{$field} ?? $default : old($field, $default);
  $muscles = (array) $value('muscles', []);
@endphp
<div class="row">
  <label class="field"><span>Nom</span><input name="name" required maxlength="100" placeholder="Clamshell" value="{{ $value('name') }}"></label>
  <label class="field"><span>Machine</span>
    <select name="equipment_id">
      <option value="">Poids du corps</option>
      @foreach ($equipment as $item)
        <option value="{{ $item->id }}" @selected((int) $value('equipment_id') === $item->id)>{{ $item->name }}</option>
      @endforeach
    </select>
  </label>
  <label class="field"><span>On note</span>
    <select name="measure">
      @foreach (Exercise::MEASURES as $key => $label)<option value="{{ $key }}" @selected($value('measure', 'load_reps') === $key)>{{ $label }}</option>@endforeach
    </select>
  </label>
</div>
<fieldset>
  <legend>Muscles travaillés</legend>
  <div class="checks">
    @foreach (Exercise::MUSCLES as $key => $label)
      <label class="check"><input type="checkbox" name="muscles[]" value="{{ $key }}" @checked(in_array($key, $muscles, true))> {{ $label }}</label>
    @endforeach
  </div>
</fieldset>
<label class="check"><input type="checkbox" name="unilateral" value="1" @checked($value('unilateral'))> Se fait de chaque côté</label>
<label class="field"><span>Consignes, réglages (facultatif)</span><textarea name="notes" maxlength="1000">{{ $value('notes') }}</textarea></label>
