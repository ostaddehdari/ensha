@php($editing = isset($user))
<div class="ensha-form-grid">
    <label>نام <span class="required">*</span><input name="first_name" value="{{ old('first_name', $user->first_name ?? '') }}" maxlength="100" required autocomplete="off"></label>
    <label>نام خانوادگی <span class="required">*</span><input name="last_name" value="{{ old('last_name', $user->last_name ?? '') }}" maxlength="100" required autocomplete="off"></label>
    <label>شماره تلفن <span class="required">*</span><input type="tel" name="phone" value="{{ old('phone', $user->phone ?? '') }}" dir="ltr" inputmode="tel" placeholder="09123456789" maxlength="14" required></label>
    <label>کد ملی <span class="required">*</span><input name="national_id" value="{{ old('national_id', $user->national_id ?? '') }}" dir="ltr" inputmode="numeric" maxlength="10" placeholder="۱۰ رقم" required></label>
    <label>نقش کاربری <span class="required">*</span>
        <select name="role_id" data-role-select required>
            <option value="">انتخاب نقش</option>
            @foreach($roles as $role)<option value="{{ $role->id }}" data-scope="{{ $role->scope }}" @selected((string) old('role_id', $user->role_id ?? (auth()->user()->isSuperAdmin() ? optional($roles->firstWhere('slug', request('role')))->id : null)) === (string) $role->id)>{{ $role->name }} · {{ $role->scope === 'global' ? 'سراسری' : 'مرکز‌محور' }}</option>@endforeach
        </select>
    </label>
    <label data-centre-field>مرکز مشاوره <span class="required">*</span>
        <select name="centre_id">
            <option value="">انتخاب مرکز</option>
            @foreach($centres as $centre)<option value="{{ $centre->id }}" @selected((string) old('centre_id', $user->centre_id ?? (auth()->user()->isSuperAdmin() ? request('centre_id') : auth()->user()->centre_id)) === (string) $centre->id)>{{ $centre->name }}</option>@endforeach
        </select>
        <span class="field-hint">نقش‌های سراسری به مرکز وابسته نیستند.</span>
    </label>
    @unless($editing)
        <label>رمز عبور اولیه <span class="required">*</span>
            <span class="ensha-input-action"><input type="password" name="password" data-password-input minlength="10" required autocomplete="new-password"><button type="button" data-generate-password>ساخت رمز قوی</button></span>
            <span class="field-hint">حداقل ۱۰ کاراکتر، شامل حروف بزرگ و کوچک، عدد و نماد</span>
        </label>
        <label>تکرار رمز عبور <span class="required">*</span><input type="password" name="password_confirmation" minlength="10" required autocomplete="new-password"></label>
        <label class="ensha-switch-row full-span"><span><strong>حساب فعال باشد</strong><small>کاربر بلافاصله امکان ورود خواهد داشت.</small></span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))></label>
    @endunless
</div>

@if(!empty($profileFieldGroups) && $profileFieldGroups->isNotEmpty())
    @include('users.partials.profile-fields')
@endif
