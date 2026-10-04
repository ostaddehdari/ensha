@extends('layouts.app', ['title' => 'ویرایش نقش'])

@section('content')
<div class="ensha-page-heading"><div><a class="ensha-back-crumb" href="{{ route('roles.index') }}"><i class="ki-filled ki-arrow-right"></i> نقش‌ها</a><h2>{{ $role->name }}</h2><p>ماتریس مجوزها و مشخصات نقش را مدیریت کنید.</p></div><span class="ensha-role-chip role-{{ $role->slug }}">{{ $role->is_system ? 'نقش سیستمی' : 'نقش سفارشی' }}</span></div>
<form method="POST" action="{{ route('roles.update', $role) }}">@csrf @method('PUT') @include('roles.partials.form')</form>
@if(!$role->is_system)<form id="delete-role-form" method="POST" action="{{ route('roles.destroy', $role) }}" data-confirm="نقش {{ $role->name }} حذف شود؟">@csrf @method('DELETE')</form>@endif
@endsection
