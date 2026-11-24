<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Dada Coach')</title>
<meta name="theme-color" content="#0a0b0d">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
<style>
@include('coach::partials.styles')
</style>
@stack('head')
</head>
<body>
<div class="page @yield('page-class')">
  @include('coach::partials.topbar')
  @yield('content')
</div>

@if (session('status'))
  <div class="toast" role="status">{{ session('status') }}</div>
@endif
@if (session('garmin_sync'))
  @include('coach::partials.sync-status', ['since' => session('garmin_sync')])
@endif

@stack('scripts')
</body>
</html>
