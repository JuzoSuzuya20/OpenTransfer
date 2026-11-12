@extends('errors.layout')
@section('code', '503')
@section('stamp', __('errors.503.stamp'))
@section('title', __('errors.503.title'))
@section('text', __('errors.503.text'))
@section('actions')
  <button type="button" class="button-link" data-reload>{{ __('errors.retry') }}</button>
  <a href="/" class="button-secondary">{{ __('errors.home') }}</a>
@endsection
