<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\Client;
use App\Models\CompensationRule;
use App\Models\Role;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\CompensationService;
use App\Services\PaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Stage10CompensationSettlementTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    public function test_completed_session_freezes_share_even_after_a_new_rule(): void
    {
        config(['appointments.lock_store'=>'array']);
        [$manager,$counselor,$client,$slot]=$this->fixture();
        $appointment=app(AppointmentBookingService::class)->book($slot->id,$client->id,null,$manager->id);
        $this->complete($appointment,$manager);
        $snapshot=$appointment->fresh()->compensationSnapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame(350000,(int)$snapshot->counselor_share_amount);
        app(CompensationService::class)->createRule($slot->centre_id,$manager,[
            'name'=>'نسخه جدید ۵۰٪','beneficiary'=>'counselor','calculation_type'=>'percentage','value'=>5000,
            'valid_from'=>now()->addDay()->toDateString(),'topic_id'=>null,'counselor_id'=>null,
        ]);
        $this->assertSame(350000,(int)$snapshot->fresh()->counselor_share_amount);
    }

    public function test_partial_collection_creates_proportional_settlement_and_workflow(): void
    {
        config(['appointments.lock_store'=>'array']);
        [$manager,$counselor,$client,$slot]=$this->fixture();
        $appointment=app(AppointmentBookingService::class)->book($slot->id,$client->id,null,$manager->id);
        $this->complete($appointment,$manager);
        $ledger=app(PaymentLedgerService::class);
        $ledger->openRegister($manager,0);
        $ledger->postPayment($appointment,$manager,250000,'card');
        $settlement=app(CompensationService::class)->createSettlement($slot->centre_id,$manager,$counselor->id,$slot->slot_date->toDateString(),$slot->slot_date->toDateString());
        $this->assertSame(175000,(int)$settlement->counselor_share_amount);
        $this->assertSame(75000,(int)$settlement->centre_share_amount);
        app(CompensationService::class)->approve($settlement,$manager);
        app(CompensationService::class)->markPaid($settlement->fresh(),$manager,'BANK-TEST-10');
        $this->assertSame('paid',$settlement->fresh()->status);
        $this->assertSame(175000,(int)$settlement->fresh()->paid_amount);
    }

    public function test_counselor_sees_only_own_settlement_and_cannot_create_one(): void
    {
        config(['appointments.lock_store'=>'array']);
        [$manager,$counselor,$client,$slot]=$this->fixture();
        $appointment=app(AppointmentBookingService::class)->book($slot->id,$client->id,null,$manager->id);
        $this->complete($appointment,$manager);
        $ledger=app(PaymentLedgerService::class); $ledger->openRegister($manager,0); $ledger->postPayment($appointment,$manager,500000,'card');
        $settlement=app(CompensationService::class)->createSettlement($slot->centre_id,$manager,$counselor->id,$slot->slot_date->toDateString(),$slot->slot_date->toDateString());
        $this->actingAs($counselor)->get(route('settlements.show',$settlement))->assertOk();
        $this->actingAs($counselor)->post(route('settlements.store'),[])->assertForbidden();
    }

    private function complete($appointment,User $actor): void
    {
        $booking=app(AppointmentBookingService::class);
        foreach(['confirmed','arrived','in_session','completed'] as $status) $appointment=$booking->transition($appointment,$status,$actor->id);
    }

    private function fixture(): array
    {
        $centre=Centre::where('code','ENSHA-MAIN')->firstOrFail();
        $manager=$this->makeUser('manager',$centre->id,'09127770101');
        $counselor=$this->makeUser('counselor',$centre->id,'09127770102');
        $clientUser=$this->makeUser('client',$centre->id,'09127770103');
        $client=Client::create(['user_id'=>$clientUser->id,'centre_id'=>$centre->id,'client_code'=>'CL-S10','status'=>'active']);
        $category=DB::table('service_categories')->insertGetId(['centre_id'=>$centre->id,'name'=>'Stage10','created_at'=>now(),'updated_at'=>now()]);
        $topic=DB::table('service_topics')->insertGetId(['category_id'=>$category,'name'=>'تسویه','minimum_minutes'=>30,'session_minutes'=>60,'break_minutes'=>0,'capacity'=>1,'requires_room'=>false,'is_active'=>true,'price'=>500000,'color'=>'#6688aa','allowed_modes'=>json_encode(['in_person']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('counselor_topics')->insert(['user_id'=>$counselor->id,'topic_id'=>$topic,'centre_id'=>$centre->id,'is_active'=>true]);
        $start=now()->addDay()->startOfHour();
        DB::table('counselor_shifts')->insert(['user_id'=>$counselor->id,'centre_id'=>$centre->id,'weekday'=>$start->dayOfWeek,'starts_at'=>'00:00:00','ends_at'=>'23:59:59','hourly_pay'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        CompensationRule::where('centre_id',$centre->id)->delete();
        CompensationRule::create(['public_id'=>fake()->uuid(),'centre_id'=>$centre->id,'name'=>'سهم ۷۰٪','beneficiary'=>'counselor','calculation_type'=>'percentage','value'=>7000,'version'=>1,'valid_from'=>'2000-01-01','is_active'=>true]);
        $slot=AppointmentSlot::create(['centre_id'=>$centre->id,'topic_id'=>$topic,'counselor_id'=>$counselor->id,'slot_date'=>$start->toDateString(),'starts_at'=>$start,'ends_at'=>$start->copy()->addHour(),'mode'=>'in_person','capacity'=>1,'booked_count'=>0,'status'=>'available']);
        return [$manager,$counselor,$client,$slot];
    }

    private function makeUser(string $roleSlug,int $centreId,string $phone): User
    {
        $role=Role::where('slug',$roleSlug)->firstOrFail();
        $user=User::create(['first_name'=>'کاربر','last_name'=>$role->name,'name'=>'کاربر '.$role->name,'phone'=>$phone,'password'=>'ValidPass123!','role'=>$roleSlug,'role_id'=>$role->id,'centre_id'=>$centreId,'is_active'=>true,'status'=>'active','must_change_password'=>false]);
        $user->roleAssignments()->create(['role_id'=>$role->id,'centre_id'=>$centreId]);
        return $user;
    }
}
