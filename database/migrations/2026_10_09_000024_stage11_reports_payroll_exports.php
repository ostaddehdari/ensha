<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'finance.analytics.view', 'finance.analytics.export',
        'payroll.view', 'payroll.manage', 'payroll.export',
    ];

    public function up(): void
    {
        Schema::create('financial_report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_key', 48)->index();
            $table->json('filters');
            $table->unsignedInteger('row_count')->default(0);
            $table->string('format', 12)->default('xlsx');
            $table->string('file_name', 180);
            $table->char('sha256', 64);
            $table->timestamp('exported_at');
            $table->timestamps();
            $table->index(['centre_id', 'exported_at'], 'report_exports_centre_date');
        });

        Schema::create('staff_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('run_number', 48)->unique();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('jalali_period', 16)->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedBigInteger('total_payable_amount')->default(0);
            $table->unsignedInteger('total_worked_minutes')->default(0);
            $table->unsignedInteger('staff_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['centre_id', 'period_start', 'period_end'], 'payroll_runs_period_unique');
        });

        Schema::create('staff_payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('staff_payroll_runs')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('pay_rule_id')->nullable()->constrained('staff_pay_rules')->nullOnDelete();
            $table->json('rule_snapshot')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('worked_days')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('shortfall_minutes')->default(0);
            $table->unsignedBigInteger('base_pay_amount')->default(0);
            $table->unsignedBigInteger('hourly_pay_amount')->default(0);
            $table->unsignedBigInteger('overtime_pay_amount')->default(0);
            $table->unsignedBigInteger('payable_amount')->default(0);
            $table->timestamps();
            $table->unique(['payroll_run_id', 'staff_id'], 'payroll_run_staff_unique');
        });

        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $now=now();
        $definitions=[
            ['finance.analytics.view','مشاهده گزارش‌های مالی','reports','گزارش و خروجی','مشاهده درآمد، بدهکاران، سهم‌ها، صندوق و تسویه‌ها',350],
            ['finance.analytics.export','خروجی گزارش‌های مالی','reports','گزارش و خروجی','دریافت فایل XLSX گزارش‌های مالی',351],
            ['payroll.view','مشاهده کارکرد و حقوق','reports','گزارش و خروجی','مشاهده ساعات حضور و محاسبه حقوق کارکنان',352],
            ['payroll.manage','ساخت و قفل دوره حقوق','reports','گزارش و خروجی','ساخت Snapshot حقوق و قفل دوره',353],
            ['payroll.export','خروجی کارکرد و حقوق','reports','گزارش و خروجی','دریافت فایل XLSX کارکرد و حقوق',354],
        ];
        foreach($definitions as [$slug,$name,$groupKey,$groupName,$description,$sortOrder]) {
            DB::table('permissions')->updateOrInsert(['slug'=>$slug],[
                'name'=>$name,'group_key'=>$groupKey,'group_name'=>$groupName,'description'=>$description,
                'sort_order'=>$sortOrder,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
        $grants=[
            'super_admin'=>$this->permissions,
            'manager'=>$this->permissions,
            'finance'=>['finance.analytics.view','finance.analytics.export','payroll.view','payroll.export','attendance.view'],
        ];
        foreach($grants as $roleSlug=>$slugs) {
            $roleId=DB::table('roles')->where('slug',$roleSlug)->value('id');
            if(!$roleId) continue;
            foreach(DB::table('permissions')->whereIn('slug',$slugs)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id'=>$roleId,'permission_id'=>$permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_payroll_items');
        Schema::dropIfExists('staff_payroll_runs');
        Schema::dropIfExists('financial_report_exports');
        $ids=DB::table('permissions')->whereIn('slug',$this->permissions)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id',$ids)->delete();
        DB::table('permissions')->whereIn('id',$ids)->delete();
    }
};
