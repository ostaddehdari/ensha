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
