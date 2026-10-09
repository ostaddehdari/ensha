<?php

namespace App\Http\Controllers;

use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\Client;
use App\Models\ExternalIdentity;
use App\Models\Role;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WordPressApiController extends Controller
{
    public function availability(Request $request,Centre $centre)
    {
        $data=$request->validate(['from'=>'required|date','to'=>'required|date|after:from','topic_id'=>'nullable|integer','counselor_id'=>'nullable|integer']);
        $slots=AppointmentSlot::with(['topic','counselor'])->where('centre_id',$centre->id)->where('status','available')->where('starts_at','>=',$data['from'])->where('starts_at','<',$data['to'])
            ->when($data['topic_id']??null,fn($q,$id)=>$q->where('topic_id',$id))->when($data['counselor_id']??null,fn($q,$id)=>$q->where('counselor_id',$id))->orderBy('starts_at')->limit(300)->get();
        return response()->json(['data'=>$slots->map(fn($s)=>['id'=>$s->id,'start'=>$s->starts_at->toIso8601String(),'end'=>$s->ends_at->toIso8601String(),'topic'=>['id'=>$s->topic_id,'name'=>$s->topic?->name],'counselor'=>['id'=>$s->counselor_id,'name'=>$s->counselor?->display_name],'mode'=>$s->mode])->values()]);
    }

    public function book(Request $request,Centre $centre,AppointmentBookingService $booking)
    {
        $data=$request->validate(['slot_id'=>'required|integer','wordpress_user_id'=>'required|string|max:120','first_name'=>'required|string|max:100','last_name'=>'required|string|max:100','phone'=>'required|string|max:20','email'=>'nullable|email|max:190','notes'=>'nullable|string|max:2000']);
        $slot=AppointmentSlot::where('centre_id',$centre->id)->findOrFail($data['slot_id']);
        $actorId=DB::table('user_role_centres as urc')->join('roles as r','r.id','=','urc.role_id')->where('urc.centre_id',$centre->id)->whereIn('r.slug',['manager','super_admin'])->value('urc.user_id');
        abort_unless($actorId,422,'مدیر مرکز برای ثبت نوبت عمومی مشخص نشده است.');
        $identity=ExternalIdentity::where('provider','wordpress')->where('external_id',$data['wordpress_user_id'])->whereHas('client',fn($q)=>$q->where('centre_id',$centre->id))->first();
        $client=$identity?->client;
        if(!$client){
            $client=DB::transaction(function()use($data,$centre,$actorId){
                $role=Role::where('slug','client')->firstOrFail();
                $user=User::create(['first_name'=>$data['first_name'],'last_name'=>$data['last_name'],'name'=>$data['first_name'].' '.$data['last_name'],'phone'=>$data['phone'],'password'=>Str::random(48),'role'=>'client','role_id'=>$role->id,'centre_id'=>$centre->id,'status'=>'active','is_active'=>true,'must_change_password'=>true,'created_by'=>$actorId]);
                $user->roleAssignments()->create(['role_id'=>$role->id,'centre_id'=>$centre->id]);
                $client=Client::create(['user_id'=>$user->id,'centre_id'=>$centre->id,'client_code'=>'WP-'.Str::upper(Str::random(10)),'status'=>'active','profile_state'=>'minimal','created_by'=>$actorId]);
                ExternalIdentity::create(['client_id'=>$client->id,'provider'=>'wordpress','external_id'=>$data['wordpress_user_id'],'external_email'=>$data['email']??null,'linked_at'=>now(),'last_synced_at'=>now()]);
                return $client;
            });
        }
        $appointment=$booking->book($slot->id,$client->id,null,(int)$actorId,$data['notes']??null,['source'=>'wordpress']);
        return response()->json(['appointment_id'=>$appointment->public_id,'number'=>$appointment->appointment_number,'status'=>$appointment->status],201);
    }
}
