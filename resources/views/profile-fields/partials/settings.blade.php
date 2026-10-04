<input type="hidden" name="role" value="{{ $role }}">
<label>برچسب فیلد<input class="kt-input" name="label" required maxlength="150" value="{{ old('label',$field->label) }}"></label>
<label>کلید فنی<input class="kt-input" name="key" required maxlength="100" pattern="[a-z][a-z0-9_]*" dir="ltr" value="{{ old('key',$field->key) }}"><small>پس از ثبت پاسخ، کلید ثابت می‌ماند.</small></label>
<label>نوع فیلد<select class="kt-input" name="field_type" id="builder-field-type">@foreach($types as $type=>$title)<option value="{{ $type }}" @selected(old('field_type',$field->field_type)===$type)>{{ $title }}</option>@endforeach</select></label>
<label class="builder-options-wrap">گزینه‌ها (هر گزینه در یک سطر)<textarea class="kt-input" name="options" rows="5" placeholder="گزینهٔ اول&#10;گزینهٔ دوم">{{ old('options',implode("\n",$field->options??[])) }}</textarea></label>
<label>متن راهنما<textarea class="kt-input" name="settings[help]" rows="2" maxlength="500">{{ old('settings.help',$field->settings['help']??'') }}</textarea></label>
<label>متن داخل فیلد<input class="kt-input" name="settings[placeholder]" maxlength="200" value="{{ old('settings.placeholder',$field->settings['placeholder']??'') }}"></label>
<label>مقدار اولیه<input class="kt-input" name="settings[default]" maxlength="500" value="{{ old('settings.default',$field->settings['default']??'') }}"></label>
<label>عرض فیلد<select class="kt-input" name="settings[width]"><option value="full" @selected(old('settings.width',$field->settings['width']??'full')==='full')>تمام‌عرض</option><option value="half" @selected(old('settings.width',$field->settings['width']??'full')==='half')>نیم‌عرض</option></select></label>
<label class="ensha-builder-check"><input type="checkbox" name="is_required" value="1" @checked(old('is_required',$field->is_required))> پاسخ الزامی باشد</label>
<label class="ensha-builder-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$field->is_active))> فیلد فعال باشد</label>
