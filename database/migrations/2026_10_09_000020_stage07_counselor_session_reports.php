<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('counselling_sessions', 'appointment_id')) {
            Schema::table('counselling_sessions', function (Blueprint $table) {
                $table->foreignId('appointment_id')->nullable()->after('id')->constrained('appointments')->nullOnDelete();
                $table->unique('appointment_id', 'counselling_sessions_appointment_unique');
            });
        }

        if (! Schema::hasTable('session_report_templates')) {
            Schema::create('session_report_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
                $table->foreignId('topic_id')->nullable()->constrained('service_topics')->nullOnDelete();
                $table->string('name', 150);
                $table->unsignedSmallInteger('version')->default(1);
                $table->json('fields');
                $table->boolean('is_default')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['centre_id', 'topic_id', 'name', 'version'], 'session_template_version_unique');
                $table->index(['centre_id', 'topic_id', 'is_active'], 'session_template_lookup');
            });
        }

        if (! Schema::hasTable('session_reports')) {
            Schema::create('session_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
                $table->foreignId('appointment_id')->unique()->constrained('appointments')->cascadeOnDelete();
                $table->foreignId('counselling_session_id')->unique()->constrained('counselling_sessions')->cascadeOnDelete();
                $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->foreignId('counselor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('template_id')->nullable()->constrained('session_report_templates')->nullOnDelete();
                $table->string('template_name_snapshot', 150)->nullable();
                $table->unsignedSmallInteger('template_version_snapshot')->nullable();
                $table->string('status', 20)->default('draft')->index();
                $table->longText('summary')->nullable();
                $table->longText('outcome')->nullable();
                $table->longText('recommendations')->nullable();
                $table->longText('structured_answers')->nullable();
                $table->boolean('follow_up_required')->default(false);
                $table->dateTime('follow_up_at')->nullable();
                $table->string('content_hash', 64)->nullable();
                $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['centre_id', 'counselor_id', 'status'], 'session_reports_workspace');
            });
        }

        if (! Schema::hasTable('session_report_addenda')) {
            Schema::create('session_report_addenda', function (Blueprint $table) {
                $table->id();
                $table->foreignId('session_report_id')->constrained()->cascadeOnDelete();
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->longText('body');
                $table->string('content_hash', 64);
                $table->timestamps();
                $table->index(['session_report_id', 'created_at'], 'session_report_addenda_timeline');
            });
        }

        $this->seedPermissions();
        $this->seedDefaultTemplates();
    }

    private function seedPermissions(): void
    {
        $now = now();
        $permissions = [
            ['session_reports.view', 'مشاهده گزارش جلسه', 'clinical_reports', 'گزارش جلسه', 'مشاهده گزارش بالینی نوبت‌های مجاز', 280],
            ['session_reports.manage', 'ثبت گزارش جلسه', 'clinical_reports', 'گزارش جلسه', 'ثبت، تکمیل و نهایی‌سازی گزارش بالینی', 281],
            ['session_report_templates.manage', 'مدیریت فرم گزارش جلسه', 'clinical_reports', 'گزارش جلسه', 'تعریف فرم‌های متنی و تیک‌زدنی هر مرکز', 282],
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
            'manager' => ['session_reports.view', 'session_report_templates.manage'],
            'counselor' => ['session_reports.view', 'session_reports.manage'],
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

    private function seedDefaultTemplates(): void
    {
        $fields = [
            ['key' => 'presenting_issue_reviewed', 'label' => 'موضوع اصلی جلسه بررسی شد', 'type' => 'checkbox', 'required' => false, 'options' => []],
            ['key' => 'goals_reviewed', 'label' => 'اهداف درمانی مرور شد', 'type' => 'checkbox', 'required' => false, 'options' => []],
            ['key' => 'risk_assessed', 'label' => 'ارزیابی خطر انجام شد', 'type' => 'checkbox', 'required' => false, 'options' => []],
            ['key' => 'homework_assigned', 'label' => 'تمرین یا تکلیف تعیین شد', 'type' => 'checkbox', 'required' => false, 'options' => []],
            ['key' => 'session_result', 'label' => 'نتیجه کلی جلسه', 'type' => 'select', 'required' => true, 'options' => ['پیشرفت مناسب', 'نیازمند پیگیری', 'بدون تغییر محسوس', 'ارجاع به متخصص دیگر']],
            ['key' => 'next_session_focus', 'label' => 'محور پیشنهادی جلسه بعد', 'type' => 'textarea', 'required' => false, 'options' => []],
        ];
        $now = now();
        foreach (DB::table('centres')->pluck('id') as $centreId) {
            DB::table('session_report_templates')->insertOrIgnore([
                'centre_id' => $centreId,
                'topic_id' => null,
                'name' => 'فرم عمومی گزارش جلسه',
                'version' => 1,
                'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'is_default' => true,
                'is_active' => true,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $slugs = ['session_reports.view', 'session_reports.manage', 'session_report_templates.manage'];
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        Schema::dropIfExists('session_report_addenda');
        Schema::dropIfExists('session_reports');
        Schema::dropIfExists('session_report_templates');
        if (Schema::hasColumn('counselling_sessions', 'appointment_id')) {
            Schema::table('counselling_sessions', function (Blueprint $table) {
                $table->dropForeign(['appointment_id']);
                $table->dropUnique('counselling_sessions_appointment_unique');
                $table->dropColumn('appointment_id');
            });
        }
    }
};
