@extends('layouts.app', ['title' => 'نقش جدید'])

@section('content')
<div class="ensha-page-heading"><div><a class="ensha-back-crumb" href="{{ route('roles.index') }}"><i class="ki-filled ki-arrow-right"></i> نقش‌ها</a><h2>ایجاد نقش سفارشی</h2><p>یک نقش جدید بسازید و دسترسی‌های موردنیاز آن را دقیق انتخاب کنید.</p></div></div>
<form method="POST" action="{{ route('roles.store') }}">@csrf @include('roles.partials.form')</form>
@endsection
