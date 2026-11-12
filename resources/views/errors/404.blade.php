@extends('errors.layout')
@section('code', '404')
@section('stamp', __('errors.404.stamp'))
@section('title', __('errors.404.title'))
@section('text', __('errors.404.text'))
@section('actions')
  <a href="/" class="button-link">{{ __('errors.home') }}</a>
@endsection
