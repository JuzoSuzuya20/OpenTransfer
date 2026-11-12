{{-- The error pages (404, 403, 419, 500, 503): the look of the application, without the
     application. They must show even when the database does not answer. Texts: lang/{locale}/errors.php --}}
@php
  // Given by the composer in AppServiceProvider (which also set the language before the page's
  // own texts were read); the defaults stand when it could not reach the database
  $name = $errorSettings['application_name'] ?? 'OpenTransfer';
  $locale = $errorLocale ?? 'en';
  $defaultLogo = $errorDefaultLogo ?? true;
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="dark light" />
  <meta name="robots" content="noindex" />
  <title>@yield('title') – {{ $name }}</title>
  @vite('resources/js/status.js')
</head>
<body class="status-page">
  <header class="status-brand">
    <a href="/" class="status-home" aria-label="{{ __('errors.home') }}">
      @if ($defaultLogo)
        <span class="status-mark">
          <svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true">
            <rect width="28" height="28" rx="7" />
            <path d="M8 15l6-6 6 6M14 9v11" />
          </svg>
          <span>{{ $name }}</span>
        </span>
      @else
        <img src="/images/logo.png" alt="{{ $name }}" style="width: {{ (int) ($errorSettings['logo_width'] ?? 120) }}px" />
      @endif
    </a>
  </header>

  <main class="status-main">
    <section class="ot-card status-card error-card" aria-labelledby="status-title">
      <div class="error-code" aria-hidden="true">
        <span class="error-number">@yield('code')</span>
        <span class="error-stamp">@yield('stamp')</span>
      </div>
      <h1 id="status-title">@yield('title')</h1>
      <p>@yield('text')</p>
      <div class="status-actions">@yield('actions')</div>
    </section>
  </main>
</body>
</html>
