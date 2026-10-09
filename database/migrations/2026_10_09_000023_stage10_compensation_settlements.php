<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $permissions = [
        'compensation_rules.view', 'compensation_rules.manage', 'compensation_snapshots.view',
        'settlements.view', 'settlements.manage', 'settlements.approve', 'settlements.pay',
    ];

    public function up(): void
    {
        Schema::create('compensation_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('service_topics')->restrictOnDelete();
            $table->foreignId('counselor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('beneficiary', 16)->default('counselor');
            $table->string('calculation_type', 16);
            $table->unsignedBigInteger('value');
            $table->unsignedInteger('version');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['centre_id', 'counselor_id', 'topic_id', 'valid_from'], 'compensation_rules_resolution');
        });

        Schema::create('appointment_compensation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('appointment_id')->unique()->constrained('appointments')->restrictOnDelete();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counselor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('service_topics')->nullOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('compensation_rules')->restrictOnDelete();
            $table->json('rule_snapshot');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('collected_at_snapshot')->default(0);
            $table->unsignedBigInteger('outstanding_at_snapshot')->default(0);
            $table->unsignedBigInteger('centre_share_amount');
            $table->unsignedBigInteger('counselor_share_amount');
            $table->char('currency', 3)->default('IRR');
            $table->dateTime('calculated_at');
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('locked_at');
            $table->timestamps();
            $table->index(['centre_id', 'counselor_id', 'calculated_at'], 'compensation_snapshots_counselor_date');
        });

        Schema::create('counselor_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('settlement_number', 48)->unique();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counselor_id')->constrained('users')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedBigInteger('gross_collected_amount')->default(0);
            $table->unsignedBigInteger('centre_share_amount')->default(0);
            $table->unsignedBigInteger('counselor_share_amount')->default(0);
            $table->unsignedBigInteger('deductions_amount')->default(0);
            $table->unsignedBigInteger('bonuses_amount')->default(0);
            $table->unsignedBigInteger('payable_amount')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->char('currency', 3)->default('IRR');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['centre_id', 'counselor_id', 'period_start', 'period_end'], 'counselor_settlements_period');
        });

        Schema::create('counselor_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('counselor_settlements')->restrictOnDelete();
            $table->foreignId('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->foreignId('compensation_snapshot_id')->constrained('appointment_compensation_snapshots')->restrictOnDelete();
            $table->unsignedBigInteger('collected_amount');
            $table->unsignedBigInteger('centre_share_amount');
            $table->unsignedBigInteger('counselor_share_amount');
            $table->json('calculation_snapshot');
            $table->timestamps();
            $table->unique(['settlement_id', 'appointment_id'], 'settlement_appointment_unique');
            $table->index(['appointment_id', 'settlement_id'], 'settlement_items_appointment');
        });

        Schema::create('counselor_settlement_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('counselor_settlements')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('title', 150);
            $table->unsignedBigInteger('amount');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->seedPermissions();
        $this->seedDefaultRules();
        $this->backfillCompletedAppointments();
    }

    private function seedPermissions(): void
    {
        $now = now();
        $definitions = [
            ['compensation_rules.view', 'مشاهده قوانین سهم', 'settlements', 'سهم و تسویه مشاور', 'مشاهده قوانین نسخه‌دار سهم مرکز و مشاور', 330],
            ['compensation_rules.manage', 'مدیریت قوانین سهم', 'settlements', 'سهم و تسویه مشاور', 'ایجاد نسخه جدید و پایان اعتبار قوانین سهم', 331],
            ['compensation_snapshots.view', 'مشاهده Snapshot سهم', 'settlements', 'سهم و تسویه مشاور', 'مشاهده سهم قفل‌شده جلسات تکمیل‌شده', 332],
            ['settlements.view', 'مشاهده تسویه‌ها', 'settlements', 'سهم و تسویه مشاور', 'مشاهده دوره‌ها و اقلام تسویه', 333],
            ['settlements.manage', 'ساخت تسویه', 'settlements', 'سهم و تسویه مشاور', 'ساخت پیش‌نویس تسویه و کسورات', 334],
            ['settlements.approve', 'تأیید تسویه', 'settlements', 'سهم و تسویه مشاور', 'تأیید مدیریتی تسویه مشاور', 335],
            ['settlements.pay', 'ثبت پرداخت تسویه', 'settlements', 'سهم و تسویه مشاور', 'ثبت پرداخت تسویه تأییدشده', 336],
        ];
        foreach ($definitions as [$slug,$name,$groupKey,$groupName,$description,$sortOrder]) {
            DB::table('permissions')->updateOrInsert(['slug'=>$slug],[
                'name'=>$name,'group_key'=>$groupKey,'group_name'=>$groupName,'description'=>$description,
                'sort_order'=>$sortOrder,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
        $all = array_column($definitions, 0);
        $grants = [
            'super_admin'=>$all,
            'manager'=>$all,
            'finance'=>['compensation_rules.view','compensation_snapshots.view','settlements.view','settlements.manage','settlements.pay'],
            'counselor'=>['settlements.view'],
        ];
        foreach ($grants as $roleSlug=>$slugs) {
            $roleId=DB::table('roles')->where('slug',$roleSlug)->value('id');
            if (!$roleId) continue;
            foreach (DB::table('permissions')->whereIn('slug',$slugs)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id'=>$roleId,'permission_id'=>$permissionId]);
            }
        }
    }

    private function seedDefaultRules(): void
    {
        foreach (DB::table('centres')->pluck('id') as $centreId) {
            DB::table('compensation_rules')->insert([
                'public_id'=>(string)Str::uuid(),'centre_id'=>$centreId,'name'=>'قانون پیش‌فرض سهم مشاور',
                'beneficiary'=>'counselor','calculation_type'=>'percentage','value'=>7000,'version'=>1,
                'valid_from'=>'2000-01-01','is_active'=>true,'note'=>'سهم پیش‌فرض ۷۰٪ مشاور؛ قابل جایگزینی با نسخه جدید',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    }

    private function backfillCompletedAppointments(): void
    {
        DB::table('appointments')->where('status','completed')->orderBy('id')->chunkById(200,function($appointments){
            foreach($appointments as $appointment) {
                if (DB::table('appointment_compensation_snapshots')->where('appointment_id',$appointment->id)->exists()) continue;
                $gross=(int)$appointment->final_price;
                $legacyPay=min($gross,max(0,(int)$appointment->counselor_pay));
                $counselorShare=$legacyPay>0?$legacyPay:(int)floor($gross*0.70);
                $rule=DB::table('compensation_rules')->where('centre_id',$appointment->centre_id)->whereNull('topic_id')->whereNull('counselor_id')->first();
                DB::table('appointment_compensation_snapshots')->insert([
                    'public_id'=>(string)Str::uuid(),'appointment_id'=>$appointment->id,'centre_id'=>$appointment->centre_id,
                    'counselor_id'=>$appointment->counselor_id,'topic_id'=>$appointment->topic_id,'rule_id'=>$legacyPay>0?null:$rule?->id,
                    'rule_snapshot'=>json_encode($legacyPay>0?[
                        'source'=>'legacy_tariff','beneficiary'=>'counselor','calculation_type'=>'fixed','value'=>$legacyPay,
                    ]:[
                        'source'=>'stage10_default','name'=>$rule?->name,'version'=>$rule?->version,'beneficiary'=>'counselor','calculation_type'=>'percentage','value'=>7000,
                    ],JSON_UNESCAPED_UNICODE),
                    'gross_amount'=>$gross,'discount_amount'=>max(0,(int)$appointment->base_price-$gross),
                    'collected_at_snapshot'=>(int)$appointment->paid_amount,'outstanding_at_snapshot'=>(int)$appointment->balance_amount,
                    'centre_share_amount'=>max(0,$gross-$counselorShare),'counselor_share_amount'=>$counselorShare,
                    'currency'=>$appointment->currency?:'IRR','calculated_at'=>$appointment->session_ended_at?:($appointment->updated_at?:now()),
                    'locked_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counselor_settlement_adjustments');
        Schema::dropIfExists('counselor_settlement_items');
        Schema::dropIfExists('counselor_settlements');
        Schema::dropIfExists('appointment_compensation_snapshots');
        Schema::dropIfExists('compensation_rules');
        $ids=DB::table('permissions')->whereIn('slug',$this->permissions)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id',$ids)->delete();
        DB::table('permissions')->whereIn('id',$ids)->delete();
    }
};
