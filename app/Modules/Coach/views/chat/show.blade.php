@extends('coach::layout')

@section('title', $conversation->title.' · Dada Coach')
@section('page-class', 'narrow')

@section('content')
<div class="card-head">
  <h1>{{ $conversation->title }}</h1>
  <a class="small" href="{{ route('coach.chat.index') }}">Toutes les conversations</a>
</div>

<div class="chat" id="chat" aria-live="polite">
  @include('coach::chat.items', ['items' => $timeline])
</div>
<p class="typing" id="typing" hidden>Le coach écrit…</p>
<p class="errors" id="chatError" hidden role="alert"></p>

<form class="composer" id="composer">
  <label class="sr-only" for="message">Ton message</label>
  <textarea id="message" name="message" maxlength="4000" placeholder="Écris à ton coach…" required></textarea>
  <button class="btn" id="send">Envoyer</button>
</form>
@endsection

@push('scripts')
<script>
(() => {
  const chat = document.getElementById('chat');
  const form = document.getElementById('composer');
  const input = document.getElementById('message');
  const send = document.getElementById('send');
  const typing = document.getElementById('typing');
  const error = document.getElementById('chatError');
  const url = @json(route('coach.chat.reply', $conversation));
  const token = document.querySelector('meta[name="csrf-token"]').content;

  const scroll = () => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });

  const ask = async (message) => {
    send.disabled = true;
    typing.hidden = false;
    error.hidden = true;
    scroll();
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ message }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data.error || data.message || 'Le coach n\'a pas pu répondre.');
      }
      chat.insertAdjacentHTML('beforeend', data.html);
    } catch (e) {
      error.textContent = e.message;
      error.hidden = false;
    } finally {
      send.disabled = false;
      typing.hidden = true;
      scroll();
    }
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const message = input.value.trim();
    if (!message || send.disabled) return;
    const bubble = document.createElement('div');
    bubble.className = 'bubble user';
    bubble.textContent = message;
    chat.appendChild(bubble);
    input.value = '';
    ask(message);
  });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && window.matchMedia('(pointer: fine)').matches) {
      event.preventDefault();
      form.requestSubmit();
    }
  });

  // Conversation vide (premier échange, débrief, annulation) : le coach parle en premier.
  if (!chat.querySelector('.bubble')) {
    ask(null);
  } else {
    scroll();
  }
})();
</script>
@endpush
