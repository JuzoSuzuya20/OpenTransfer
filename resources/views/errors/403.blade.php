@extends('errors.layout')
@section('code', '403')
@section('stamp', __('errors.403.stamp'))
@section('title', __('errors.403.title'))
@section('text', __('errors.403.text'))
@section('actions')
  <a href="/" class="button-link">{{ __('errors.home') }}</a>
@endsection
