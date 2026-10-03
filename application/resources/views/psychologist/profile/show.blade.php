@extends('layouts.psychologist')
@section('content')
@include('shared.profile-data', ['hideConsent' => true])
<h2 class="mb-4">Мои документы</h2>
@include('shared.documents')
@endsection
