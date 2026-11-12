@extends('errors.layout')
@section('code', '500')
@section('stamp', __('errors.500.stamp'))
@section('title', __('errors.500.title'))
@section('text', __('errors.500.text'))
@section('actions')
  <button type="button" class="button-link" data-reload>{{ __('errors.retry') }}</button>
  <a href="/" class="button-secondary">{{ __('errors.home') }}</a>
@endsection
