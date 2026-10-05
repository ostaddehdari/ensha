<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'merged_into_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignId('merged_into_id')->nullable()->after('status')->constrained('clients')->nullOnDelete();
                $table->timestamp('merged_at')->nullable()->after('merged_into_id');
                $table->foreignId('merged_by')->nullable()->after('merged_at')->constrained('users')->nullOnDelete();
                $table->index(['centre_id', 'merged_into_id']);
            });
        }

        if (! Schema::hasTable('client_intakes')) {
            Schema::create('client_intakes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
                $table->string('intake_number', 60);
                $table->date('intake_date')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->string('risk_level', 20)->default('unknown')->index();
                $table->string('referral_source', 190)->nullable();
                $table->text('presenting_concern')->nullable();
                $table->text('medical_notes')->nullable();
                $table->text('safeguarding_notes')->nullable();
                $table->json('answers')->nullable();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['client_id', 'intake_number']);
                $table->index(['client_id', 'status']);
            });
        }

        if (! Schema::hasTable('client_guardians')) {
            Schema::create('client_guardians', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('full_name', 190);
                $table->string('relationship', 80);
                $table->string('phone', 32);
                $table->string('national_id', 32)->nullable();
                $table->boolean('is_primary')->default(false)->index();
                $table->boolean('has_legal_authority')->default(false);
                $table->text('verification_notes')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->index(['client_id', 'phone']);
            });
        }

        if (! Schema::hasTable('emergency_contacts')) {
            Schema::create('emergency_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('full_name', 190);
                $table->string('relationship', 80);
                $table->string('phone', 32);
                $table->string('alternate_phone', 32)->nullable();
                $table->unsignedTinyInteger('priority')->default(1);
                $table->boolean('authorized_for_contact')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['client_id', 'priority']);
            });
        }

        if (! Schema::hasTable('client_consents')) {
            Schema::create('client_consents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
                $table->string('consent_type', 80);
                $table->string('document_version', 40)->default('1.0');
                $table->boolean('is_granted')->default(true)->index();
                $table->timestamp('granted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->date('expires_at')->nullable();
                $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('evidence')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['client_id', 'consent_type']);
            });
        }

        if (! Schema::hasTable('counselling_sessions')) {
            Schema::create('counselling_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
                $table->foreignId('counselor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('session_number');
                $table->dateTime('scheduled_at')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->unsignedSmallInteger('duration_minutes')->nullable();
                $table->string('channel', 30)->default('in_person');
                $table->string('status', 30)->default('scheduled')->index();
                $table->text('administrative_summary')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['case_id', 'session_number']);
                $table->index(['counselor_id', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('confidential_notes')) {
            Schema::create('confidential_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
                $table->foreignId('session_id')->nullable()->constrained('counselling_sessions')->cascadeOnDelete();
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->longText('body');
                $table->string('status', 20)->default('draft')->index();
                $table->string('content_hash', 64)->nullable();
                $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['case_id', 'status']);
            });
        }

        if (! Schema::hasTable('note_addenda')) {
            Schema::create('note_addenda', function (Blueprint $table) {
                $table->id();
                $table->foreignId('confidential_note_id')->constrained()->cascadeOnDelete();
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->string('content_hash', 64);
                $table->timestamps();
                $table->index(['confidential_note_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('private_files')) {
            Schema::create('private_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk', 40)->default('local');
                $table->string('path', 500);
                $table->string('original_name', 255);
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('sha256', 64);
                $table->string('classification', 40)->default('confidential')->index();
                $table->string('scan_status', 30)->default('pending')->index();
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['client_id', 'case_id']);
            });
        }

        if (! Schema::hasTable('client_merge_records')) {
            Schema::create('client_merge_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('source_client_id')->constrained('clients')->restrictOnDelete();
                $table->foreignId('target_client_id')->constrained('clients')->restrictOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 30)->default('completed')->index();
                $table->text('reason');
                $table->json('merge_summary')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();
                $table->index(['source_client_id', 'target_client_id']);
            });
        }

        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $now = now();
        $permissions = [
            ['intakes.view', 'مشاهده پذیرش مراجع', 'client_records', 'پرونده مراجع', 'مشاهده پذیرش، ولی، تماس اضطراری و رضایت‌ها', 220],
            ['intakes.manage', 'مدیریت پذیرش مراجع', 'client_records', 'پرونده مراجع', 'ثبت و ویرایش پذیرش و رضایت‌ها', 221],
            ['sessions.view', 'مشاهده جلسات', 'clinical_records', 'جلسات محرمانه', 'مشاهده جلسات پرونده‌های مجاز', 222],
            ['sessions.manage', 'مدیریت جلسات', 'clinical_records', 'جلسات محرمانه', 'ثبت و به‌روزرسانی جلسات', 223],
            ['notes.view', 'مشاهده یادداشت محرمانه', 'clinical_records', 'جلسات محرمانه', 'مشاهده یادداشت محرمانه پرونده‌های مجاز', 224],
            ['notes.manage', 'مدیریت یادداشت محرمانه', 'clinical_records', 'جلسات محرمانه', 'ثبت پیش‌نویس، نهایی‌سازی و الحاقیه', 225],
            ['private_files.view', 'مشاهده فایل خصوصی', 'private_files', 'فایل‌های خصوصی', 'مشاهده و دریافت فایل‌های خصوصی پرونده', 226],
            ['private_files.manage', 'مدیریت فایل خصوصی', 'private_files', 'فایل‌های خصوصی', 'بارگذاری و حذف فایل خصوصی', 227],
            ['clients.duplicates', 'بررسی مراجع تکراری', 'clients', 'مراجعین', 'مشاهده نامزدهای پرونده تکراری', 228],
            ['clients.merge', 'ادغام کنترل‌شده مراجع', 'clients', 'مراجعین', 'ادغام کنترل‌شده و ثبت ممیزی مراجع', 229],
        ];
        foreach ($permissions as [$slug, $name, $groupKey, $groupName, $description, $sortOrder]) {
            DB::table('permissions')->updateOrInsert(['slug' => $slug], [
                'name' => $name, 'group_key' => $groupKey, 'group_name' => $groupName,
                'description' => $description, 'sort_order' => $sortOrder,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $grants = [
            'super_admin' => array_column($permissions, 0),
            'manager' => array_column($permissions, 0),
            'secretary' => ['intakes.view', 'intakes.manage', 'sessions.view', 'sessions.manage', 'private_files.view', 'private_files.manage', 'clients.duplicates'],
            'counselor' => ['intakes.view', 'sessions.view', 'sessions.manage', 'notes.view', 'notes.manage', 'private_files.view'],
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
        $slugs = ['intakes.view', 'intakes.manage', 'sessions.view', 'sessions.manage', 'notes.view', 'notes.manage', 'private_files.view', 'private_files.manage', 'clients.duplicates', 'clients.merge'];
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        Schema::dropIfExists('client_merge_records');
        Schema::dropIfExists('private_files');
        Schema::dropIfExists('note_addenda');
        Schema::dropIfExists('confidential_notes');
        Schema::dropIfExists('counselling_sessions');
        Schema::dropIfExists('client_consents');
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('client_guardians');
        Schema::dropIfExists('client_intakes');
        if (Schema::hasColumn('clients', 'merged_into_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropForeign(['merged_into_id']);
                $table->dropForeign(['merged_by']);
                $table->dropIndex(['centre_id', 'merged_into_id']);
                $table->dropColumn(['merged_into_id', 'merged_at', 'merged_by']);
            });
        }
    }
};
