{{-- Étiquettes à cocher. Paramètres : $tags, $checked (identifiants cochés). --}}
@php($checked = collect($checked)->map(fn ($id) => (int) $id))
<div class="check-chips">
  @foreach ($tags as $tag)
    <label><input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}" @checked($checked->contains($tag->id))><span>{{ $tag->label() }}</span></label>
  @endforeach
</div>
