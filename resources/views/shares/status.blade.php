{{-- The pages a recipient may meet on a download before the archive of a share is ready: the
     look of the application, without the application. Texts: lang/{locale}/shares.php --}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="dark light" />
  @yield('head')
  <title>@yield('title') – {{ $settings['application_name'] ?? 'OpenTransfer' }}</title>
  @vite('resources/js/status.js')
</head>
<body class="status-page">
  <header class="status-brand">
    @if ($defaultLogo)
      <span class="status-mark">
        <svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true">
          <rect width="28" height="28" rx="7" />
          <path d="M8 15l6-6 6 6M14 9v11" />
        </svg>
        <span>{{ $settings['application_name'] ?? 'OpenTransfer' }}</span>
      </span>
    @else
      <img src="/images/logo.png" alt="{{ $settings['application_name'] ?? 'OpenTransfer' }}" style="width: {{ (int) ($settings['logo_width'] ?? 120) }}px" />
    @endif
  </header>

  <main class="status-main">
    <section class="ot-card status-card" aria-labelledby="status-title">
      <span class="status-icon @yield('icon-class')" aria-hidden="true">@yield('icon')</span>
      <h1 id="status-title">@yield('title')</h1>
      <p>@yield('text')</p>
      <div class="status-actions">@yield('actions')</div>
    </section>
  </main>
</body>
</html>
