<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\Role;
use App\Models\User;
use App\Services\JalaliDate;
use App\Services\PayrollService;
use App\Services\SimpleXlsxExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Stage11ReportsPayrollTest extends TestCase
{
    use RefreshDatabase;
    protected $seed=true;

    public function test_jalali_month_conversion_is_stable(): void
    {
        $jalali=app(JalaliDate::class);
        $this->assertSame('1405/01/01',$jalali->format('2026-03-21'));
        [$gy,$gm,$gd]=$jalali->toGregorian(1405,1,1);
        $this->assertSame([2026,3,21],[$gy,$gm,$gd]);
    }

    public function test_payroll_run_freezes_attendance_and_rule_amounts(): void
    {
        $centre=Centre::where('code','ENSHA-MAIN')->firstOrFail();
        $manager=$this->user('manager',$centre->id,'09126660101');
        $staff=$this->user('counselor',$centre->id,'09126660102');
        $date=now()->startOfMonth()->addDay()->toDateString();
        $ruleId=DB::table('staff_pay_rules')->insertGetId(['centre_id'=>$centre->id,'staff_id'=>$staff->id,'model'=>'hourly','base_amount'=>0,'hourly_amount'=>60000,'overtime_amount'=>90000,'daily_target_minutes'=>60,'valid_from'=>now()->subYear()->toDateString(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('staff_work_sessions')->insert(['centre_id'=>$centre->id,'staff_id'=>$staff->id,'started_at'=>$date.' 08:00:00','ended_at'=>$date.' 10:00:00','duration_minutes'=>120,'status'=>'closed','created_at'=>now(),'updated_at'=>now()]);
        $run=app(PayrollService::class)->createRun($centre->id,$manager,$date,$date);
        $item=$run->items->first();
        $this->assertSame(120000,(int)$item->payable_amount);
        $this->assertSame(60,(int)$item->overtime_minutes);
        DB::table('staff_pay_rules')->where('id',$ruleId)->update(['hourly_amount'=>999999]);
        $this->assertSame(120000,(int)$item->fresh()->payable_amount);
        app(PayrollService::class)->lock($run,$manager);
        $this->assertSame('locked',$run->fresh()->status);
    }

    public function test_xlsx_export_is_a_real_zip_based_workbook(): void
    {
        if(!class_exists(\ZipArchive::class)) $this->markTestSkipped('zip extension unavailable');
        $file=app(SimpleXlsxExporter::class)->create('گزارش',['نام','مبلغ'],[['نمونه',125000]]);
        $this->assertSame('PK',file_get_contents($file['path'],false,null,0,2));
        $zip=new \ZipArchive(); $this->assertTrue($zip->open($file['path'])===true);
        $this->assertNotFalse($zip->getFromName('xl/worksheets/sheet1.xml')); $zip->close(); unlink($file['path']);
    }

    private function user(string $roleSlug,int $centreId,string $phone): User
    {
        $role=Role::where('slug',$roleSlug)->firstOrFail();
        $user=User::create(['first_name'=>'کاربر','last_name'=>$role->name,'name'=>'کاربر '.$role->name,'phone'=>$phone,'password'=>'ValidPass123!','role'=>$roleSlug,'role_id'=>$role->id,'centre_id'=>$centreId,'is_active'=>true,'status'=>'active','must_change_password'=>false]);
        $user->roleAssignments()->create(['role_id'=>$role->id,'centre_id'=>$centreId]); return $user;
    }
}
