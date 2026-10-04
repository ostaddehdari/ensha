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
