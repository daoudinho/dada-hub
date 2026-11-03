<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>@yield('title', 'Mes Recettes')</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Kalam:wght@700&family=Nunito:wght@400;600;700;800&display=swap">
<style>
@include('recettes::partials.styles')
</style>
@stack('head')
</head>
<body>
@yield('content')

@if (session('status'))
  <div class="toast" role="status">{{ session('status') }}</div>
@endif

@stack('scripts')
</body>
</html>
