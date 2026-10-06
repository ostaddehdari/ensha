<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Client;
use App\Models\ServiceCategory;
use App\Models\ServiceTopic;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class Stage06CalendarDemoSeeder extends Seeder
{
    public function run(): void
    {
        $centreId = (int) (env('ENSHA_DEMO_CENTRE_ID') ?: User::findOrFail(3)->centre_id);
        if (! $centreId) throw new RuntimeException('ENSHA_DEMO_CENTRE_ID must refer to the secretary centre.');
        $roleId = DB::table('roles')->where('slug', 'counselor')->value('id');
        $clientRoleId = DB::table('roles')->where('slug', 'client')->value('id');
        if (! $roleId || ! $clientRoleId) throw new RuntimeException('Counselor/client roles are missing.');
        $week = now('Asia/Tehran')->startOfWeek(Carbon::SATURDAY);
        $today = now('Asia/Tehran')->startOfDay();
        $startDay = max(0, (int) $week->diffInDays($today, false));
        if ($startDay > 6) $startDay = 0;
        $names = [['ح','میرزایی'],['ح','کریم پور'],['ح','نوروزی'],['ح','کهنمویی'],['د','شهرکی'],['ح','اسماعیلی']];
        $clients = [['آزمایشی','محمدی'],['آزمایشی','احمدی'],['آزمایشی','رضایی'],['آزمایشی','حسینی'],['آزمایشی','کریمی'],['آزمایشی','موسوی'],['آزمایشی','جعفری'],['آزمایشی','صادقی'],['آزمایشی','قاسمی'],['آزمایشی','نوری']];
        DB::transaction(function () use ($centreId,$roleId,$clientRoleId,$week,$startDay,$names,$clients) {
            $category = ServiceCategory::firstOrCreate(['centre_id'=>$centreId,'name'=>'خدمات آزمایشی Stage 06']);
            $topic = ServiceTopic::firstOrCreate(['category_id'=>$category->id,'name'=>'مشاوره آزمایشی'],[
                'minimum_minutes'=>45,'session_minutes'=>45,'break_minutes'=>0,'capacity'=>1,'requires_room'=>false,
                'price'=>500000,'color'=>'#2563eb','allowed_modes'=>['video'],'is_active'=>true,
            ]);
            $counselors=[];
            foreach ($names as $i => [$first,$last]) {
                $phone='09990006'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT);
                $counselors[]=$this->demoUser($phone,$first,$last,'counselor',$roleId,$centreId);
            }
            $clientIds=[];
            foreach ($clients as $i => [$first,$last]) {
                $phone='09990007'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT);
                $user=$this->demoUser($phone,$first,$last,'client',$clientRoleId,$centreId);
                $client=Client::firstOrCreate(['user_id'=>$user->id],['centre_id'=>$centreId,'client_code'=>'DEMO-06-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT),'status'=>'active','profile_state'=>'minimal','notes'=>'داده آزمایشی Stage 06']);
                if ((int)$client->centre_id !== $centreId) throw new RuntimeException('Demo client centre mismatch.');
                $clientIds[]=$client->id;
            }
            $statusId=DB::table('appointment_statuses')->where('centre_id',$centreId)->where('slug','pending')->value('id');
            for ($i=0;$i<10;$i++) {
                $day=$week->copy()->addDays($startDay+($i % (7-$startDay)));
                $hour=9+intdiv($i,6)*2;
                if ($day->isSameDay(now('Asia/Tehran')) && $hour <= now('Asia/Tehran')->hour) $hour=max(now('Asia/Tehran')->hour+1, 9);
                if ($hour>19) $hour=19;
                $begin=$day->copy()->setTime($hour,0);$end=$begin->copy()->addMinutes(45);
                $counselor=$counselors[$i % 6];
                $this->ensureShift($centreId,$counselor->id,$begin->dayOfWeek,$hour);
                $this->ensureMapping($centreId,$counselor->id,$topic->id);
                $slot=AppointmentSlot::firstOrCreate(['counselor_id'=>$counselor->id,'topic_id'=>$topic->id,'starts_at'=>$begin->format('Y-m-d H:i:s'),'mode'=>'video'],[
                    'centre_id'=>$centreId,'slot_date'=>$begin->toDateString(),'ends_at'=>$end->format('Y-m-d H:i:s'),'capacity'=>1,'booked_count'=>1,'status'=>'full','source'=>'stage06_demo',
                ]);
                if ($slot->source !== 'stage06_demo') throw new RuntimeException('Demo slot collides with an existing slot.');
                $number='DEMO06-'.$week->format('Ymd').'-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT);
                $existing=Appointment::withTrashed()->where('appointment_number',$number)->first();
                if ($existing && ($existing->source !== 'stage06_demo' || $existing->centre_id != $centreId)) throw new RuntimeException('Demo appointment identifier is occupied.');
                if (!$existing) {
                    $appointment=Appointment::create(['appointment_number'=>$number,'centre_id'=>$centreId,'client_id'=>$clientIds[$i],
                        'topic_id'=>$topic->id,'counselor_id'=>$counselor->id,'slot_id'=>$slot->id,'seat_number'=>1,'mode'=>'video',
                        'starts_at'=>$begin,'ends_at'=>$end,'status'=>'pending','price'=>500000,'base_price'=>500000,'final_price'=>500000,
                        'balance_amount'=>500000,'unit_price_snapshot'=>500000,'duration_minutes'=>45,'currency'=>'IRR',
                        'topic_name_snapshot'=>$topic->name,'topic_color_snapshot'=>$topic->color,'status_id'=>$statusId,'status_snapshot'=>'نوبت',
                        'public_id'=>(string) random_int(1000000000000,9999999999999),'source'=>'stage06_demo','notes'=>'نوبت آزمایشی Stage 06']);
                    DB::table('appointment_status_histories')->insert(['appointment_id'=>$appointment->id,'to_status'=>'pending','reason'=>'داده آزمایشی Stage 06','changed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
                }
            }
            // Create free slots for the same six columns, so calendar clicks can open a usable booking form.
            foreach ($counselors as $counselor) for ($day=$startDay;$day<7;$day++) {
                $date=$week->copy()->addDays($day);
                if ($date->isFriday()) continue;
                $this->ensureShift($centreId,$counselor->id,$date->dayOfWeek,15);
                $this->ensureMapping($centreId,$counselor->id,$topic->id);
                foreach ([15,16] as $hour) {
                    $begin=$date->copy()->setTime($hour,0); if ($begin->isPast()) continue;
                    AppointmentSlot::firstOrCreate(['counselor_id'=>$counselor->id,'topic_id'=>$topic->id,'starts_at'=>$begin->format('Y-m-d H:i:s'),'mode'=>'video'],[
                        'centre_id'=>$centreId,'slot_date'=>$begin->toDateString(),'ends_at'=>$begin->copy()->addMinutes(45)->format('Y-m-d H:i:s'),
                        'capacity'=>1,'booked_count'=>0,'status'=>'available','source'=>'stage06_demo',
                    ]);
                }
            }
            $this->command?->info('DEMO_STAGE06_OK centre='.$centreId.' counselors=6 clients=10 appointments=10 week='.$week->toDateString());
        });
    }

    private function demoUser(string $phone,string $first,string $last,string $role,int $roleId,int $centreId): User
    {
        $user=User::withTrashed()->where('phone',$phone)->first();
        if ($user && (($user->metadata['stage06_demo'] ?? null) !== true || $user->centre_id != $centreId || $user->trashed()))
            throw new RuntimeException('Demo phone belongs to a different user: '.$phone);
        $user ??= User::create(['first_name'=>$first,'last_name'=>$last,'name'=>$first.' '.$last,'phone'=>$phone,
            'password'=>Str::random(48),'role'=>$role,'role_id'=>$roleId,'centre_id'=>$centreId,'is_active'=>true,'status'=>'active',
            'metadata'=>['stage06_demo'=>true],'must_change_password'=>true]);
        DB::table('user_role_centres')->insertOrIgnore(['user_id'=>$user->id,'role_id'=>$roleId,'centre_id'=>$centreId,'created_at'=>now(),'updated_at'=>now()]);
        return $user;
    }
    private function ensureShift(int $centreId,int $userId,int $weekday,int $hour): void
    {
        if (! DB::table('counselor_shifts')->where('centre_id',$centreId)->where('user_id',$userId)->where('weekday',$weekday)->where('is_active',true)->where('starts_at','<=',sprintf('%02d:00:00',$hour))->where('ends_at','>=',sprintf('%02d:45:00',$hour))->exists())
            DB::table('counselor_shifts')->insert(['centre_id'=>$centreId,'user_id'=>$userId,'weekday'=>$weekday,'starts_at'=>'08:00:00','ends_at'=>'20:00:00','hourly_pay'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    }
    private function ensureMapping(int $centreId,int $userId,int $topicId): void
    {
        DB::table('counselor_topics')->insertOrIgnore(['user_id'=>$userId,'topic_id'=>$topicId,'centre_id'=>$centreId,'is_active'=>true]);
    }
}
