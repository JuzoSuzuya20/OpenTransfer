<!doctype html>
{{-- The language of the instance; the app then follows the one chosen by the reader (app.js) --}}
<html lang="{{ preg_match('/^[a-z]{2}$/', (string) ($settings['default_language'] ?? '')) ? $settings['default_language'] : 'en' }}">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" href="/api/favicon" type="image/svg+xml">
  <title>{{ $settings['application_name'] }}</title>
  {{-- The light or dark choice of this browser, before anything shows (lib/theme) --}}
  <script src="/theme.js"></script>
  @vite('resources/js/app.js')
  @php($accentCss = \App\Support\Accents::css(\App\Support\Accents::chosen()))
  @if ($accentCss !== '')
  {{-- The main colour of the instance (Appearance), after the styles it overrides --}}
  <style id="ot-accent">{!! $accentCss !!}</style>
  @endif
</head>


<body data-settings='{!! htmlspecialchars(json_encode($settings)) !!}' data-version="{{ config('app.version') }}" data-error="{{ session()->get('error') }}" data-success="{{ session()->get('success') }}">
  
  <div id="app"></div>
</body>

</html>