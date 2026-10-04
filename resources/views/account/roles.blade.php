@extends('layouts.app', ['title'=>'انتخاب نقش و مرکز'])
@section('content')
<div class="ensha-page-heading"><div><h2>انتخاب نقش، مرکز و شعبه</h2><p>دسترسی‌ها و داشبورد بر اساس زمینه عملیاتی انتخابی شما نمایش داده می‌شوند.</p></div></div>
<section class="ensha-card" style="padding:24px"><div class="ensha-module-grid">@foreach($assignments as $assignment)
<form method="POST" action="{{ route('account.roles.select',$assignment) }}">@csrf<button class="ensha-secondary-btn" type="submit">{{ $assignment->role->name }} · {{ $assignment->centre?->name ?? 'سراسری' }} @if($assignment->branch)· {{ $assignment->branch->name }}@endif</button></form>
@endforeach</div></section>
@endsection
