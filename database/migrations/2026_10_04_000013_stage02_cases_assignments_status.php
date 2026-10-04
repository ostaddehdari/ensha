<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cases')) {
            Schema::create('cases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('centre_id')->nullable()->constrained()->nullOnDelete();
                $table->string('case_number', 60);
                $table->string('title', 190);
                $table->string('status', 30)->default('open')->index();
                $table->string('priority', 20)->default('normal')->index();
                $table->date('opened_at')->nullable();
                $table->date('closed_at')->nullable();
                $table->text('presenting_issue')->nullable();
                $table->text('administrative_notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['centre_id', 'case_number']);
                $table->index(['centre_id', 'status']);
                $table->index(['client_id', 'status']);
            });
        }

        if (! Schema::hasTable('case_assignments')) {
            Schema::create('case_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('assignment_role', 40)->default('counselor');
                $table->boolean('is_primary')->default(false)->index();
                $table->string('status', 20)->default('active')->index();
                $table->date('starts_at')->nullable();
                $table->date('ends_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['case_id', 'user_id', 'assignment_role']);
                $table->index(['case_id', 'status']);
            });
        }

        if (! Schema::hasTable('case_status_histories')) {
            Schema::create('case_status_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30);
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('changed_at')->useCurrent();
                $table->timestamps();
                $table->index(['case_id', 'changed_at']);
            });
        }

        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $now = now();
        $permissions = [
            ['cases.view', 'مشاهده پرونده‌ها', 'cases', 'پرونده‌ها', 'مشاهده پرونده‌های مراجع در مرکز مجاز', 215],
            ['cases.manage', 'مدیریت پرونده‌ها', 'cases', 'پرونده‌ها', 'ایجاد و ویرایش پرونده‌های مشاوره', 216],
            ['cases.assign', 'تخصیص پرونده‌ها', 'cases', 'پرونده‌ها', 'تخصیص و پایان تخصیص کارکنان به پرونده', 217],
        ];
        foreach ($permissions as [$slug, $name, $groupKey, $groupName, $description, $sortOrder]) {
            DB::table('permissions')->updateOrInsert(['slug' => $slug], [
                'name' => $name, 'group_key' => $groupKey, 'group_name' => $groupName,
                'description' => $description, 'sort_order' => $sortOrder,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $grants = [
            'super_admin' => ['cases.view', 'cases.manage', 'cases.assign'],
            'manager' => ['cases.view', 'cases.manage', 'cases.assign'],
            'secretary' => ['cases.view', 'cases.manage', 'cases.assign'],
            'counselor' => ['cases.view'],
        ];
        foreach ($grants as $roleSlug => $slugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) continue;
            foreach (DB::table('permissions')->whereIn('slug', $slugs)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $slugs = ['cases.view', 'cases.manage', 'cases.assign'];
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        Schema::dropIfExists('case_status_histories');
        Schema::dropIfExists('case_assignments');
        Schema::dropIfExists('cases');
    }
};
