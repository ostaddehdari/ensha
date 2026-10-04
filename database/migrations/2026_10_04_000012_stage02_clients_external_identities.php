<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->foreignId('centre_id')->nullable()->constrained()->nullOnDelete();
                $table->string('client_code', 60);
                $table->string('status', 30)->default('active')->index();
                $table->date('date_of_birth')->nullable();
                $table->string('gender', 30)->nullable();
                $table->string('preferred_contact', 30)->nullable();
                $table->string('referral_source', 160)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['centre_id', 'client_code']);
                $table->index(['centre_id', 'status']);
            });
        }

        if (! Schema::hasTable('external_identities')) {
            Schema::create('external_identities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 80);
                $table->string('external_id', 190);
                $table->string('external_username', 190)->nullable();
                $table->string('external_email', 190)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('linked_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
                $table->unique(['provider', 'external_id']);
                $table->unique(['client_id', 'provider']);
                $table->index(['provider', 'external_email']);
            });
        }

        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $now = now();
        $permissions = [
            ['clients.view', 'مشاهده مراجعین', 'clients', 'مراجعین', 'مشاهده فهرست و پرونده پایه مراجعین', 205],
            ['clients.manage', 'مدیریت مراجعین', 'clients', 'مراجعین', 'ایجاد و ویرایش پرونده پایه مراجعین و هویت‌های بیرونی', 206],
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
            'super_admin' => ['clients.view', 'clients.manage'],
            'manager' => ['clients.view', 'clients.manage'],
            'secretary' => ['clients.view', 'clients.manage'],
            'counselor' => ['clients.view'],
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
        $slugs = ['clients.view', 'clients.manage'];
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        Schema::dropIfExists('external_identities');
        Schema::dropIfExists('clients');
    }
};
