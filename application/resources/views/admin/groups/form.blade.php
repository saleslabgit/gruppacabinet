@extends('layouts.admin')
@section('breadcrumbs')
<x-breadcrumbs :items="array_merge([['label' => 'Группы' , 'url' => $links['admin-groups']]], (($creating ?? false) || $variant === 'create') ? [] : [['label' => $group['title'] ?: 'Новая группа' , 'url' => ($realGroups ?? false) ? route('admin.groups.show', $group['id']) : $links['admin-group']]], [['label' => (($creating ?? false) || $variant === 'create') ? 'Создание' : 'Редактирование']])" />
@endsection
@section('content')
@include('shared.group-form')
@endsection
