@extends('engines.layout_blade')
@section('content')<h1>{{ $title }}</h1><p>old={{ $old('email', 'none') }}</p>@endsection
