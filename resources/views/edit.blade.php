@extends('statamic::layout')

@section('title', __('statamic-ai-translations::cp.title'))

@section('content')
    <publish-form
        title='{{ __('statamic-ai-translations::cp.title') }}'
        action={{ cp_route('statamic-ai-translations.config') }}
        :blueprint='@json($blueprint)'
        :meta='@json($meta)'
        :values='@json($values)'
    ></publish-form>
@stop
