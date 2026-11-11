@extends('shares.status')

@section('title', __('shares.failed_title', [], $locale))
@section('icon-class', 'is-failed')
@section('icon')
  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" /><path d="M12 9v4" /><path d="M12 17h.01" /></svg>
@endsection

@section('text')
  {{ __('shares.failed_text', ['name' => $share->name], $locale) }}
@endsection

@section('actions')
  <a class="button-link secondary" href="/shares/{{ $share->long_id }}">{{ __('shares.back', [], $locale) }}</a>
@endsection
