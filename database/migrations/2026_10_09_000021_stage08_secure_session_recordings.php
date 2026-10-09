<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('session_recordings')) {
            Schema::create('session_recordings', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
                $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
                $table->foreignId('counselling_session_id')->constrained('counselling_sessions')->cascadeOnDelete();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->foreignId('counselor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('consent_id')->nullable()->constrained('client_consents')->nullOnDelete();
                $table->string('disk', 40)->default('local');
                $table->string('path')->nullable();
                $table->string('original_name')->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('sha256', 64)->nullable();
                $table->string('plaintext_sha256', 64)->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->string('status', 30)->default('uploading')->index();
                $table->unsignedInteger('chunk_count')->nullable();
                $table->unsignedInteger('received_chunks')->default(0);
                $table->string('transcript_status', 30)->default('not_requested')->index();
                $table->longText('transcript_text')->nullable();
                $table->string('transcript_language', 12)->default('fa');
                $table->string('transcription_provider', 80)->nullable();
                $table->text('transcription_error')->nullable();
                $table->timestamp('transcribed_at')->nullable();
                $table->foreignId('transcript_finalized_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('transcript_finalized_at')->nullable();
                $table->string('transcript_hash', 64)->nullable();
                $table->json('consent_snapshot')->nullable();
                $table->timestamp('recorded_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['appointment_id', 'status'], 'session_recordings_appointment_status');
                $table->index(['centre_id', 'counselor_id', 'recorded_at'], 'session_recordings_workspace');
            });
        }

        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $now = now();
        $permissions = [
            ['session_recordings.view', 'شنیدن صوت جلسه', 'clinical_recordings', 'ضبط جلسات', 'پخش صوت جلسات مجاز مشاور', 290],
            ['session_recordings.manage', 'مدیریت ضبط جلسه', 'clinical_recordings', 'ضبط جلسات', 'ثبت رضایت، ضبط، بارگذاری و حذف صوت جلسه', 291],
            ['session_transcripts.manage', 'مدیریت متن صوت جلسه', 'clinical_recordings', 'ضبط جلسات', 'درخواست پیاده‌سازی و اصلاح متن صوت جلسه', 292],
            ['session_recordings.audit', 'ممیزی ضبط جلسات', 'clinical_recordings', 'ضبط جلسات', 'مشاهده فراداده ضبط‌ها بدون دسترسی به محتوای صوت', 293],
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
            'manager' => ['session_recordings.audit'],
            'counselor' => ['session_recordings.view', 'session_recordings.manage', 'session_transcripts.manage'],
        ];
        foreach ($grants as $roleSlug => $slugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->whereIn('slug', $slugs)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $slugs = ['session_recordings.view', 'session_recordings.manage', 'session_transcripts.manage', 'session_recordings.audit'];
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        Schema::dropIfExists('session_recordings');
    }
};
