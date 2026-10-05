@extends('layouts.app',['title'=>'تکمیل پرونده مراجع'])
@push('head')<link rel="stylesheet" href="{{ asset('css/stage06.css') }}">@endpush
@section('content')<div class="ensha-page-heading"><h2>تکمیل پرونده مراجع: {{ $client->user->display_name }}</h2></div>
<form class="ensha-card stage06-card" method="post" action="{{ route('stage06.clients.update',$client) }}">@csrf @method('PUT')
<label>نام<input name="first_name" value="{{ old('first_name',$client->user->first_name) }}"></label><label>نام خانوادگی<input name="last_name" value="{{ old('last_name',$client->user->last_name) }}"></label>
@foreach($fields as $field)
@php($type=$field->field_type)
@php($raw=$client->user->profileValues->firstWhere('profile_field_id',$field->id)?->value)
@php($saved=in_array($type,['multi_select','checkbox_group'],true)?(json_decode($raw??'[]',true)?:[]):($raw??''))
@php($value=old('profile.'.$field->id,$saved))
@if($type==='divider')<hr>@elseif($type==='heading')<h3>{{ $field->label }}</h3>@elseif($type==='paragraph')<p>{{ $field->settings['help']??$field->label }}</p>
@else<div class="stage06-card"><label>{{ $field->label }} @if($field->is_required)*@endif
@if($type==='textarea')<textarea name="profile[{{ $field->id }}]" @if($field->is_required) required @endif>{{ $value }}</textarea>
@elseif(in_array($type,['select','multi_select'],true))<select name="profile[{{ $field->id }}]{{ $type==='multi_select'?'[]':'' }}" @if($type==='multi_select') multiple @endif @if($field->is_required) required @endif>@if($type==='select')<option value="">انتخاب کنید</option>@endif @foreach(\App\Support\ProfileOptions::normalized($field->options) as $option)<option value="{{ $option['value'] }}" @selected($type==='multi_select'?in_array($option['value'],(array)$value,true):$value===$option['value'])>{{ $option['label'] }}</option>@endforeach</select>
@elseif(in_array($type,['radio','checkbox_group'],true))@foreach(\App\Support\ProfileOptions::normalized($field->options) as $option)<label><input type="{{ $type==='radio'?'radio':'checkbox' }}" name="profile[{{ $field->id }}]{{ $type==='radio'?'':'[]' }}" value="{{ $option['value'] }}" @checked($type==='radio'?$value===$option['value']:in_array($option['value'],(array)$value,true))> {{ $option['label'] }}</label>@endforeach
@elseif($type==='boolean')<input type="hidden" name="profile[{{ $field->id }}]" value="0"><input type="checkbox" name="profile[{{ $field->id }}]" value="1" @checked((string)$value==='1')>
@else<input type="{{ in_array($type,['email','tel','url','number','date','time'])?$type:($type==='datetime'?'datetime-local':'text') }}" name="profile[{{ $field->id }}]" value="{{ $value }}" @if($field->is_required) required @endif>@endif
</label>@error('profile.'.$field->id)<small>{{ $message }}</small>@enderror</div>@endif
@endforeach
<button class="ensha-primary-btn">ثبت پرونده</button></form>@endsection
