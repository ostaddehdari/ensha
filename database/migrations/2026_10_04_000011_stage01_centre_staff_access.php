<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('centres', 'timezone')) {
            Schema::table('centres', fn (Blueprint $table) => $table->string('timezone', 64)->default('Asia/Tehran'));
        }
        if (! Schema::hasColumn('centres', 'settings')) {
            Schema::table('centres', fn (Blueprint $table) => $table->json('settings')->nullable());
        }

        if (! Schema::hasTable('centre_branches')) {
            Schema::create('centre_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('code', 50);
                $table->string('timezone', 64)->nullable();
                $table->string('phone', 20)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('address', 500)->nullable();
                $table->boolean('is_default')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->json('settings')->nullable();
                $table->timestamps();
                $table->unique(['centre_id', 'code']);
                $table->index(['centre_id', 'is_active']);
            });
        }

        $now = now();
        DB::table('centres')->orderBy('id')->get()->each(function ($centre) use ($now) {
            DB::table('centre_branches')->insertOrIgnore([
                'centre_id' => $centre->id,
                'name' => 'شعبه اصلی',
                'code' => 'MAIN',
                'timezone' => $centre->timezone ?: 'Asia/Tehran',
                'phone' => $centre->phone,
                'email' => $centre->email,
                'address' => $centre->address,
                'is_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        if (! Schema::hasColumn('user_role_centres', 'branch_id')) {
            Schema::table('user_role_centres', function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->constrained('centre_branches')->nullOnDelete();
                $table->index(['centre_id', 'branch_id']);
            });
        }

        DB::table('user_role_centres')->whereNotNull('centre_id')->orderBy('id')->chunkById(500, function ($assignments) {
            foreach ($assignments as $assignment) {
                $branchId = DB::table('centre_branches')
                    ->where('centre_id', $assignment->centre_id)
                    ->where('is_default', true)
                    ->value('id');
                DB::table('user_role_centres')->where('id', $assignment->id)->update(['branch_id' => $branchId]);
            }
        });

        if (! Schema::hasColumn('staff_profiles', 'employee_code')) {
            Schema::table('staff_profiles', fn (Blueprint $table) => $table->string('employee_code', 60)->nullable()->unique());
        }
        if (! Schema::hasColumn('staff_profiles', 'department')) {
            Schema::table('staff_profiles', fn (Blueprint $table) => $table->string('department', 120)->nullable());
        }
        if (! Schema::hasColumn('staff_profiles', 'work_email')) {
            Schema::table('staff_profiles', fn (Blueprint $table) => $table->string('work_email', 190)->nullable());
        }
        if (! Schema::hasColumn('staff_profiles', 'extension')) {
            Schema::table('staff_profiles', fn (Blueprint $table) => $table->string('extension', 20)->nullable());
        }

        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $now = now();
        $permissions = [
            ['branches.view', 'مشاهده شعب', 'centres', 'مراکز', 'مشاهده شعب مرکزهای مجاز', 185],
            ['branches.manage', 'مدیریت شعب', 'centres', 'مراکز', 'ایجاد و ویرایش شعب مرکز', 186],
            ['centre_settings.view', 'مشاهده تنظیمات مرکز', 'centres', 'مراکز', 'مشاهده منطقه زمانی و تنظیمات عملیاتی مرکز', 187],
            ['centre_settings.manage', 'مدیریت تنظیمات مرکز', 'centres', 'مراکز', 'ویرایش منطقه زمانی و تنظیمات عملیاتی مرکز', 188],
            ['staff.view', 'مشاهده کارکنان', 'staff', 'کارکنان', 'مشاهده کارکنان و پرونده همکاری در دامنه مجاز', 195],
            ['staff.manage', 'مدیریت کارکنان', 'staff', 'کارکنان', 'ویرایش پرونده همکاری و شعبه کارکنان', 196],
        ];

        foreach ($permissions as [$slug, $name, $groupKey, $groupName, $description, $sortOrder]) {
            DB::table('permissions')->updateOrInsert(['slug' => $slug], [
                'name' => $name,
                'group_key' => $groupKey,
                'group_name' => $groupName,
                'description' => $description,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $grants = [
            'super_admin' => array_column($permissions, 0),
            'manager' => array_column($permissions, 0),
            'secretary' => ['branches.view', 'centre_settings.view', 'staff.view'],
        ];

        foreach ($grants as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }
            $permissionIds = DB::table('permissions')->whereIn('slug', $permissionSlugs)->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $slugs = ['branches.view', 'branches.manage', 'centre_settings.view', 'centre_settings.manage', 'staff.view', 'staff.manage'];
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropUnique(['employee_code']);
            $table->dropColumn(['employee_code', 'department', 'work_email', 'extension']);
        });
        Schema::table('user_role_centres', function (Blueprint $table) {
            $table->dropIndex(['centre_id', 'branch_id']);
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::dropIfExists('centre_branches');
        Schema::table('centres', fn (Blueprint $table) => $table->dropColumn(['timezone', 'settings']));
    }
};
