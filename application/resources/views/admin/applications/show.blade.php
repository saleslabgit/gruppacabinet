@extends('layouts.admin')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Заявки' , 'url' => $links['admin-applications']],['label' => $application['name']]]" />
@endsection
@section('content')
@include('shared.application-detail')
@endsection
