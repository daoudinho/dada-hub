{{-- Erreurs de validation d'un formulaire. Paramètre : $bag (MessageBag, celui par défaut si absent). --}}
@php($bag ??= $errors->getBag('default'))
@if ($bag->any())
  <ul class="errors" role="alert">
    @foreach ($bag->all() as $error)<li>{{ $error }}</li>@endforeach
  </ul>
@endif
