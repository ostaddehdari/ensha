<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Stage06SchedulingTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    private function setupSlot(): array
    {
        $centre=Centre::where('code','ENSHA-MAIN')->firstOrFail();
        $users=[];
        foreach (['manager','counselor','client'] as $i=>$slug) {
            $role=Role::where('slug',$slug)->firstOrFail();
            $user=User::create(['first_name'=>'آزمون','last_name'=>$slug,'name'=>'آزمون '.$slug,
                'phone'=>'0912777100'.$i,'password'=>'ValidPass123!','role'=>$slug,'role_id'=>$role->id,
                'centre_id'=>$centre->id,'is_active'=>true,'status'=>'active','must_change_password'=>false]);
            $user->roleAssignments()->create(['role_id'=>$role->id,'centre_id'=>$centre->id]);
            $users[$slug]=$user;
        }
        $client=Client::create(['user_id'=>$users['client']->id,'centre_id'=>$centre->id,'client_code'=>'CL-S06','status'=>'active']);
        $category=DB::table('service_categories')->insertGetId(['centre_id'=>$centre->id,'name'=>'Stage06','created_at'=>now(),'updated_at'=>now()]);
        $topic=DB::table('service_topics')->insertGetId(['category_id'=>$category,'name'=>'آزمون','minimum_minutes'=>30,'session_minutes'=>60,
            'break_minutes'=>0,'capacity'=>1,'requires_room'=>false,'is_active'=>true,'price'=>600000,'color'=>'#3366ff',
            'allowed_modes'=>json_encode(['phone']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('counselor_topics')->insert(['user_id'=>$users['counselor']->id,'topic_id'=>$topic,'centre_id'=>$centre->id,'is_active'=>true]);
        $start=now()->addDays(2)->startOfHour();
        DB::table('counselor_shifts')->insert(['user_id'=>$users['counselor']->id,'centre_id'=>$centre->id,'weekday'=>$start->dayOfWeek,
            'starts_at'=>'00:00:00','ends_at'=>'23:59:59','hourly_pay'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $slot=AppointmentSlot::create(['centre_id'=>$centre->id,'topic_id'=>$topic,'counselor_id'=>$users['counselor']->id,
            'slot_date'=>$start->toDateString(),'starts_at'=>$start,'ends_at'=>$start->copy()->addMinutes(30),
            'mode'=>'phone','capacity'=>1,'booked_count'=>0,'status'=>'available']);
        return [$centre,$users,$client,$slot];
    }

    public function test_quote_and_booking_snapshot_and_draft_case(): void
    {
        config(['appointments.lock_store'=>'array']);
        [$centre,$users,$client,$slot]=$this->setupSlot();
        $discount=DB::table('discounts')->insertGetId(['centre_id'=>$centre->id,'name'=>'تخفیف آزمون','type'=>'percent','value'=>20,
            'minimum_amount'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $quote=app(AppointmentPricingService::class)->quote($slot,$discount,100000);
        $this->assertSame(300000,$quote['base_price']); $this->assertSame(240000,$quote['final_price']);
        $appointment=app(AppointmentBookingService::class)->book($slot->id,$client->id,null,$users['manager']->id,null,
            ['discount_id'=>$discount,'paid_amount'=>100000]);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{12}$/',$appointment->public_id);
        $this->assertSame(140000,$appointment->balance_amount);
        $this->assertNotNull($appointment->case_id);
        $this->assertDatabaseHas('cases',['id'=>$appointment->case_id,'status'=>'draft']);
    }

    public function test_leave_rejects_booking_at_commit_time(): void
    {
        config(['appointments.lock_store'=>'array']);
        [$centre,$users,$client,$slot]=$this->setupSlot();
        DB::table('counselor_leaves')->insert(['centre_id'=>$centre->id,'user_id'=>$users['counselor']->id,
            'starts_at'=>$slot->starts_at,'ends_at'=>$slot->ends_at,'created_at'=>now(),'updated_at'=>now()]);
        $this->expectException(ValidationException::class);
        app(AppointmentBookingService::class)->book($slot->id,$client->id,null,$users['manager']->id);
    }

    public function test_quick_client_and_attendance_start_are_centre_scoped(): void
    {
        [$centre,$users]=$this->setupSlot();
        $this->actingAs($users['manager'])->postJson(route('stage06.clients.quick'),['first_name'=>'مراجع','last_name'=>'تازه'])
            ->assertCreated()->assertJsonPath('profile_state','minimal');
        $this->actingAs($users['manager'])->post(route('attendance.start'))->assertRedirect();
        $this->assertDatabaseHas('staff_work_sessions',['staff_id'=>$users['manager']->id,'centre_id'=>$centre->id,'status'=>'open']);
        $this->actingAs($users['manager'])->post(route('attendance.end'))->assertRedirect();
        $this->assertDatabaseHas('staff_work_sessions',['staff_id'=>$users['manager']->id,'status'=>'closed']);
    }
}
