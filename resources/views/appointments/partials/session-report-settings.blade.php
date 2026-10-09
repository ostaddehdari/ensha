<section class="appointment-pane" data-pane="report-templates" hidden>
    <div class="settings-card-head">
        <div><h3>فرم گزارش جلسه مشاور</h3><p>برای هر موضوع فرم جدا بسازید؛ فرم عمومی زمانی استفاده می‌شود که موضوع فرم اختصاصی ندارد.</p></div>
        <span class="count-badge">{{ $reportTemplates->count() }} فرم</span>
    </div>

    <form method="post" action="{{ route('centres.appointments-settings.report-templates.store',$centre) }}" class="report-template-form" data-report-template-form>
        @csrf
        <div class="settings-form-grid">
            <label>نام فرم<input class="kt-input" name="name" required placeholder="مثلاً گزارش مشاوره خانواده"></label>
            <label>موضوع<select class="kt-select" name="topic_id"><option value="">فرم عمومی همه موضوعات</option>@foreach($topics as $topic)<option value="{{ $topic->id }}">{{ $topic->name }}</option>@endforeach</select></label>
            <label class="check-line"><input type="checkbox" name="is_default" value="1"> فرم پیش‌فرض این موضوع</label>
        </div>
        <div class="report-field-builder">
            <div class="report-field-builder-head"><div><strong>فیلدهای فرم</strong><small>نوع «تیک» برای گزارش سریع و نوع «متن بلند» برای توضیح مشاور است.</small></div><button class="kt-btn kt-btn-outline" type="button" data-add-report-field><i class="ki-filled ki-plus"></i> افزودن فیلد</button></div>
            <div data-report-fields>
                <div class="report-field-row" data-report-field>
                    <input class="kt-input" name="fields[0][label]" placeholder="عنوان فیلد" required>
                    <select class="kt-select" name="fields[0][type]" data-report-field-type><option value="checkbox">تیک‌زدنی</option><option value="text">متن کوتاه</option><option value="textarea">متن بلند</option><option value="select">فهرست انتخابی</option><option value="number">عدد</option></select>
                    <input class="kt-input" name="fields[0][options]" placeholder="گزینه‌ها با | جدا شوند" data-report-options disabled>
                    <label class="check-line compact"><input type="checkbox" name="fields[0][required]" value="1"> الزامی</label>
                    <button class="kt-btn kt-btn-icon kt-btn-outline" type="button" data-remove-report-field aria-label="حذف"><i class="ki-filled ki-trash"></i></button>
                </div>
            </div>
        </div>
        <div class="settings-actions"><button class="kt-btn kt-btn-primary" type="submit"><i class="ki-filled ki-check"></i> ثبت نسخه جدید فرم</button></div>
    </form>

    <div class="ensha-table-wrap settings-table mt-5"><table class="ensha-table"><thead><tr><th>نام فرم</th><th>موضوع</th><th>نسخه</th><th>فیلدها</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
    @forelse($reportTemplates as $template)
        <tr><td><strong>{{ $template->name }}</strong>@if($template->is_default)<small class="block">پیش‌فرض</small>@endif</td><td>{{ $template->topic?->name ?: 'همه موضوعات' }}</td><td>{{ $template->version }}</td><td>{{ count($template->fields ?: []) }}</td><td><span class="ensha-status {{ $template->is_active?'success':'warning' }}">{{ $template->is_active?'فعال':'غیرفعال' }}</span></td><td><form method="post" action="{{ route('centres.appointments-settings.report-templates.toggle',[$centre,$template]) }}">@csrf @method('PATCH')<button class="kt-btn kt-btn-sm kt-btn-outline">{{ $template->is_active?'غیرفعال‌کردن':'فعال‌کردن' }}</button></form></td></tr>
    @empty<tr><td colspan="6" class="ensha-table-empty">فرم گزارش جلسه‌ای ثبت نشده است.</td></tr>@endforelse
    </tbody></table></div>

    <template data-report-field-template><div class="report-field-row" data-report-field><input class="kt-input" data-name="label" placeholder="عنوان فیلد" required><select class="kt-select" data-name="type" data-report-field-type><option value="checkbox">تیک‌زدنی</option><option value="text">متن کوتاه</option><option value="textarea">متن بلند</option><option value="select">فهرست انتخابی</option><option value="number">عدد</option></select><input class="kt-input" data-name="options" placeholder="گزینه‌ها با | جدا شوند" data-report-options disabled><label class="check-line compact"><input type="checkbox" data-name="required" value="1"> الزامی</label><button class="kt-btn kt-btn-icon kt-btn-outline" type="button" data-remove-report-field aria-label="حذف"><i class="ki-filled ki-trash"></i></button></div></template>
</section>
