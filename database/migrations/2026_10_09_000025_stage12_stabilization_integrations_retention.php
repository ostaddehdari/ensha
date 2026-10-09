<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        // This migration may be retried after an interrupted deployment.  Every
        // DDL operation is therefore guarded so a partially-applied attempt is
        // safely completed instead of failing with "already exists".
        $payrollColumns = [
            'submitted_by' => fn (Blueprint $table) => $table->foreignId('submitted_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete(),
            'submitted_at' => fn (Blueprint $table) => $table->timestamp('submitted_at')->nullable()->after('submitted_by'),
            'approved_by' => fn (Blueprint $table) => $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete(),
            'approved_at' => fn (Blueprint $table) => $table->timestamp('approved_at')->nullable()->after('approved_by'),
            'paid_by' => fn (Blueprint $table) => $table->foreignId('paid_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete(),
            'paid_at' => fn (Blueprint $table) => $table->timestamp('paid_at')->nullable()->after('paid_by'),
            'payment_reference' => fn (Blueprint $table) => $table->string('payment_reference', 120)->nullable()->after('paid_at'),
            'voided_by' => fn (Blueprint $table) => $table->foreignId('voided_by')->nullable()->after('payment_reference')->constrained('users')->nullOnDelete(),
            'voided_at' => fn (Blueprint $table) => $table->timestamp('voided_at')->nullable()->after('voided_by'),
            'void_reason' => fn (Blueprint $table) => $table->text('void_reason')->nullable()->after('voided_at'),
            'replacement_run_id' => fn (Blueprint $table) => $table->foreignId('replacement_run_id')->nullable()->after('void_reason')->constrained('staff_payroll_runs')->nullOnDelete(),
        ];
        foreach ($payrollColumns as $column => $definition) {
            if (! Schema::hasColumn('staff_payroll_runs', $column)) {
                Schema::table('staff_payroll_runs', $definition);
            }
        }

        if (! Schema::hasTable('staff_payroll_run_audits')) {
            Schema::create('staff_payroll_run_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained('staff_payroll_runs')->restrictOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 40);
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20)->nullable();
                $table->text('reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['payroll_run_id', 'created_at'], 'payroll_audits_run_date');
            });
        }

        $recordingColumns = [
            'legal_hold' => fn (Blueprint $table) => $table->boolean('legal_hold')->default(false)->after('completed_at'),
            'legal_hold_reason' => fn (Blueprint $table) => $table->text('legal_hold_reason')->nullable()->after('legal_hold'),
            'legal_hold_by' => fn (Blueprint $table) => $table->foreignId('legal_hold_by')->nullable()->after('legal_hold_reason')->constrained('users')->nullOnDelete(),
            'legal_hold_at' => fn (Blueprint $table) => $table->timestamp('legal_hold_at')->nullable()->after('legal_hold_by'),
            'retention_expires_at' => fn (Blueprint $table) => $table->timestamp('retention_expires_at')->nullable()->after('legal_hold_at')->index(),
            'purged_at' => fn (Blueprint $table) => $table->timestamp('purged_at')->nullable()->after('retention_expires_at'),
            'purged_by' => fn (Blueprint $table) => $table->foreignId('purged_by')->nullable()->after('purged_at')->constrained('users')->nullOnDelete(),
            'purge_reason' => fn (Blueprint $table) => $table->string('purge_reason', 180)->nullable()->after('purged_by'),
        ];
        foreach ($recordingColumns as $column => $definition) {
            if (! Schema::hasColumn('session_recordings', $column)) {
                Schema::table('session_recordings', $definition);
            }
        }
        DB::table('session_recordings')->whereNotNull('completed_at')->whereNull('retention_expires_at')->orderBy('id')->chunkById(200,function($rows){
            foreach($rows as $row)DB::table('session_recordings')->where('id',$row->id)->update(['retention_expires_at'=>Carbon::parse($row->completed_at)->addDays(365)]);
        });

        if (! Schema::hasTable('session_recording_retention_audits')) {
            Schema::create('session_recording_retention_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('session_recording_id')->constrained('session_recordings')->restrictOnDelete();
                $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 40);
                $table->text('reason')->nullable();
                $table->string('file_sha256', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('centre_integrations')) {
            Schema::create('centre_integrations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
                $table->string('driver', 40);
                $table->boolean('is_active')->default(false);
                $table->json('settings')->nullable();
                $table->text('secret_payload')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->string('last_status', 20)->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['centre_id', 'driver']);
            });
        }

        $smsColumns = [
            'provider_message_id' => fn (Blueprint $table) => $table->string('provider_message_id', 120)->nullable()->after('provider_response')->index(),
            'attempts' => fn (Blueprint $table) => $table->unsignedTinyInteger('attempts')->default(0)->after('provider_message_id'),
            'last_attempt_at' => fn (Blueprint $table) => $table->timestamp('last_attempt_at')->nullable()->after('attempts'),
            'delivered_at' => fn (Blueprint $table) => $table->timestamp('delivered_at')->nullable()->after('last_attempt_at'),
            'last_error' => fn (Blueprint $table) => $table->text('last_error')->nullable()->after('delivered_at'),
        ];
        foreach ($smsColumns as $column => $definition) {
            if (! Schema::hasColumn('sms_messages', $column)) {
                Schema::table('sms_messages', $definition);
            }
        }

        $this->permissions();
    }

    private function permissions(): void
    {
        $now = now();
        $definitions = [
            ['payroll.submit', 'ارسال دوره حقوق برای تأیید', 'payroll', 'حقوق کارکنان', 360],
            ['payroll.approve', 'تأیید دوره حقوق', 'payroll', 'حقوق کارکنان', 361],
            ['payroll.pay', 'ثبت پرداخت حقوق', 'payroll', 'حقوق کارکنان', 362],
            ['payroll.void', 'ابطال حسابرسی‌شده حقوق', 'payroll', 'حقوق کارکنان', 363],
            ['integrations.manage', 'مدیریت اتصالات مرکز', 'integrations', 'اتصالات خارجی', 364],
            ['session_recordings.retention', 'مدیریت نگهداری صوت', 'clinical_recordings', 'ضبط جلسات', 365],
        ];
        foreach ($definitions as [$slug, $name, $groupKey, $groupName, $sort]) {
            DB::table('permissions')->updateOrInsert(['slug' => $slug], [
                'name' => $name, 'group_key' => $groupKey, 'group_name' => $groupName,
                'description' => $name, 'sort_order' => $sort, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $grants = [
            'super_admin' => array_column($definitions, 0),
            'manager' => ['payroll.submit', 'payroll.approve', 'payroll.void', 'integrations.manage', 'session_recordings.retention'],
            'finance' => ['payroll.submit', 'payroll.pay'],
        ];
        foreach ($grants as $role => $slugs) {
            $roleId = DB::table('roles')->where('slug', $role)->value('id');
            if (! $roleId) continue;
            foreach (DB::table('permissions')->whereIn('slug', $slugs)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['provider_message_id', 'attempts', 'last_attempt_at', 'delivered_at', 'last_error']);
        });
        Schema::dropIfExists('centre_integrations');
        Schema::dropIfExists('session_recording_retention_audits');
        Schema::table('session_recordings', function (Blueprint $table) {
            $table->dropForeign(['legal_hold_by']); $table->dropForeign(['purged_by']);
            $table->dropColumn(['legal_hold', 'legal_hold_reason', 'legal_hold_by', 'legal_hold_at', 'retention_expires_at', 'purged_at', 'purged_by', 'purge_reason']);
        });
        Schema::dropIfExists('staff_payroll_run_audits');
        Schema::table('staff_payroll_runs', function (Blueprint $table) {
            foreach (['submitted_by','approved_by','paid_by','voided_by','replacement_run_id'] as $foreign) $table->dropForeign([$foreign]);
            $table->dropColumn(['submitted_by','submitted_at','approved_by','approved_at','paid_by','paid_at','payment_reference','voided_by','voided_at','void_reason','replacement_run_id']);
        });
        $slugs = ['payroll.submit','payroll.approve','payroll.pay','payroll.void','integrations.manage','session_recordings.retention'];
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
