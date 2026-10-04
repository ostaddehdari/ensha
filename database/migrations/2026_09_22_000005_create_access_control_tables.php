<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centres', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('code', 50)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('description', 500)->nullable();
            $table->string('scope', 20)->default('centre')->index();
            $table->string('color', 30)->default('slate');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 120)->unique();
            $table->string('group_key', 80)->index();
            $table->string('group_name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('centre_id')->nullable()->after('id')->constrained('centres')->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('centre_id')->constrained('roles')->nullOnDelete();
            $table->string('status', 20)->default('active')->after('is_active')->index();
            $table->string('status_reason', 500)->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');
            $table->foreignId('created_by')->nullable()->after('metadata')->constrained('users')->nullOnDelete();
            $table->foreignId('status_changed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable()->after('status_changed_by');
            $table->ipAddress('last_login_ip')->nullable()->after('last_login_at');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_ip');
            $table->boolean('must_change_password')->default(false)->after('password_changed_at');
            $table->unsignedInteger('auth_revision')->default(1)->after('must_change_password');
            $table->timestamp('sessions_revoked_at')->nullable()->after('auth_revision');
            $table->softDeletes();
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('session_hash', 64)->unique();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_name', 160)->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoke_reason', 120)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 120)->nullable()->after('event')->index();
            $table->string('subject_type')->nullable()->after('actor_id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->index(['subject_type', 'subject_id']);
        });

        $this->seedAccessControl();
        $this->migrateLegacyUsers();
    }

    private function seedAccessControl(): void
    {
        $now = now();
        $roles = [
            ['name' => 'ادمین سیستم', 'slug' => 'super_admin', 'description' => 'دسترسی کامل و سراسری به سامانه', 'scope' => 'global', 'color' => 'danger', 'sort_order' => 10],
            ['name' => 'مدیر مرکز مشاوره', 'slug' => 'manager', 'description' => 'مدیریت کاربران و عملیات مرکز اختصاص‌یافته', 'scope' => 'centre', 'color' => 'primary', 'sort_order' => 20],
            ['name' => 'منشی', 'slug' => 'secretary', 'description' => 'مدیریت مراجعین و فرایند نوبت‌دهی', 'scope' => 'centre', 'color' => 'success', 'sort_order' => 30],
            ['name' => 'مشاور', 'slug' => 'counselor', 'description' => 'دسترسی به برنامه و پرونده‌های مجاز', 'scope' => 'centre', 'color' => 'warning', 'sort_order' => 40],
            ['name' => 'مسئول تست', 'slug' => 'test_manager', 'description' => 'مدیریت آزمون‌ها و نتایج مجاز', 'scope' => 'centre', 'color' => 'info', 'sort_order' => 50],
            ['name' => 'مراجعه‌کننده', 'slug' => 'client', 'description' => 'دسترسی شخصی مراجعه‌کننده', 'scope' => 'centre', 'color' => 'slate', 'sort_order' => 60],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->insertOrIgnore([
                ...$role,
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissions = [
            ['dashboard.view', 'مشاهده داشبورد', 'dashboard', 'داشبورد', 'ورود و مشاهده داشبورد نقش', 10],
            ['users.view', 'مشاهده کاربران', 'users', 'کاربران', 'مشاهده فهرست و جزئیات کاربران مجاز', 20],
            ['users.create', 'ایجاد کاربر', 'users', 'کاربران', 'ساخت حساب کاربری جدید', 30],
            ['users.update', 'ویرایش کاربر', 'users', 'کاربران', 'ویرایش مشخصات، نقش و مرکز کاربر', 40],
            ['users.change_status', 'تغییر وضعیت کاربر', 'users', 'کاربران', 'فعال، غیرفعال یا مسدودکردن حساب', 50],
            ['users.reset_password', 'بازنشانی رمز عبور', 'users', 'کاربران', 'تعیین رمز موقت و اجبار به تغییر رمز', 60],
            ['users.revoke_sessions', 'ابطال نشست‌ها', 'users', 'کاربران', 'خروج اجباری کاربر از دستگاه‌های فعال', 70],
            ['users.impersonate', 'ورود موقت به حساب کاربر', 'users', 'کاربران', 'ورود کنترل‌شده ادمین به پنل کاربر', 80],
            ['users.export', 'خروجی کاربران', 'users', 'کاربران', 'دریافت خروجی کاربران در دامنه مجاز', 90],
            ['users.delete', 'حذف کاربر', 'users', 'کاربران', 'حذف نرم حساب کاربری', 100],
            ['users.restore', 'بازیابی کاربر', 'users', 'کاربران', 'بازیابی حساب حذف‌شده', 110],
            ['roles.view', 'مشاهده نقش‌ها', 'access', 'نقش‌ها و دسترسی‌ها', 'مشاهده نقش‌ها و ماتریس مجوزها', 120],
            ['roles.create', 'ایجاد نقش', 'access', 'نقش‌ها و دسترسی‌ها', 'ساخت نقش سفارشی', 130],
            ['roles.update', 'ویرایش نقش', 'access', 'نقش‌ها و دسترسی‌ها', 'ویرایش نقش و مجوزهای آن', 140],
            ['roles.delete', 'حذف نقش', 'access', 'نقش‌ها و دسترسی‌ها', 'حذف نقش سفارشی بدون کاربر', 150],
            ['permissions.view', 'مشاهده مجوزها', 'access', 'نقش‌ها و دسترسی‌ها', 'مشاهده کاتالوگ مجوزهای سامانه', 160],
            ['profile_fields.manage', 'مدیریت فرم‌ساز پروفایل', 'profiles', 'پروفایل‌ها', 'افزودن و حذف فیلدهای نقش‌ها', 170],
            ['centres.view', 'مشاهده مراکز', 'centres', 'مراکز', 'مشاهده مرکزهای مجاز', 180],
            ['centres.manage', 'مدیریت مراکز', 'centres', 'مراکز', 'ایجاد و ویرایش مراکز', 190],
            ['counselors.view', 'مشاهده مشاوران', 'operations', 'عملیات مرکز', 'مشاهده مشاوران مرکز', 200],
            ['counselors.manage', 'مدیریت مشاوران', 'operations', 'عملیات مرکز', 'مدیریت اطلاعات همکاری مشاوران', 210],
            ['schedules.view', 'مشاهده ساعات کاری', 'operations', 'عملیات مرکز', 'مشاهده برنامه کاری مشاوران', 220],
            ['schedules.manage', 'مدیریت ساعات کاری', 'operations', 'عملیات مرکز', 'تنظیم برنامه و تعطیلات مشاوران', 230],
            ['appointments.view', 'مشاهده نوبت‌ها', 'appointments', 'نوبت‌ها', 'مشاهده نوبت‌های مجاز', 240],
            ['appointments.manage', 'مدیریت نوبت‌ها', 'appointments', 'نوبت‌ها', 'ایجاد و ویرایش نوبت‌ها', 250],
            ['fees.view', 'مشاهده تعرفه‌ها', 'finance', 'مالی', 'مشاهده تعرفه و دستمزد', 260],
            ['fees.manage', 'مدیریت تعرفه‌ها', 'finance', 'مالی', 'تعیین تعرفه و دستمزد مشاور', 270],
            ['tests.view', 'مشاهده آزمون‌ها', 'tests', 'آزمون‌ها', 'مشاهده آزمون‌ها و نتایج مجاز', 280],
            ['tests.manage', 'مدیریت آزمون‌ها', 'tests', 'آزمون‌ها', 'ساخت و تخصیص آزمون‌ها', 290],
            ['notifications.manage', 'مدیریت اعلان‌ها', 'communication', 'ارتباطات', 'مدیریت قالب‌ها و ارسال اعلان', 300],
            ['chat.manage', 'مدیریت چت', 'communication', 'ارتباطات', 'مدیریت تنظیمات و دسترسی چت', 310],
            ['monitoring.view', 'مشاهده پایش سیستم', 'monitoring', 'پایش و گزارش', 'مشاهده سلامت سرویس‌ها', 320],
            ['audit_logs.view', 'مشاهده لاگ‌های امنیتی', 'monitoring', 'پایش و گزارش', 'مشاهده رویدادها و تغییرات حساس', 330],
            ['reports.view', 'مشاهده گزارش‌ها', 'monitoring', 'پایش و گزارش', 'مشاهده و دریافت گزارش‌های مجاز', 340],
            ['settings.manage', 'مدیریت تنظیمات سامانه', 'settings', 'تنظیمات', 'ویرایش تنظیمات عمومی سامانه', 350],
        ];

        foreach ($permissions as [$slug, $name, $groupKey, $groupName, $description, $sortOrder]) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'slug' => $slug,
                'group_key' => $groupKey,
                'group_name' => $groupName,
                'description' => $description,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rolePermissions = [
            'super_admin' => array_column($permissions, 0),
            'manager' => [
                'dashboard.view', 'users.view', 'users.create', 'users.update', 'users.change_status',
                'users.reset_password', 'users.revoke_sessions', 'users.export', 'centres.view',
                'counselors.view', 'counselors.manage', 'schedules.view', 'schedules.manage',
                'appointments.view', 'appointments.manage', 'fees.view', 'fees.manage', 'reports.view',
            ],
            'secretary' => ['dashboard.view', 'appointments.view', 'appointments.manage'],
            'counselor' => ['dashboard.view', 'appointments.view', 'tests.view'],
            'test_manager' => ['dashboard.view', 'tests.view', 'tests.manage'],
            'client' => ['dashboard.view', 'appointments.view', 'tests.view'],
        ];

        foreach ($rolePermissions as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            $permissionIds = DB::table('permissions')->whereIn('slug', $permissionSlugs)->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        DB::table('centres')->insertOrIgnore([
            'name' => config('app.name', 'انشا').' - مرکز اصلی',
            'code' => 'ENSHA-MAIN',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function migrateLegacyUsers(): void
    {
        $centreId = DB::table('centres')->where('code', 'ENSHA-MAIN')->value('id');
        $aliases = [
            'super_admin' => 'super_admin',
            'admin' => 'super_admin',
            'administrator' => 'super_admin',
            'manager' => 'manager',
            'centre_manager' => 'manager',
            'secretary' => 'secretary',
            'counselor' => 'counselor',
            'consultant' => 'counselor',
            'test_manager' => 'test_manager',
            'test_operator' => 'test_manager',
            'client' => 'client',
            'patient' => 'client',
            'user' => 'client',
        ];

        foreach ($aliases as $legacy => $canonical) {
            $roleId = DB::table('roles')->where('slug', $canonical)->value('id');
            $changes = [
                'role' => $canonical,
                'role_id' => $roleId,
                'status' => DB::raw("CASE WHEN is_active = 1 THEN 'active' ELSE 'inactive' END"),
            ];
            if ($canonical !== 'super_admin') {
                $changes['centre_id'] = $centreId;
            }

            DB::table('users')->where('role', $legacy)->update($changes);
        }

        $clientRoleId = DB::table('roles')->where('slug', 'client')->value('id');
        DB::table('users')->whereNull('role_id')->update([
            'role' => 'client',
            'role_id' => $clientRoleId,
            'centre_id' => $centreId,
            'status' => DB::raw("CASE WHEN is_active = 1 THEN 'active' ELSE 'inactive' END"),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropIndex(['action']);
            $table->dropColumn(['action', 'subject_type', 'subject_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('centre_id');
            $table->dropColumn([
                'status', 'status_reason', 'status_changed_at', 'last_login_at', 'last_login_ip',
                'password_changed_at', 'must_change_password', 'auth_revision', 'sessions_revoked_at', 'deleted_at',
            ]);
        });

        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('centres');
    }
};
