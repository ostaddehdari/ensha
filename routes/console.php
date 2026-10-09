<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


Artisan::command('ensha:about', function () {
    $this->info('Ensha counselling centre foundation');
})->purpose('Show Ensha application information');

Artisan::command('ensha:verify-stage00', function () {
    $errors = [];

    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.10.4') {
        $errors[] = 'VERSION باید 0.10.4 باشد.';
    }
    if (! Schema::hasTable('official_holidays')) {
        $errors[] = 'جدول official_holidays ساخته نشده است.';
    } elseif (DB::table('official_holidays')->where('jalali_year', 1405)->where('is_active', true)->count() !== 26) {
        $errors[] = 'داده‌های تعطیلات رسمی ۱۴۰۵ کامل نیست.';
    }

    $secretaryPermissions = DB::table('permission_role as pr')
        ->join('roles as r', 'r.id', '=', 'pr.role_id')
        ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
        ->where('r.slug', 'secretary')
        ->whereIn('p.slug', ['counselors.view', 'schedules.view', 'fees.view'])
        ->distinct()
        ->count('p.slug');
    if ($secretaryPermissions !== 3) {
        $errors[] = 'مجوزهای فقط‌خواندنی منشی کامل نیست.';
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            $this->error($error);
        }

        return 1;
    }

    $this->info('VERIFY_STAGE00_OK');

    return 0;
})->purpose('Verify Ensha Stage 00 / v0.10.4 after deployment');

Artisan::command('ensha:verify-stage01', function () {
    $errors = [];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.11.0') {
        $errors[] = 'VERSION باید 0.11.0 باشد.';
    }
    foreach (['centre_branches', 'staff_profiles', 'user_role_centres', 'permissions'] as $table) {
        if (! Schema::hasTable($table)) {
            $errors[] = "جدول {$table} وجود ندارد.";
        }
    }
    foreach ([
        'centres' => ['timezone', 'settings'],
        'user_role_centres' => ['branch_id'],
        'staff_profiles' => ['employee_code', 'department', 'work_email', 'extension'],
    ] as $table => $columns) {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $errors[] = "ستون {$table}.{$column} وجود ندارد.";
            }
        }
    }
    if (Schema::hasTable('centre_branches') && Schema::hasTable('centres') && Schema::hasTable('user_role_centres')) {
        $invalidDefaultCentres = DB::table('centres as c')
            ->leftJoin('centre_branches as b', function ($join) {
                $join->on('b.centre_id', '=', 'c.id')->where('b.is_default', true);
            })
            ->select('c.id')->groupBy('c.id')->havingRaw('COUNT(b.id) <> 1')->get()->count();
        if ($invalidDefaultCentres !== 0) {
            $errors[] = 'همه مراکز دقیقاً به یک شعبه پیش‌فرض نیاز دارند.';
        }
        if (DB::table('centre_branches')->where('is_default', true)->where('is_active', false)->exists()) {
            $errors[] = 'شعبه پیش‌فرض غیرفعال مجاز نیست.';
        }
        $crossCentreAssignments = DB::table('user_role_centres as urc')
            ->join('centre_branches as b', 'b.id', '=', 'urc.branch_id')
            ->where(function ($query) {
                $query->whereNull('urc.centre_id')->orWhereColumn('urc.centre_id', '!=', 'b.centre_id');
            })->count();
        if ($crossCentreAssignments !== 0) {
            $errors[] = 'انتساب نقش با شعبه خارج از مرکز مشاهده شد.';
        }
    }
    $requiredPermissions = ['branches.view', 'branches.manage', 'centre_settings.view', 'centre_settings.manage', 'staff.view', 'staff.manage'];
    if (Schema::hasTable('permissions') && DB::table('permissions')->where('is_active', true)->whereIn('slug', $requiredPermissions)->count() !== count($requiredPermissions)) {
        $errors[] = 'مجوزهای Stage 01 کامل نیستند.';
    }
    if (Schema::hasTable('permission_role') && Schema::hasTable('roles') && Schema::hasTable('permissions')) {
        $managerPermissionCount = DB::table('permission_role as pr')
            ->join('roles as r', 'r.id', '=', 'pr.role_id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('r.slug', 'manager')->where('r.is_active', true)->where('p.is_active', true)
            ->whereIn('p.slug', $requiredPermissions)->distinct()->count('p.slug');
        if ($managerPermissionCount !== count($requiredPermissions)) {
            $errors[] = 'ماتریس دسترسی مدیر مرکز برای Stage 01 کامل نیست.';
        }
    }
    if ($errors !== []) {
        foreach ($errors as $error) {
            $this->error($error);
        }
        return 1;
    }
    $this->info('VERIFY_STAGE01_OK');
    return 0;
})->purpose('Verify Ensha Stage 01 / v0.11.0 after deployment');

Artisan::command('ensha:verify-stage02', function () {
    $errors = [];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.12.0') {
        $errors[] = 'VERSION باید 0.12.0 باشد.';
    }
    foreach (['clients', 'external_identities'] as $table) {
        if (! Schema::hasTable($table)) {
            $errors[] = "جدول {$table} وجود ندارد.";
        }
    }
    foreach (['clients' => ['user_id', 'centre_id', 'client_code', 'status'], 'external_identities' => ['client_id', 'provider', 'external_id']] as $table => $columns) {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $errors[] = "ستون {$table}.{$column} وجود ندارد.";
            }
        }
    }
    $requiredPermissions = ['clients.view', 'clients.manage'];
    if (Schema::hasTable('permissions') && DB::table('permissions')->where('is_active', true)->whereIn('slug', $requiredPermissions)->count() !== count($requiredPermissions)) {
        $errors[] = 'مجوزهای Stage 02 / Work 01 کامل نیستند.';
    }
    if ($errors !== []) {
        foreach ($errors as $error) {
            $this->error($error);
        }
        return 1;
    }
    $this->info('VERIFY_STAGE02_OK');
    return 0;
})->purpose('Verify Ensha Stage 02 / Work 01 after deployment');

Artisan::command('ensha:verify-stage02w02', function () {
    $errors = [];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.13.0') {
        $errors[] = 'VERSION باید 0.13.0 باشد.';
    }
    foreach (['cases', 'case_assignments', 'case_status_histories'] as $table) {
        if (! Schema::hasTable($table)) $errors[] = "جدول {$table} وجود ندارد.";
    }
    foreach (['cases' => ['client_id', 'centre_id', 'case_number', 'status', 'priority'], 'case_assignments' => ['case_id', 'user_id', 'assignment_role', 'status'], 'case_status_histories' => ['case_id', 'to_status', 'changed_by', 'changed_at']] as $table => $columns) {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) $errors[] = "ستون {$table}.{$column} وجود ندارد.";
        }
    }
    $requiredPermissions = ['cases.view', 'cases.manage', 'cases.assign'];
    if (Schema::hasTable('permissions') && DB::table('permissions')->where('is_active', true)->whereIn('slug', $requiredPermissions)->count() !== count($requiredPermissions)) {
        $errors[] = 'مجوزهای پرونده‌های مشاوره کامل نیستند.';
    }
    if ($errors !== []) {
        foreach ($errors as $error) $this->error($error);
        return 1;
    }
    $this->info('VERIFY_STAGE02_W02_OK');
    return 0;
})->purpose('Verify Ensha Stage 02 / Work 02 after deployment');

Artisan::command('ensha:verify-stage02-complete', function () {
    $errors = [];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.14.0') {
        $errors[] = 'VERSION باید 0.14.0 باشد.';
    }
    $tables = [
        'clients', 'external_identities', 'cases', 'case_assignments', 'case_status_histories',
        'client_intakes', 'client_guardians', 'emergency_contacts', 'client_consents',
        'counselling_sessions', 'confidential_notes', 'note_addenda', 'private_files', 'client_merge_records',
    ];
    foreach ($tables as $table) {
        if (! Schema::hasTable($table)) $errors[] = "جدول {$table} وجود ندارد.";
    }
    $columns = [
        'official_holidays' => ['gregorian_date'],
        'clients' => ['merged_into_id', 'merged_at', 'merged_by'],
        'client_intakes' => ['client_id', 'intake_number', 'risk_level', 'status'],
        'client_guardians' => ['client_id', 'full_name', 'has_legal_authority'],
        'emergency_contacts' => ['client_id', 'phone', 'priority'],
        'client_consents' => ['client_id', 'consent_type', 'is_granted', 'revoked_at'],
        'counselling_sessions' => ['case_id', 'session_number', 'counselor_id', 'status'],
        'confidential_notes' => ['case_id', 'body', 'status', 'content_hash', 'finalized_at'],
        'note_addenda' => ['confidential_note_id', 'body', 'content_hash'],
        'private_files' => ['client_id', 'path', 'sha256', 'classification', 'scan_status'],
        'client_merge_records' => ['source_client_id', 'target_client_id', 'merge_summary', 'executed_at'],
    ];
    foreach ($columns as $table => $requiredColumns) {
        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn($table, $column)) $errors[] = "ستون {$table}.{$column} وجود ندارد.";
        }
    }
    $requiredPermissions = [
        'clients.view', 'clients.manage', 'cases.view', 'cases.manage', 'cases.assign',
        'intakes.view', 'intakes.manage', 'sessions.view', 'sessions.manage', 'notes.view', 'notes.manage',
        'private_files.view', 'private_files.manage', 'clients.duplicates', 'clients.merge',
    ];
    if (Schema::hasTable('permissions') && DB::table('permissions')->where('is_active', true)->whereIn('slug', $requiredPermissions)->count() !== count($requiredPermissions)) {
        $errors[] = 'مجوزهای کامل Stage 02 ثبت نشده‌اند.';
    }
    if (Schema::hasTable('permission_role') && Schema::hasTable('roles') && Schema::hasTable('permissions')) {
        $managerCount = DB::table('permission_role as pr')->join('roles as r', 'r.id', '=', 'pr.role_id')->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('r.slug', 'manager')->whereIn('p.slug', $requiredPermissions)->distinct()->count('p.slug');
        if ($managerCount !== count($requiredPermissions)) $errors[] = 'دسترسی مدیر مرکز برای Stage 02 کامل نیست.';
    }
    if ($errors !== []) {
        foreach ($errors as $error) $this->error($error);
        return 1;
    }
    $this->info('VERIFY_STAGE02_COMPLETE_OK');
    return 0;
})->purpose('Verify completed Ensha Stage 02 / v0.14.0');

Artisan::command('ensha:verify-stage03-complete', function () {
    $errors = [];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.15.0') $errors[] = 'VERSION باید 0.15.0 باشد.';
    $tables = ['schedule_exceptions', 'service_tariffs', 'appointment_slots', 'appointments', 'appointment_status_histories'];
    foreach ($tables as $table) if (! Schema::hasTable($table)) $errors[] = "جدول {$table} وجود ندارد.";
    $columns = [
        'service_topics' => ['allowed_modes', 'break_minutes', 'capacity', 'requires_room', 'is_active'],
        'centre_rooms' => ['branch_id', 'capacity', 'room_type'],
        'counselor_shifts' => ['branch_id', 'slot_interval_minutes', 'break_minutes', 'is_active'],
        'service_tariffs' => ['topic_id', 'counselor_id', 'branch_id', 'scope_key', 'version', 'valid_from', 'valid_until'],
        'appointment_slots' => ['topic_id', 'counselor_id', 'starts_at', 'capacity', 'booked_count', 'lock_version'],
        'appointments' => ['appointment_number', 'slot_id', 'seat_number', 'status', 'price'],
        'appointment_status_histories' => ['appointment_id', 'from_status', 'to_status', 'changed_at'],
    ];
    foreach ($columns as $table => $required) foreach ($required as $column) if (! Schema::hasColumn($table, $column)) $errors[] = "ستون {$table}.{$column} وجود ندارد.";
    $requiredPermissions = ['appointments.view', 'appointments.manage', 'appointments.status', 'slots.manage', 'tariffs.view', 'tariffs.manage', 'schedule_exceptions.manage'];
    if (Schema::hasTable('permissions') && DB::table('permissions')->where('is_active', true)->whereIn('slug', $requiredPermissions)->count() !== count($requiredPermissions)) $errors[] = 'مجوزهای کامل Stage 03 ثبت نشده‌اند.';
    if (config('appointments.lock_store') !== 'redis') $errors[] = 'قفل نوبت باید روی Redis تنظیم شود.';
    if ($errors !== []) { foreach ($errors as $error) $this->error($error); return 1; }
    $this->info('VERIFY_STAGE03_COMPLETE_OK');
    return 0;
})->purpose('Verify completed Ensha Stage 03 / v0.15.0');

Artisan::command('ensha:verify-stage04-complete', function () {
    $errors = [];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.16.0') $errors[] = 'VERSION باید 0.16.0 باشد.';
    if (! Schema::hasTable('calendar_preferences')) $errors[] = 'جدول calendar_preferences وجود ندارد.';
    foreach (['app/Http/Controllers/SecretaryCalendarController.php', 'resources/views/appointments/calendar.blade.php', 'public/js/secretary-calendar.js', 'public/css/secretary-calendar.css'] as $file) if (! file_exists(base_path($file))) $errors[] = "فایل تقویم وجود ندارد: {$file}";
    foreach (['appointments.calendar', 'appointments.calendar.api.events', 'appointments.calendar.api.update'] as $route) {
        if (! \Illuminate\Support\Facades\Route::has($route)) $errors[] = "مسیر {$route} ثبت نشده است.";
    }
    if ($errors !== []) { foreach ($errors as $error) $this->error($error); return 1; }
    $this->info('VERIFY_STAGE04_COMPLETE_OK');
    return 0;
})->purpose('Verify completed Ensha Stage 04 / v0.16.0');

Artisan::command('ensha:verify-stage05-complete', function () {
    $errors=[];
    if(trim((string)@file_get_contents(base_path('VERSION'))) !== '0.17.0') $errors[]='VERSION باید 0.17.0 باشد.';
    foreach(['appointment_waitlists','appointment_reschedules','telephone_consultations','sms_messages'] as $table) if(!Schema::hasTable($table)) $errors[]="جدول {$table} وجود ندارد.";
    foreach(['checked_in_at','checked_in_by','session_started_at','session_ended_at','no_show_at'] as $column) if(!Schema::hasColumn('appointments',$column)) $errors[]="ستون appointments.{$column} وجود ندارد.";
    foreach(['operations.index','operations.telephone','operations.report','operations.check-in','operations.waitlist.store','operations.sms.reminders'] as $route) if(!\Illuminate\Support\Facades\Route::has($route)) $errors[]="مسیر {$route} ثبت نشده است.";
    if($errors!==[]){foreach($errors as $error)$this->error($error);return 1;}$this->info('VERIFY_STAGE05_COMPLETE_OK');return 0;
})->purpose('Verify completed Ensha Stage 05 / v0.17.0');

Artisan::command('ensha:verify-stage06-complete', function () {
    $errors=[];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.18.2') $errors[]='VERSION باید 0.18.2 باشد.';
    foreach (['discounts','appointment_statuses','counselor_leave_requests','staff_work_sessions','staff_work_session_audits',
        'staff_pay_rules','client_record_settings','client_profile_field_permissions','appointment_waitlists'] as $table) {
        if (! Schema::hasTable($table)) $errors[]="جدول {$table} وجود ندارد.";
    }
    foreach (['public_id','source','duration_minutes','base_price','final_price','paid_amount','balance_amount',
        'topic_name_snapshot','topic_color_snapshot','status_id','status_snapshot'] as $column) {
        if (! Schema::hasColumn('appointments',$column)) $errors[]="ستون appointments.{$column} وجود ندارد.";
    }
    if (! Schema::hasColumn('clients','profile_state')) $errors[]='ستون وضعیت تکمیل پرونده وجود ندارد.';
    foreach (['appointments.calendar.api.quote','stage06.clients.search','stage06.clients.quick','attendance.index','counselor.week','stage06.settings'] as $route) {
        if (! \Illuminate\Support\Facades\Route::has($route)) $errors[]="مسیر {$route} وجود ندارد.";
    }
    foreach (['public/vendor/daypilot/daypilot-javascript.min.js','public/js/stage06-scheduler.js',
        'resources/views/appointments/calendar.blade.php'] as $file) if (! file_exists(base_path($file))) $errors[]="فایل {$file} وجود ندارد.";
    if (Schema::hasTable('appointments') && DB::table('appointments')->whereNull('public_id')->exists()) $errors[]='نوبت بدون شناسه عمومی وجود دارد.';
    if ($errors) { foreach ($errors as $error) $this->error($error); return 1; }
    $this->info('VERIFY_STAGE06_COMPLETE_OK'); return 0;
})->purpose('Verify Stage 06 scheduling, records, leave and attendance / v0.18.2');

Artisan::command('ensha:verify-stage07-complete', function () {
    $errors=[];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.19.0') $errors[]='VERSION باید 0.19.0 باشد.';
    foreach (['session_report_templates','session_reports','session_report_addenda'] as $table) {
        if (! Schema::hasTable($table)) $errors[]="جدول {$table} وجود ندارد.";
    }
    if (! Schema::hasColumn('counselling_sessions','appointment_id')) $errors[]='اتصال جلسه به نوبت وجود ندارد.';
    foreach (['appointment_id','counselling_session_id','structured_answers','content_hash','finalized_at'] as $column) {
        if (Schema::hasTable('session_reports') && ! Schema::hasColumn('session_reports',$column)) $errors[]="ستون session_reports.{$column} وجود ندارد.";
    }
    foreach (['session_reports.view','session_reports.manage','session_report_templates.manage'] as $permission) {
        if (! DB::table('permissions')->where('slug',$permission)->where('is_active',true)->exists()) $errors[]="مجوز {$permission} ثبت نشده است.";
    }
    foreach (['counselor.workspace','counselor.reports.show','counselor.reports.save','counselor.reports.finalize','counselor.sessions.start','counselor.sessions.complete','centres.appointments-settings.report-templates.store'] as $route) {
        if (! \Illuminate\Support\Facades\Route::has($route)) $errors[]="مسیر {$route} وجود ندارد.";
    }
    foreach (['app/Http/Controllers/CounselorClinicalController.php','resources/views/counselor/workspace.blade.php','resources/views/counselor/session-report.blade.php','public/css/stage07-clinical.css','public/js/stage07-clinical.js'] as $file) {
        if (! file_exists(base_path($file))) $errors[]="فایل {$file} وجود ندارد.";
    }
    if (Schema::hasTable('centres') && Schema::hasTable('session_report_templates')) {
        foreach (DB::table('centres')->pluck('id') as $centreId) {
            if (! DB::table('session_report_templates')->where('centre_id',$centreId)->where('is_active',true)->exists()) $errors[]="مرکز {$centreId} فرم گزارش فعال ندارد.";
        }
    }
    $managerPermissions=DB::table('permission_role as pr')->join('roles as r','r.id','=','pr.role_id')->join('permissions as p','p.id','=','pr.permission_id')
        ->where('r.slug','manager')->whereIn('p.slug',['session_reports.view','session_report_templates.manage'])->distinct()->count('p.slug');
    if ($managerPermissions!==2) $errors[]='مجوزهای مدیر برای گزارش جلسه کامل نیست.';
    $counselorPermissions=DB::table('permission_role as pr')->join('roles as r','r.id','=','pr.role_id')->join('permissions as p','p.id','=','pr.permission_id')
        ->where('r.slug','counselor')->whereIn('p.slug',['session_reports.view','session_reports.manage'])->distinct()->count('p.slug');
    if ($counselorPermissions!==2) $errors[]='مجوزهای مشاور برای گزارش جلسه کامل نیست.';
    if ($errors) { foreach($errors as $error) $this->error($error); return 1; }
    $this->info('VERIFY_STAGE07_COMPLETE_OK');
    return 0;
})->purpose('Verify Ensha Stage 07 counselor workspace and session reports / v0.19.0');

Artisan::command('ensha:verify-stage08-complete', function () {
    $errors=[];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.20.0') $errors[]='VERSION باید 0.20.0 باشد.';
    if (! extension_loaded('sodium')) $errors[]='افزونه Sodium برای رمزگذاری صوت فعال نیست.';
    if (! Schema::hasTable('session_recordings')) $errors[]='جدول session_recordings وجود ندارد.';
    foreach (['public_id','appointment_id','counselling_session_id','consent_id','path','sha256','plaintext_sha256','status','transcript_status','transcript_text','consent_snapshot','transcript_hash'] as $column) {
        if (Schema::hasTable('session_recordings') && ! Schema::hasColumn('session_recordings',$column)) $errors[]="ستون session_recordings.{$column} وجود ندارد.";
    }
    foreach (['session_recordings.view','session_recordings.manage','session_transcripts.manage','session_recordings.audit'] as $permission) {
        if (! DB::table('permissions')->where('slug',$permission)->where('is_active',true)->exists()) $errors[]="مجوز {$permission} ثبت نشده است.";
    }
    foreach (['counselor.recordings.consent','counselor.recordings.initialize','counselor.recordings.chunk','counselor.recordings.finalize','counselor.recordings.stream','counselor.recordings.transcribe','counselor.recordings.transcript','counselor.recordings.destroy'] as $route) {
        if (! \Illuminate\Support\Facades\Route::has($route)) $errors[]="مسیر {$route} وجود ندارد.";
    }
    foreach (['app/Http/Controllers/SessionRecordingController.php','app/Jobs/TranscribeSessionRecording.php','app/Models/SessionRecording.php','app/Services/ClinicalAudioVault.php','config/clinical_audio.php','resources/views/counselor/partials/recording-panel.blade.php','public/css/stage08-recording.css','public/js/stage08-recorder.js'] as $file) {
        if (! file_exists(base_path($file))) $errors[]="فایل {$file} وجود ندارد.";
    }
    $counselorPermissions=DB::table('permission_role as pr')->join('roles as r','r.id','=','pr.role_id')->join('permissions as p','p.id','=','pr.permission_id')
        ->where('r.slug','counselor')->whereIn('p.slug',['session_recordings.view','session_recordings.manage','session_transcripts.manage'])->distinct()->count('p.slug');
    if ($counselorPermissions!==3) $errors[]='مجوزهای ضبط و متن مشاور کامل نیست.';
    $secretaryRecordingPermissions=DB::table('permission_role as pr')->join('roles as r','r.id','=','pr.role_id')->join('permissions as p','p.id','=','pr.permission_id')
        ->where('r.slug','secretary')->whereIn('p.slug',['session_recordings.view','session_recordings.manage','session_transcripts.manage'])->count();
    if ($secretaryRecordingPermissions!==0) $errors[]='منشی نباید به محتوای صوت یا متن جلسه دسترسی داشته باشد.';
    if (config('clinical_audio.disk') !== 'local') $errors[]='در Stage 08 دیسک صوت امن باید local باشد.';
    $audioRoot=storage_path('app/private/ensha-audio');
    if (! is_dir($audioRoot)) $errors[]='پوشه خصوصی صوت ساخته نشده است.';
    if (is_link($audioRoot)) $errors[]='پوشه خصوصی صوت نباید symlink باشد.';
    if ($errors) { foreach($errors as $error) $this->error($error); return 1; }
    $this->info('VERIFY_STAGE08_COMPLETE_OK');
    return 0;
})->purpose('Verify Ensha Stage 08 secure session recording and transcription / v0.20.0');

Artisan::command('ensha:verify-stage09-complete', function () {
    $errors=[];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.21.0') $errors[]='VERSION باید 0.21.0 باشد.';
    foreach (['financial_sequences','cash_register_sessions','payment_transactions','payment_receipts'] as $table) {
        if (! Schema::hasTable($table)) $errors[]="جدول {$table} وجود ندارد.";
    }
    foreach (['payment_status','financial_updated_at'] as $column) {
        if (! Schema::hasColumn('appointments',$column)) $errors[]="ستون appointments.{$column} وجود ندارد.";
    }
    $columns=[
        'cash_register_sessions'=>['public_id','session_number','cashier_id','opening_cash_amount','expected_cash_amount','counted_cash_amount','difference_amount','status'],
        'payment_transactions'=>['public_id','transaction_number','appointment_id','cash_register_session_id','parent_transaction_id','kind','method','amount','signed_amount','idempotency_key'],
        'payment_receipts'=>['public_id','receipt_number','transaction_id','snapshot','issued_at'],
    ];
    foreach ($columns as $table=>$required) foreach ($required as $column) {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table,$column)) $errors[]="ستون {$table}.{$column} وجود ندارد.";
    }
    foreach (['payments.view','payments.create','payments.refund','payments.void','cash_register.view','cash_register.manage','finance.reports.view'] as $permission) {
        if (! DB::table('permissions')->where('slug',$permission)->where('is_active',true)->exists()) $errors[]="مجوز {$permission} ثبت نشده است.";
    }
    if (! DB::table('roles')->where('slug','finance')->where('scope','centre')->where('is_active',true)->exists()) $errors[]='نقش مسئول مالی ثبت نشده است.';
    foreach (['finance.cashier.index','finance.cash-register.open','finance.cash-register.close','finance.payments.store','finance.transactions.refund','finance.transactions.void','finance.receipts.show'] as $route) {
        if (! \Illuminate\Support\Facades\Route::has($route)) $errors[]="مسیر {$route} وجود ندارد.";
    }
    foreach (['app/Services/PaymentLedgerService.php','app/Http/Controllers/FinanceController.php','app/Models/PaymentTransaction.php','resources/views/finance/cashier.blade.php','resources/views/finance/receipt.blade.php','public/css/stage09-finance.css'] as $file) {
        if (! file_exists(base_path($file))) $errors[]="فایل {$file} وجود ندارد.";
    }
    $managerCount=DB::table('permission_role as pr')->join('roles as r','r.id','=','pr.role_id')->join('permissions as p','p.id','=','pr.permission_id')
        ->where('r.slug','manager')->whereIn('p.slug',['payments.view','payments.create','payments.refund','payments.void','cash_register.manage'])->distinct()->count('p.slug');
    if ($managerCount!==5) $errors[]='مجوزهای مالی مدیر کامل نیست.';
    $secretarySensitive=DB::table('permission_role as pr')->join('roles as r','r.id','=','pr.role_id')->join('permissions as p','p.id','=','pr.permission_id')
        ->where('r.slug','secretary')->whereIn('p.slug',['payments.refund','payments.void','finance.reports.view'])->count();
    if ($secretarySensitive!==0) $errors[]='منشی نباید مجوز برگشت، ابطال یا گزارش مالی داشته باشد.';
    if (Schema::hasTable('payment_transactions')) {
        $mismatch=DB::table('appointments as a')->leftJoinSub(
            DB::table('payment_transactions')->selectRaw('appointment_id, COALESCE(SUM(signed_amount),0) ledger_paid')->where('status','posted')->groupBy('appointment_id'),
            'p','p.appointment_id','=','a.id')->whereRaw('a.paid_amount <> COALESCE(p.ledger_paid,0)')->count();
        if ($mismatch) $errors[]="{$mismatch} نوبت با دفتر تراکنش ناسازگار است.";
    }
    if ($errors) { foreach($errors as $error) $this->error($error); return 1; }
    $this->info('VERIFY_STAGE09_COMPLETE_OK'); return 0;
})->purpose('Verify Ensha Stage 09 payment ledger, receipts and daily cash register / v0.21.0');

Artisan::command('ensha:verify-stage10-complete', function () {
    $errors=[];
    if (trim((string) @file_get_contents(base_path('VERSION'))) !== '0.22.0') $errors[]='VERSION باید 0.22.0 باشد.';
    foreach (['compensation_rules','appointment_compensation_snapshots','counselor_settlements','counselor_settlement_items','counselor_settlement_adjustments'] as $table) {
        if (! Schema::hasTable($table)) $errors[]="جدول {$table} وجود ندارد.";
    }
    $columns=[
        'compensation_rules'=>['public_id','centre_id','topic_id','counselor_id','beneficiary','calculation_type','value','version','valid_from','valid_until'],
        'appointment_compensation_snapshots'=>['appointment_id','rule_snapshot','gross_amount','centre_share_amount','counselor_share_amount','locked_at'],
        'counselor_settlements'=>['settlement_number','period_start','period_end','status','deductions_amount','bonuses_amount','payable_amount','paid_amount'],
        'counselor_settlement_items'=>['settlement_id','appointment_id','compensation_snapshot_id','collected_amount','centre_share_amount','counselor_share_amount'],
    ];
    foreach ($columns as $table=>$required) foreach ($required as $column) {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table,$column)) $errors[]="ستون {$table}.{$column} وجود ندارد.";
    }
    $permissions=['compensation_rules.view','compensation_rules.manage','compensation_snapshots.view','settlements.view','settlements.manage','settlements.approve','settlements.pay'];
    if (DB::table('permissions')->where('is_active',true)->whereIn('slug',$permissions)->count()!==count($permissions)) $errors[]='مجوزهای Stage 10 کامل نیستند.';
    foreach (['centres.compensation-rules.index','centres.compensation-rules.store','centres.compensation-rules.retire','settlements.index','settlements.store','settlements.show','settlements.approve','settlements.pay','settlements.cancel'] as $route) {
        if (! \Illuminate\Support\Facades\Route::has($route)) $errors[]="مسیر {$route} وجود ندارد.";
    }
    foreach (['app/Services/CompensationService.php','app/Http/Controllers/CompensationRuleController.php','app/Http/Controllers/CounselorSettlementController.php','resources/views/finance/compensation-rules.blade.php','resources/views/finance/settlements/index.blade.php','resources/views/finance/settlements/show.blade.php','public/css/stage10-settlements.css'] as $file) {
        if (! file_exists(base_path($file))) $errors[]="فایل {$file} وجود ندارد.";
    }
    if (Schema::hasTable('appointment_compensation_snapshots')) {
        $missing=DB::table('appointments as a')->leftJoin('appointment_compensation_snapshots as s','s.appointment_id','=','a.id')->where('a.status','completed')->whereNull('s.id')->count();
        if ($missing) $errors[]="{$missing} جلسه تکمیل‌شده Snapshot سهم ندارد.";
        $bad=DB::table('appointment_compensation_snapshots')->whereRaw('gross_amount <> centre_share_amount + counselor_share_amount')->count();
        if ($bad) $errors[]="{$bad} Snapshot سهم جمع ناسازگار دارد.";
    }
    if (Schema::hasTable('counselor_settlements')) {
        $badTotals=DB::table('counselor_settlements')->whereRaw('payable_amount <> GREATEST(0, counselor_share_amount - deductions_amount + bonuses_amount)')->count();
        if ($badTotals) $errors[]="{$badTotals} تسویه جمع ناسازگار دارد.";
    }
    $managerCount=DB::table('permission_role as pr')->join('roles as r','r.id','=','pr.role_id')->join('permissions as p','p.id','=','pr.permission_id')->where('r.slug','manager')->whereIn('p.slug',$permissions)->distinct()->count('p.slug');
    if ($managerCount!==count($permissions)) $errors[]='دسترسی مدیر برای Stage 10 کامل نیست.';
    $financeApprove=DB::table('permission_role as pr')->join('roles as r','r.id','=','pr.role_id')->join('permissions as p','p.id','=','pr.permission_id')->where('r.slug','finance')->where('p.slug','settlements.approve')->exists();
    if ($financeApprove) $errors[]='مسئول مالی نباید تأییدکننده تسویه خودش باشد.';
    if ($errors) { foreach($errors as $error) $this->error($error); return 1; }
    $this->info('VERIFY_STAGE10_COMPLETE_OK'); return 0;
})->purpose('Verify Ensha Stage 10 versioned compensation and counselor settlements / v0.22.0');
