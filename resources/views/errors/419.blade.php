@extends('errors.layout')
@section('code', '419')
@section('stamp', __('errors.419.stamp'))
@section('title', __('errors.419.title'))
@section('text', __('errors.419.text'))
@section('actions')
  <button type="button" class="button-link" data-reload>{{ __('errors.reload') }}</button>
@endsection
