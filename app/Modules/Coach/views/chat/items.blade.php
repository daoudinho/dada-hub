{{-- Éléments du fil : messages affichables et propositions du coach. Paramètre : $items. --}}
@foreach ($items as $item)
  @if ($item instanceof \App\Modules\Coach\Models\Proposal)
    @include('coach::chat.proposal', ['proposal' => $item])
  @elseif ($item->role === 'user')
    <div class="bubble user">{{ $item->display_text }}</div>
  @else
    <div class="bubble assistant">{!! \Illuminate\Support\Str::markdown($item->display_text, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
  @endif
@endforeach
