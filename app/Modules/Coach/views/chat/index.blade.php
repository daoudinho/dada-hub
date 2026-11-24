@extends('coach::layout')

@section('title', 'Coach · Conversations')
@section('page-class', 'narrow')

@section('content')
<div class="card-head">
  <h1>Ton coach</h1>
  <form method="POST" action="{{ route('coach.chat.store') }}">
    @csrf
    <button class="btn">{{ $conversations->contains('kind', 'onboarding') ? 'Nouvelle discussion' : 'Faire connaissance' }}</button>
  </form>
</div>

@if ($conversations->isEmpty())
  <p class="card muted">Aucune conversation pour l'instant. Commence par faire connaissance : ton coach te posera quelques questions et t'aidera à fixer ton objectif.</p>
@else
  <ul class="list conv-list">
    @foreach ($conversations as $conversation)
      <li>
        <a class="what" href="{{ route('coach.chat.show', $conversation) }}">
          <strong>{{ $conversation->title }}</strong><br>
          <span class="muted small">{{ $conversation->kindLabel() }} · {{ $conversation->updated_at->locale('fr')->isoFormat('D MMM, HH:mm') }}</span>
        </a>
        @if ($conversation->isAwaitingFeedback())
          <span class="pill pending">En attente</span>
        @endif
      </li>
    @endforeach
  </ul>
@endif
@endsection
