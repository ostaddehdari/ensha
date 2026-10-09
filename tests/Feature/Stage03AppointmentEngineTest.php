<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceTariff;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\TariffVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Stage03AppointmentEngineTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    public function test_tariff_versions_close_the_previous_version(): void
    {
        [$centre, $manager, $counselor, $client, $topic] = $this->fixture();
        $service = app(TariffVersionService::class);
        $first = $service->create(['centre_id'=>$centre->id,'topic_id'=>$topic,'price'=>1000000,'counselor_pay'=>500000,'currency'=>'IRR','valid_from'=>'2026-10-06','created_by'=>$manager->id]);
        $second = $service->create(['centre_id'=>$centre->id,'topic_id'=>$topic,'price'=>1200000,'counselor_pay'=>600000,'currency'=>'IRR','valid_from'=>'2026-11-01','created_by'=>$manager->id]);
        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $first->refresh();
        $this->assertSame('2026-10-31', $first->valid_until?->toDateString());
        $this->assertFalse($first->is_active);
    }

    public function test_booking_uses_unique_seat_and_status_history(): void
    {
        config(['appointments.lock_store'=>'array']);
        [$centre, $manager, $counselor, $client, $topic] = $this->fixture();
        $slot = AppointmentSlot::create(['centre_id'=>$centre->id,'topic_id'=>$topic,'counselor_id'=>$counselor->id,'slot_date'=>now()->addDay()->toDateString(),'starts_at'=>now()->addDay()->startOfHour(),'ends_at'=>now()->addDay()->startOfHour()->addHour(),'mode'=>'phone','capacity'=>1,'booked_count'=>0,'status'=>'available']);
        $appointment = app(AppointmentBookingService::class)->book($slot->id, $client->id, null, $manager->id);
        $this->assertSame(1, $appointment->seat_number);
        $this->assertDatabaseHas('appointment_status_histories', ['appointment_id'=>$appointment->id,'to_status'=>'pending']);
        $this->assertSame('full', $slot->fresh()->status);
    }

    public function test_status_machine_rejects_invalid_transition(): void
    {
        $appointment = new Appointment(['status'=>'pending']);
        $this->assertTrue($appointment->canTransitionTo('confirmed'));
        $this->assertFalse($appointment->canTransitionTo('completed'));
    }

    private function fixture(): array
    {
        $centre = Centre::where('code','ENSHA-MAIN')->firstOrFail();
        $manager = $this->user('manager', $centre, '09125551001');
        $counselor = $this->user('counselor', $centre, '09125551002');
        $clientUser = $this->user('client', $centre, '09125551003');
        $client = Client::create(['user_id'=>$clientUser->id,'centre_id'=>$centre->id,'client_code'=>'CL-S03','status'=>'active']);
        $category = DB::table('service_categories')->insertGetId(['centre_id'=>$centre->id,'name'=>'Stage 03','created_at'=>now(),'updated_at'=>now()]);
        $topic = DB::table('service_topics')->insertGetId(['category_id'=>$category,'name'=>'جلسه آزمون','minimum_minutes'=>30,'session_minutes'=>60,'break_minutes'=>0,'capacity'=>1,'requires_room'=>false,'is_active'=>true,'price'=>1000000,'color'=>'#3366ff','allowed_modes'=>json_encode(['phone']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('counselor_topics')->insert(['user_id'=>$counselor->id,'topic_id'=>$topic]);
        DB::table('counselor_shifts')->insert(['user_id'=>$counselor->id,'centre_id'=>$centre->id,
            'weekday'=>now()->addDay()->dayOfWeek,'starts_at'=>'00:00:00','ends_at'=>'23:59:59',
            'hourly_pay'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        return [$centre,$manager,$counselor,$client,$topic];
    }

    private function user(string $slug, Centre $centre, string $phone): User
    {
        $role = Role::where('slug',$slug)->firstOrFail();
        $user = User::create(['first_name'=>'کاربر','last_name'=>$role->name,'name'=>'کاربر','phone'=>$phone,'password'=>'ValidPass123!','role'=>$slug,'role_id'=>$role->id,'centre_id'=>$centre->id,'is_active'=>true,'status'=>'active','must_change_password'=>false]);
        $user->roleAssignments()->create(['role_id'=>$role->id,'centre_id'=>$centre->id]);
        return $user;
    }
}
