<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\AppointmentSlot;
use App\Models\Client;
use App\Models\Role;
use App\Models\StaffPayrollRun;
use App\Models\SessionRecording;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\AppointmentBookingService;
use App\Services\AudioRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Stage12StabilizationTest extends TestCase
{
    use RefreshDatabase;
    protected $seed=true;

    public function test_calendar_has_real_month_filters_move_and_resize_contracts(): void
    {
        $js=file_get_contents(public_path('js/stage06-scheduler.js'));
        $view=file_get_contents(resource_path('views/appointments/calendar.blade.php'));
        $this->assertStringContainsString("new DayPilot.Month('stage06-month'",$js);
        $this->assertStringContainsString('onEventMove:args=>move(args)',$js);
        $this->assertStringContainsString('onEventResize:args=>move(args,true)',$js);
        $this->assertStringContainsString('data-calendar-filter',$view);
        $this->assertStringContainsString('value="month"',$view);
    }

    public function test_calendar_filters_and_resize_are_enforced_by_feature_api(): void
    {
        config(['appointments.lock_store'=>'array']);
        [$manager,$counselor,$appointment,$start]=$this->calendarFixture();
        $this->actingAs($manager)->getJson(route('appointments.calendar.api.events',['start'=>$start->copy()->startOfDay()->toIso8601String(),'end'=>$start->copy()->addDay()->startOfDay()->toIso8601String(),'counselor_id'=>$counselor->id]))->assertOk()->assertJsonCount(1);
        $newEnd=$start->copy()->addMinutes(75);
        $this->actingAs($manager)->patchJson(route('appointments.calendar.api.update',$appointment),['start'=>$start->toIso8601String(),'end'=>$newEnd->toIso8601String(),'counselor_id'=>$counselor->id])->assertOk();
        $this->assertDatabaseHas('appointments',['id'=>$appointment->id,'duration_minutes'=>75]);
    }

    public function test_payroll_blocks_overlaps_prorates_fixed_salary_and_enforces_four_eye_approval(): void
    {
        $centre=Centre::where('code','ENSHA-MAIN')->firstOrFail();
        $maker=$this->user('manager',$centre->id,'09128881001');
        $approver=$this->user('manager',$centre->id,'09128881002');
        $finance=$this->user('finance',$centre->id,'09128881003');
        $staff=$this->user('counselor',$centre->id,'09128881004');
        $start=now()->startOfMonth();$end=$start->copy()->addDays(9);$days=$start->daysInMonth;
        DB::table('staff_pay_rules')->insert(['centre_id'=>$centre->id,'staff_id'=>$staff->id,'model'=>'fixed','base_amount'=>3000000,'hourly_amount'=>0,'overtime_amount'=>0,'daily_target_minutes'=>480,'valid_from'=>$start->copy()->subYear()->toDateString(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('staff_work_sessions')->insert(['centre_id'=>$centre->id,'staff_id'=>$staff->id,'started_at'=>$start->toDateString().' 08:00:00','ended_at'=>$start->toDateString().' 16:00:00','duration_minutes'=>480,'status'=>'closed','created_at'=>now(),'updated_at'=>now()]);
        $service=app(PayrollService::class);$run=$service->createRun($centre->id,$maker,$start->toDateString(),$end->toDateString());
        $this->assertSame((int)round(3000000*10/$days),(int)$run->items->first()->base_pay_amount);
        try{$service->createRun($centre->id,$maker,$start->copy()->addDays(5)->toDateString(),$end->copy()->addDays(5)->toDateString());$this->fail('overlap accepted');}catch(ValidationException $e){$this->assertArrayHasKey('period_start',$e->errors());}
        $service->submit($run,$maker);$this->assertSame('submitted',$run->fresh()->status);
        $this->expectException(ValidationException::class);$service->approve($run,$maker);
    }

    public function test_payroll_can_be_approved_paid_and_audit_trail_is_immutable(): void
    {
        $centre=Centre::where('code','ENSHA-MAIN')->firstOrFail();$maker=$this->user('manager',$centre->id,'09128882001');$approver=$this->user('manager',$centre->id,'09128882002');$finance=$this->user('finance',$centre->id,'09128882003');$staff=$this->user('counselor',$centre->id,'09128882004');$date=now()->startOfMonth()->toDateString();
        DB::table('staff_pay_rules')->insert(['centre_id'=>$centre->id,'staff_id'=>$staff->id,'model'=>'hourly','base_amount'=>0,'hourly_amount'=>60000,'overtime_amount'=>0,'daily_target_minutes'=>0,'valid_from'=>now()->subYear()->toDateString(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('staff_work_sessions')->insert(['centre_id'=>$centre->id,'staff_id'=>$staff->id,'started_at'=>$date.' 08:00:00','ended_at'=>$date.' 09:00:00','duration_minutes'=>60,'status'=>'closed','created_at'=>now(),'updated_at'=>now()]);
        $service=app(PayrollService::class);$run=$service->createRun($centre->id,$maker,$date,$date);$service->submit($run,$maker);$service->approve($run,$approver);$service->pay($run,$finance,'BANK-TEST-1');
        $this->assertSame('paid',$run->fresh()->status);$this->assertSame(4,DB::table('staff_payroll_run_audits')->where('payroll_run_id',$run->id)->count());
    }

    public function test_wordpress_api_rejects_missing_key_and_sms_whisper_are_centre_scoped(): void
    {
        $centre=Centre::where('code','ENSHA-MAIN')->firstOrFail();
        $this->getJson('/api/v1/centres/'.$centre->id.'/availability?from='.now()->toDateString().'&to='.now()->addDay()->toDateString())->assertUnauthorized();
        $this->assertTrue(DB::table('permissions')->whereIn('slug',['integrations.manage','session_recordings.retention'])->count()===2);
    }

    public function test_audio_retention_respects_legal_hold_then_purges_with_audit(): void
    {
        Storage::fake('local');config(['appointments.lock_store'=>'array']);[$manager,$counselor,$appointment]=$this->calendarFixture();
        $sessionId=DB::table('counselling_sessions')->insertGetId(['appointment_id'=>$appointment->id,'case_id'=>$appointment->case_id,'counselor_id'=>$counselor->id,'session_number'=>1,'status'=>'completed','created_by'=>$counselor->id,'created_at'=>now(),'updated_at'=>now()]);
        Storage::disk('local')->put('ensha-audio/expired.eaudio','cipher');
        $recording=SessionRecording::create(['public_id'=>(string)\Illuminate\Support\Str::uuid(),'centre_id'=>$appointment->centre_id,'appointment_id'=>$appointment->id,'counselling_session_id'=>$sessionId,'client_id'=>$appointment->client_id,'counselor_id'=>$counselor->id,'disk'=>'local','path'=>'ensha-audio/expired.eaudio','status'=>'ready','sha256'=>hash('sha256','cipher'),'completed_at'=>now()->subDays(400),'retention_expires_at'=>now()->subDay(),'legal_hold'=>true,'created_by'=>$counselor->id]);
        $service=app(AudioRetentionService::class);$held=$service->purgeExpired($appointment->centre_id,$manager->id);$this->assertSame(0,$held['eligible']);$this->assertTrue(Storage::disk('local')->exists($recording->path));
        $recording->update(['legal_hold'=>false]);$purged=$service->purgeExpired($appointment->centre_id,$manager->id);$this->assertSame(1,$purged['purged']);$this->assertSame('purged',$recording->fresh()->status);$this->assertDatabaseHas('session_recording_retention_audits',['session_recording_id'=>$recording->id,'action'=>'retention_purged']);
    }

    private function user(string $roleSlug,int $centreId,string $phone): User
    {
        $role=Role::where('slug',$roleSlug)->firstOrFail();$user=User::create(['first_name'=>'آزمون','last_name'=>$roleSlug,'name'=>'آزمون '.$roleSlug,'phone'=>$phone,'password'=>'ValidPass123!','role'=>$roleSlug,'role_id'=>$role->id,'centre_id'=>$centreId,'is_active'=>true,'status'=>'active','must_change_password'=>false]);$user->roleAssignments()->create(['role_id'=>$role->id,'centre_id'=>$centreId]);return $user;
    }

    private function calendarFixture(): array
    {
        $centre=Centre::where('code','ENSHA-MAIN')->firstOrFail();$manager=$this->user('manager',$centre->id,'09128883001');$counselor=$this->user('counselor',$centre->id,'09128883002');$clientUser=$this->user('client',$centre->id,'09128883003');
        $client=Client::create(['user_id'=>$clientUser->id,'centre_id'=>$centre->id,'client_code'=>'CL-S12','status'=>'active']);
        $category=DB::table('service_categories')->insertGetId(['centre_id'=>$centre->id,'name'=>'تقویم Stage12','created_at'=>now(),'updated_at'=>now()]);
        $topic=DB::table('service_topics')->insertGetId(['category_id'=>$category,'name'=>'جلسه تست','minimum_minutes'=>15,'session_minutes'=>60,'break_minutes'=>0,'capacity'=>1,'requires_room'=>false,'is_active'=>true,'price'=>600000,'color'=>'#3366ff','allowed_modes'=>json_encode(['in_person']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('counselor_topics')->insert(['user_id'=>$counselor->id,'topic_id'=>$topic,'centre_id'=>$centre->id,'is_active'=>true]);
        $start=now()->addDays(5)->startOfHour();DB::table('counselor_shifts')->insert(['user_id'=>$counselor->id,'centre_id'=>$centre->id,'weekday'=>$start->dayOfWeek,'starts_at'=>'00:00:00','ends_at'=>'23:59:59','hourly_pay'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $slot=AppointmentSlot::create(['centre_id'=>$centre->id,'topic_id'=>$topic,'counselor_id'=>$counselor->id,'slot_date'=>$start->toDateString(),'starts_at'=>$start,'ends_at'=>$start->copy()->addMinutes(45),'mode'=>'in_person','capacity'=>1,'booked_count'=>0,'status'=>'available']);
        $appointment=app(AppointmentBookingService::class)->book($slot->id,$client->id,null,$manager->id);
        return [$manager,$counselor,$appointment,$start];
    }
}
