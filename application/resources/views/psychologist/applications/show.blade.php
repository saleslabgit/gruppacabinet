@extends('layouts.psychologist')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Мои группы' , 'url' => ($realApplications ?? false) ? route('psychologist.home') : $links['groups']],['label' => $application['group_title'] ?? $group['title'] , 'url' => $application['group_url'] ?? $links['group']],['label' => 'Заявки' , 'url' => $links['applications']],['label' => $application['name']]]" />
@endsection
@section('content')
@include('shared.application-detail')
@endsection
