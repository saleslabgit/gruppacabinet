@extends('layouts.psychologist')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Мои группы' , 'url' => ($realApplications ?? false) ? route('psychologist.home') : $links['groups']],['label' => $group['title'] , 'url' => $links['group']],['label' => 'Заявки']]" />
@endsection
@section('content')
@include('shared.application-list')
@endsection
