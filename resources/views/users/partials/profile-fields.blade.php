@php($savedValues = isset($user) ? $user->profileValues->pluck('value','profile_field_id') : collect())
@foreach($profileFieldGroups as $profileRole => $dynamicFields)
    <div class="ensha-dynamic-role" data-profile-role="{{ $profileRole }}" data-role-id="{{ $roles->firstWhere('slug',$profileRole)?->id }}" @if($profileRole!=='all' && (string)old('role_id',$user->role_id??'')!==(string)($roles->firstWhere('slug',$profileRole)?->id)) hidden @endif>
        <div class="ensha-card-head"><div><span class="ensha-eyebrow">فرم‌ساز پروفایل</span><h3>{{ $profileRole==='all'?'اطلاعات تکمیلی مشترک':($roles->firstWhere('slug',$profileRole)?->name??$profileRole) }}</h3></div></div>
        <div class="ensha-form-grid">
        @foreach($dynamicFields as $field)
            @php($type=$field->field_type)
            @php($name='profile['.$field->id.']')
            @php($raw=$savedValues->get($field->id))
            @php($decoded=in_array($type,['checkbox_group','multi_select'],true) ? (json_decode($raw??'[]',true)?:[]) : [])
            @php($val=old('profile.'.$field->id,in_array($type,['checkbox_group','multi_select'],true)?$decoded:($raw??($field->settings['default']??''))))
            @if($type==='divider')<div class="full-span"><hr></div>
            @elseif($type==='heading')<h4 class="full-span">{{ $field->label }}</h4>
            @elseif($type==='paragraph')<p class="full-span">{{ $field->settings['help']??$field->label }}</p>
            @else
            <div class="{{ ($field->settings['width']??'full')==='full'?'full-span':'' }} ensha-dynamic-field">
                <label>{{ $field->label }} @if($field->is_required)<span class="required">*</span>@endif
                    @if($type==='textarea')<textarea name="{{ $name }}" placeholder="{{ $field->settings['placeholder']??'' }}" @if($field->is_required) required @endif>{{ $val }}</textarea>
                    @elseif(in_array($type,['select','multi_select'],true))<select name="{{ $name }}{{ $type==='multi_select'?'[]':'' }}" @if($type==='multi_select') multiple @endif @if($field->is_required) required @endif>@if($type==='select')<option value="">انتخاب کنید</option>@endif @foreach(\App\Support\ProfileOptions::normalized($field->options) as $option)<option value="{{ $option['value'] }}" @selected($type==='multi_select'?in_array($option['value'],(array)$val,true):$val===$option['value'])>{{ $option['label'] }}</option>@endforeach</select>
                    @elseif(in_array($type,['radio','checkbox_group'],true))<span class="ensha-dynamic-options">@foreach(\App\Support\ProfileOptions::normalized($field->options) as $option)<label><input type="{{ $type==='radio'?'radio':'checkbox' }}" name="{{ $name }}{{ $type==='radio'?'':'[]' }}" value="{{ $option['value'] }}" @checked($type==='radio'?$val===$option['value']:in_array($option['value'],(array)$val,true))> {{ $option['label'] }}</label>@endforeach</span>
                    @elseif($type==='boolean')<input type="hidden" name="{{ $name }}" value="0"><input type="checkbox" name="{{ $name }}" value="1" @checked((string)$val==='1')>
                    @else<input type="{{ in_array($type,['email','tel','url','number','date','time'])?$type:($type==='datetime'?'datetime-local':'text') }}" name="{{ $name }}" value="{{ $val }}" placeholder="{{ $field->settings['placeholder']??'' }}" @if($field->is_required) required @endif>@endif
                </label>
                @if(!empty($field->settings['help']))<small class="field-hint">{{ $field->settings['help'] }}</small>@endif
                @error('profile.'.$field->id)<small class="ensha-builder-error">{{ $message }}</small>@enderror
            </div>
            @endif
        @endforeach
        </div>
    </div>
@endforeach
