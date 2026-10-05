<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A previous deployment may have completed this migration before its
        // verification failed. The old rollback restored the migrations table
        // but left newly created tables behind. Reuse only complete tables.
        foreach (['discounts'=>['centre_id','type','value'], 'appointment_statuses'=>['centre_id','slug','blocks_slot'],
            'counselor_leave_requests'=>['centre_id','counselor_id','starts_at'],
            'staff_work_sessions'=>['centre_id','staff_id','started_at'],
            'staff_work_session_audits'=>['session_id','actor_id'],
            'staff_pay_rules'=>['centre_id','staff_id','model'],
            'client_record_settings'=>['centre_id','draft_on'],
            'client_profile_field_permissions'=>['centre_id','profile_field_id','role']] as $table => $columns) {
            if (Schema::hasTable($table)) {
                foreach ($columns as $column) {
                    if (!Schema::hasColumn($table, $column)) {
                        throw new \RuntimeException("Stage 06 leftover table {$table} is missing {$column}");
                    }
                }
            }
        }
        if (!Schema::hasTable('discounts')) Schema::create('discounts', function (Blueprint $t) {
            $t->id(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120); $t->string('type', 12); $t->unsignedBigInteger('value');
            $t->dateTime('starts_at')->nullable(); $t->dateTime('ends_at')->nullable();
            $t->unsignedBigInteger('minimum_amount')->default(0); $t->unsignedBigInteger('maximum_discount')->nullable();
            $t->boolean('is_active')->default(true); $t->timestamps(); $t->index(['centre_id','is_active']);
        });
        if (!Schema::hasTable('appointment_statuses')) Schema::create('appointment_statuses', function (Blueprint $t) {
            $t->id(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80); $t->string('slug', 30);
            $t->char('indicator_color', 7)->default('#ffffff'); $t->char('text_color', 7)->default('#111827');
            $t->unsignedSmallInteger('sort_order')->default(0); $t->boolean('is_initial')->default(false);
            $t->boolean('is_final')->default(false); $t->boolean('blocks_slot')->default(true);
            $t->boolean('is_active')->default(true); $t->timestamps(); $t->unique(['centre_id','slug']);
        });
        Schema::table('counselor_topics', function (Blueprint $t) {
            $t->foreignId('centre_id')->nullable()->constrained()->cascadeOnDelete();
            $t->boolean('is_active')->default(true); $t->unsignedBigInteger('price_override')->nullable();
            $t->unsignedSmallInteger('duration_override')->nullable(); $t->date('valid_from')->nullable(); $t->date('valid_until')->nullable();
        });
        Schema::table('clients', function (Blueprint $t) { $t->string('profile_state', 20)->default('complete')->index(); });
        Schema::table('appointments', function (Blueprint $t) {
            $t->char('public_id', 13)->nullable()->unique(); $t->string('source', 20)->default('system');
            $t->unsignedSmallInteger('duration_minutes')->nullable();
            $t->unsignedBigInteger('base_price')->default(0); $t->unsignedBigInteger('final_price')->default(0);
            $t->unsignedBigInteger('paid_amount')->default(0); $t->unsignedBigInteger('balance_amount')->default(0);
            $t->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('discount_value_snapshot')->default(0); $t->string('discount_type_snapshot', 12)->nullable();
            $t->string('topic_name_snapshot', 120)->nullable(); $t->char('topic_color_snapshot', 7)->nullable();
            $t->unsignedBigInteger('unit_price_snapshot')->nullable(); $t->foreignId('status_id')->nullable()->constrained('appointment_statuses')->nullOnDelete();
            $t->string('status_snapshot', 80)->nullable(); $t->text('payment_note')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        if (!Schema::hasTable('counselor_leave_requests')) Schema::create('counselor_leave_requests', function (Blueprint $t) {
            $t->id(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained('centre_branches')->nullOnDelete();
            $t->foreignId('counselor_id')->constrained('users')->cascadeOnDelete();
            $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->text('reason')->nullable();
            $t->string('status', 16)->default('pending'); $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable(); $t->text('review_note')->nullable(); $t->timestamps();
            $t->index(['centre_id','status','starts_at']);
        });
        if (!Schema::hasTable('staff_work_sessions')) Schema::create('staff_work_sessions', function (Blueprint $t) {
            $t->id(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained('centre_branches')->nullOnDelete();
            $t->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $t->dateTime('started_at'); $t->dateTime('ended_at')->nullable();
            $t->string('started_ip', 45)->nullable(); $t->string('ended_ip', 45)->nullable();
            $t->string('device_info', 255)->nullable(); $t->unsignedInteger('duration_minutes')->nullable();
            $t->string('status', 20)->default('open'); $t->text('notes')->nullable(); $t->timestamps();
            $t->index(['staff_id','status']); $t->index(['centre_id','started_at']);
        });
        if (!Schema::hasTable('staff_work_session_audits')) Schema::create('staff_work_session_audits', function (Blueprint $t) {
            $t->id(); $t->foreignId('session_id')->constrained('staff_work_sessions')->cascadeOnDelete();
            $t->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $t->dateTime('old_start'); $t->dateTime('old_end')->nullable();
            $t->dateTime('new_start'); $t->dateTime('new_end'); $t->text('reason'); $t->timestamp('created_at');
        });
        if (!Schema::hasTable('staff_pay_rules')) Schema::create('staff_pay_rules', function (Blueprint $t) {
            $t->id(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $t->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $t->string('model', 24); $t->unsignedBigInteger('base_amount')->default(0);
            $t->unsignedBigInteger('hourly_amount')->default(0); $t->unsignedBigInteger('overtime_amount')->default(0);
            $t->unsignedSmallInteger('daily_target_minutes')->nullable(); $t->time('shift_starts_at')->nullable(); $t->time('shift_ends_at')->nullable();
            $t->date('valid_from'); $t->date('valid_until')->nullable(); $t->timestamps();
        });
        if (!Schema::hasTable('client_record_settings')) Schema::create('client_record_settings', function (Blueprint $t) {
            $t->foreignId('centre_id')->primary()->constrained()->cascadeOnDelete();
            $t->string('draft_on', 20)->default('booking'); $t->string('complete_required_on', 20)->default('never');
            $t->json('completer_roles')->nullable(); $t->timestamps();
        });
        if (!Schema::hasTable('client_profile_field_permissions')) Schema::create('client_profile_field_permissions', function (Blueprint $t) {
            $t->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $t->foreignId('profile_field_id')->constrained()->cascadeOnDelete();
            $t->string('role', 20); $t->boolean('can_view')->default(false); $t->boolean('can_edit')->default(false);
            $t->primary(['centre_id','profile_field_id','role'], 'client_field_role_primary');
        });
        $now = now();
        foreach (DB::table('centres')->pluck('id') as $centreId) {
            foreach ([['pending','نوبت','#ffffff',true,false,true],['arrived','آمد','#16a34a',false,false,true],['no_show','نیامد','#111827',false,true,false],['cancelled','کنسل کرد','#dc2626',false,true,false]] as $order => [$slug,$name,$color,$initial,$final,$blocks]) {
                DB::table('appointment_statuses')->insertOrIgnore(['centre_id'=>$centreId,'slug'=>$slug,'name'=>$name,'indicator_color'=>$color,'text_color'=>'#111827','sort_order'=>$order,'is_initial'=>$initial,'is_final'=>$final,'blocks_slot'=>$blocks,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
            }
        }
        DB::table('appointments')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $topic = DB::table('service_topics')->find($row->topic_id);
                $status = DB::table('appointment_statuses')->where('centre_id',$row->centre_id)->where('slug',$row->status)->first();
                DB::table('appointments')->where('id',$row->id)->update([
                    'public_id' => $this->publicId(), 'duration_minutes' => max(1,(int) round((strtotime($row->ends_at)-strtotime($row->starts_at))/60)),
                    'base_price'=>$row->price,'final_price'=>$row->price,'balance_amount'=>$row->price,
                    'topic_name_snapshot'=>$topic?->name,'topic_color_snapshot'=>$topic?->color,'unit_price_snapshot'=>$row->price,
                    'status_id'=>$status?->id,'status_snapshot'=>$status?->name,
                ]);
            }
        });
        foreach (['discounts.manage','appointment_statuses.manage','leave_requests.manage','attendance.view','attendance.manage','client_records.manage'] as $i => $slug) {
            DB::table('permissions')->updateOrInsert(['slug'=>$slug],['name'=>$slug,'group_key'=>'stage06','group_name'=>'عملیات نوبت‌دهی','description'=>$slug,'sort_order'=>240+$i,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
            foreach (['super_admin','manager'] as $role) {
                $roleId=DB::table('roles')->where('slug',$role)->value('id'); $permissionId=DB::table('permissions')->where('slug',$slug)->value('id');
                if ($roleId) DB::table('permission_role')->insertOrIgnore(['role_id'=>$roleId,'permission_id'=>$permissionId]);
            }
        }
    }
    private function publicId(): string
    {
        do { $id = (string) random_int(1000000000000, 9999999999999); }
        while (DB::table('appointments')->where('public_id',$id)->exists());
        return $id;
    }
    public function down(): void
    {
        foreach (['client_profile_field_permissions','client_record_settings','staff_pay_rules','staff_work_session_audits','staff_work_sessions','counselor_leave_requests'] as $table) Schema::dropIfExists($table);
        Schema::table('appointments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('discount_id'); $t->dropConstrainedForeignId('status_id'); $t->dropConstrainedForeignId('updated_by');
            $t->dropColumn(['public_id','source','duration_minutes','base_price','final_price','paid_amount','balance_amount','discount_value_snapshot','discount_type_snapshot','topic_name_snapshot','topic_color_snapshot','unit_price_snapshot','status_snapshot','payment_note']);
        });
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('profile_state'));
        Schema::table('counselor_topics', function (Blueprint $t) { $t->dropConstrainedForeignId('centre_id'); $t->dropColumn(['is_active','price_override','duration_override','valid_from','valid_until']); });
        Schema::dropIfExists('appointment_statuses'); Schema::dropIfExists('discounts');
    }
};
