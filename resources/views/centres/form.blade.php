@extends('layouts.app', ['title'=>$centre->exists?'ویرایش مرکز':'مرکز جدید'])
@section('content')
<div class="ensha-page-heading"><h2>{{ $centre->exists?'ویرایش مرکز':'افزودن مرکز' }}</h2></div>
<section class="ensha-card" style="padding:24px"><form method="POST" action="{{ $centre->exists?route('centres.update',$centre):route('centres.store') }}">@csrf @if($centre->exists) @method('PUT') @endif
@if($errors->any())<div class="ensha-status danger">{{ $errors->first() }}</div>@endif
<div class="ensha-admin-grid"><label>نام مرکز <input class="kt-input" required maxlength="160" name="name" value="{{ old('name',$centre->name) }}"></label><label>کد یکتا <input class="kt-input" required maxlength="50" dir="ltr" name="code" value="{{ old('code',$centre->code) }}" @unless(auth()->user()->isSuperAdmin()) readonly @endunless></label><label>تلفن <input class="kt-input" maxlength="20" name="phone" value="{{ old('phone',$centre->phone) }}"></label><label>ایمیل <input class="kt-input" type="email" maxlength="190" name="email" value="{{ old('email',$centre->email) }}"></label><label>نشانی <input class="kt-input" maxlength="500" name="address" value="{{ old('address',$centre->address) }}"></label><label>توضیح <textarea class="kt-input" name="description" maxlength="3000">{{ old('description',$centre->description) }}</textarea></label></div>
@if(auth()->user()->isSuperAdmin())<label><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$centre->exists?$centre->is_active:true))> مرکز فعال باشد</label>@endif
<div style="margin-top:24px"><button class="ensha-primary-btn" type="submit">ذخیره مرکز</button> <a class="ensha-secondary-btn" href="{{ route('centres.index') }}">بازگشت</a></div></form></section>
@endsection
