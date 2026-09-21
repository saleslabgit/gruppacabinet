@extends('layouts.psychologist')
@section('breadcrumbs')
<x-breadcrumbs :items="array_merge([['label' => 'Мои группы' , 'url' => $links['groups']]], (($creating ?? false) || $variant === 'create') ? [] : [['label' => $group['title'] ?: 'Новая группа' , 'url' => ($realGroups ?? false) ? route('psychologist.groups.show', $group['id']) : $links['group']]], [['label' => (($creating ?? false) || $variant === 'create') ? 'Создание' : 'Редактирование']])" />
@endsection
@section('content')
@include('shared.group-form')
@endsection
