@php
    $editing = isset($role);
    $selectedPermissions = collect(old('permission_ids', $editing ? $role->permissions->pluck('id')->all() : []))->map(fn($id) => (int) $id)->all();
    $lockedAdmin = $editing && $role->slug === 'super_admin';
@endphp
<div class="ensha-role-form-layout">
    <div class="ensha-role-main">
        <section class="ensha-card">
            <div class="ensha-card-head"><div><span class="ensha-eyebrow">تعریف نقش</span><h3>مشخصات و دامنه</h3></div>@if($editing && $role->is_system)<span class="ensha-badge-soft"><i class="ki-filled ki-lock"></i> سیستمی</span>@endif</div>
            <div class="ensha-form-grid">
                <label>عنوان نقش <span class="required">*</span><input name="name" value="{{ old('name', $role->name ?? '') }}" maxlength="120" required></label>
                <label>شناسه فنی <span class="required">*</span><input name="slug" value="{{ old('slug', $role->slug ?? '') }}" dir="ltr" placeholder="example_role" pattern="[a-z][a-z0-9_]*" @readonly($editing) required><span class="field-hint">{{ $editing ? 'شناسه فنی پس از ایجاد تغییر نمی‌کند.' : 'فقط حروف کوچک انگلیسی، عدد و زیرخط' }}</span></label>
                <label>دامنه نقش <span class="required">*</span>
                    @if($editing && ($role->is_system || $role->users()->exists()))<input type="hidden" name="scope" value="{{ $role->scope }}">@endif
                    <select name="scope" @disabled($editing && ($role->is_system || $role->users()->exists())) required><option value="centre" @selected(old('scope', $role->scope ?? 'centre') === 'centre')>مرکز‌محور</option><option value="global" @selected(old('scope', $role->scope ?? '') === 'global')>سراسری</option></select>
                </label>
                <label>رنگ نمایشی <select name="color" required>@foreach(['primary'=>'آبی','success'=>'سبز','warning'=>'کهربایی','danger'=>'قرمز','info'=>'فیروزه‌ای','slate'=>'خاکستری','violet'=>'بنفش'] as $key=>$label)<option value="{{ $key }}" @selected(old('color', $role->color ?? 'primary') === $key)>{{ $label }}</option>@endforeach</select></label>
                <label class="full-span">توضیحات<textarea name="description" rows="3" maxlength="500" placeholder="کاربرد و محدودیت‌های این نقش را توضیح دهید...">{{ old('description', $role->description ?? '') }}</textarea></label>
                <label class="ensha-switch-row full-span"><span><strong>نقش فعال باشد</strong><small>نقش غیرفعال برای تخصیص به کاربران قابل انتخاب نیست.</small></span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $role->is_active ?? true)) @disabled($lockedAdmin)></label>
            </div>
        </section>

        <section class="ensha-card">
            <div class="ensha-card-head"><div><span class="ensha-eyebrow">ماتریس مجوزها</span><h3>دسترسی‌های این نقش</h3></div><div class="ensha-matrix-actions"><button type="button" data-select-all-permissions>انتخاب همه</button><button type="button" data-clear-all-permissions>پاک‌کردن همه</button></div></div>
            @if($lockedAdmin)<div class="ensha-info-strip"><i class="ki-filled ki-shield-tick"></i><div><strong>دسترسی ذاتی ادمین سیستم</strong><p>برای جلوگیری از قفل‌شدن سامانه، همه مجوزهای فعال همیشه به این نقش تعلق دارند.</p></div></div>@endif
            <div class="ensha-permission-matrix">
                @foreach($permissionGroups as $groupKey => $permissions)
                    <div class="ensha-permission-group" data-permission-group>
                        <div class="ensha-permission-group-head"><div><span class="ensha-role-icon mini"><i class="ki-filled ki-shield-tick"></i></span><div><strong>{{ $permissions->first()->group_name }}</strong><small>{{ $permissions->count() }} مجوز</small></div></div><label><input type="checkbox" data-group-toggle> انتخاب گروه</label></div>
                        <div class="ensha-permission-list">
                            @foreach($permissions as $permission)
                                <label class="ensha-permission-option"><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked($lockedAdmin || in_array($permission->id, $selectedPermissions, true)) @disabled($lockedAdmin)><span><strong>{{ $permission->name }}</strong><small>{{ $permission->description }}</small><code>{{ $permission->slug }}</code></span></label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
    <aside class="ensha-role-aside">
        <section class="ensha-card sticky-card"><div class="ensha-help-icon"><i class="ki-filled ki-shield-tick"></i></div><h3>{{ $editing ? 'ذخیره تنظیمات نقش' : 'ایجاد نقش جدید' }}</h3><p>مجوزها را بر اساس حداقل دسترسی لازم انتخاب کنید. هر تغییر در گزارش امنیتی ثبت می‌شود.</p><button class="ensha-primary-btn full" type="submit"><i class="ki-filled ki-check"></i> {{ $editing ? 'ذخیره تغییرات' : 'ایجاد نقش' }}</button><a class="ensha-secondary-btn full" href="{{ route('roles.index') }}">انصراف</a></section>
        @if($editing && !$role->is_system)
            @can('delete', $role)<section class="ensha-danger-zone vertical"><strong>حذف نقش سفارشی</strong><p>فقط نقش بدون کاربر قابل حذف است.</p><button class="ensha-danger-btn full" type="submit" form="delete-role-form"><i class="ki-filled ki-trash"></i> حذف نقش</button></section>@endcan
        @endif
    </aside>
</div>
