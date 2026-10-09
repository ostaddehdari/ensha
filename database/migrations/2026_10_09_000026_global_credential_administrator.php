<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'users.manage_credentials_globally';
    private const ADMIN_PHONE = '09130134984';

    public function up(): void
    {
        if (! Schema::hasTable('permission_user')) {
            Schema::create('permission_user', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['user_id', 'permission_id']);
            });
        }

        $now = now();
        DB::table('permissions')->updateOrInsert(['slug' => self::PERMISSION], [
            'name' => 'مدیریت سراسری اعتبارنامه کاربران',
            'group_key' => 'users',
            'group_name' => 'کاربران',
            'description' => 'مشاهده سراسری کاربران و تغییر محدود شماره تلفن یا رمز عبور آنان',
            'sort_order' => 65,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $userId = DB::table('users')->where('phone', self::ADMIN_PHONE)->value('id');
        $permissionId = DB::table('permissions')->where('slug', self::PERMISSION)->value('id');
        $credentialRoleIds = DB::table('roles')
            ->whereIn('slug', ['super_admin', 'manager'])
            ->pluck('id');
        foreach ($credentialRoleIds as $roleId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        if ($userId && $permissionId) {
            DB::table('permission_user')->insertOrIgnore([
                'user_id' => $userId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::PERMISSION)->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            }
        }
        DB::table('permissions')->where('slug', self::PERMISSION)->delete();
        Schema::dropIfExists('permission_user');
    }
};
