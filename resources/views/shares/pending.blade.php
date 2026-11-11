@extends('shares.status')

{{-- Without a password, the page asks again on its own: the download starts once the archive
     exists. With one, a reload would come back without it: the share page asks for it again. --}}
@section('head')
  @unless ($share->password)
    <meta http-equiv="refresh" content="10" />
  @endunless
@endsection

@section('title', __('shares.pending_title', [], $locale))
@section('icon-class', 'is-working')
@section('icon')
  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56" /></svg>
@endsection

@section('text')
  {{ __($share->password ? 'shares.pending_text_protected' : 'shares.pending_text', ['name' => $share->name], $locale) }}
@endsection

@section('actions')
  @if ($share->password)
    <a class="button-link" href="/shares/{{ $share->long_id }}">{{ __('shares.back', [], $locale) }}</a>
  @else
    <button type="button" class="secondary" data-reload>{{ __('shares.retry', [], $locale) }}</button>
  @endif
@endsection
