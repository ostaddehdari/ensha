<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentReschedule;
use App\Models\AppointmentWaitlist;
use App\Models\AppointmentSlot;
use App\Models\Client;
use App\Models\ServiceTopic;
use App\Models\SmsMessage;
use App\Models\TelephoneConsultation;
use App\Services\AppointmentBookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DailyOperationsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $date = Carbon::parse($request->input('date', now()->toDateString()))->toDateString(); $centreId = (int) $request->user()->centre_id;
        $appointments = Appointment::visibleTo($request->user())->with(['client.user','topic','counselor'])->whereDate('starts_at', $date)->orderBy('starts_at')->get();
        $waiting = $appointments->whereIn('status', ['arrived','in_session'])->values();
        $stats = ['total'=>$appointments->count(),'pending'=>$appointments->where('status','pending')->count(),'waiting'=>$waiting->where('status','arrived')->count(),'in_session'=>$waiting->where('status','in_session')->count(),'completed'=>$appointments->where('status','completed')->count(),'no_show'=>$appointments->where('status','no_show')->count()];
        $waitlists = AppointmentWaitlist::with(['client.user','topic'])->where('centre_id',$centreId)->where('status','waiting')->orderByDesc('priority')->orderBy('desired_from')->limit(20)->get();
        $availableSlots = AppointmentSlot::with(['topic','counselor'])->where('centre_id',$centreId)->where('status','available')->where('starts_at','>',now())->orderBy('starts_at')->limit(150)->get();
        $clients = Client::with('user')->where('centre_id',$centreId)->where('status','active')->orderBy('client_code')->get();
        $topics = ServiceTopic::whereHas('category', fn ($q) => $q->where('centre_id',$centreId))->where('is_active',true)->orderBy('name')->get();
        return view('operations.index', compact('date','appointments','waiting','stats','waitlists','availableSlots','clients','topics'));
    }

    public function checkIn(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        $this->manage($request, $appointment); abort_if(! in_array($appointment->status,['pending','confirmed'],true),422,'این نوبت قابل پذیرش نیست.');
        $this->requireComplete($appointment,'arrival');
        $booking->transition($appointment, 'arrived', $request->user()->id, 'پذیرش مراجع');
        $appointment->update(['checked_in_at'=>now(),'checked_in_by'=>$request->user()->id]);
        return back()->with('success','مراجع پذیرش شد و وارد صف انتظار گردید.');
    }
    public function startSession(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        $this->manage($request, $appointment); abort_if($appointment->status !== 'arrived',422,'ابتدا مراجع باید پذیرش شود.');
        $this->requireComplete($appointment,'session');
        $booking->transition($appointment,'in_session',$request->user()->id,'شروع جلسه از عملیات روزانه'); $appointment->update(['session_started_at'=>now()]);
        return back()->with('success','جلسه شروع شد.');
    }
    public function endSession(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        $this->manage($request, $appointment); abort_if($appointment->status !== 'in_session',422,'جلسه فعال نیست.');
        $booking->transition($appointment,'completed',$request->user()->id,'اتمام جلسه از عملیات روزانه'); $appointment->update(['session_ended_at'=>now()]);
        return back()->with('success','جلسه تکمیل شد.');
    }
    public function noShow(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        $this->manage($request, $appointment); abort_if(! in_array($appointment->status,['pending','confirmed','arrived'],true),422,'ثبت عدم مراجعه برای این وضعیت مجاز نیست.');
        if ($appointment->status === 'pending') $booking->transition($appointment,'confirmed',$request->user()->id,'تأیید سیستمی پیش از ثبت عدم مراجعه');
        $booking->transition($appointment->fresh(),'no_show',$request->user()->id,$request->input('reason','عدم مراجعه مراجع')); $appointment->update(['no_show_at'=>now()]);
        return back()->with('success','عدم مراجعه ثبت شد.');
    }
    public function cancel(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        $this->manage($request, $appointment); $data=$request->validate(['reason'=>'required|string|max:1000']);
        if ($appointment->status === 'pending') $booking->transition($appointment,'confirmed',$request->user()->id,'تأیید سیستمی پیش از لغو');
        $booking->transition($appointment->fresh(),'cancelled',$request->user()->id,$data['reason']);
        return back()->with('success','نوبت لغو شد و ظرفیت اسلات آزاد شد.');
    }
    public function reschedule(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        $this->manage($request,$appointment); $data=$request->validate(['slot_id'=>'required|integer|exists:appointment_slots,id','reason'=>'nullable|string|max:1000']);
        abort_if(in_array($appointment->status,['completed','cancelled','no_show'],true),422,'این نوبت قابل جابه‌جایی نیست.');
        $slot=AppointmentSlot::findOrFail($data['slot_id']); abort_unless($slot->centre_id === $appointment->centre_id,403);
        // Move the existing record; booking a second overlapping record would always violate client conflict checks.
        app(\App\Services\AppointmentRescheduleService::class)->move($appointment,$slot,$request->user()->id);
        return redirect()->route('appointments.show',$appointment)->with('success','نوبت با موفقیت جابه‌جا شد.');
    }
    public function storeWaitlist(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'),403); $centreId=(int)$request->user()->centre_id;
        $data=$request->validate(['client_id'=>'required|integer|exists:clients,id','topic_id'=>'required|integer|exists:service_topics,id','desired_from'=>'nullable|date','desired_until'=>'nullable|date|after_or_equal:desired_from','counselor_id'=>'nullable|integer|exists:users,id','priority'=>'nullable|integer|min:0|max:9','notes'=>'nullable|string|max:1000']);
        abort_unless(Client::whereKey($data['client_id'])->where('centre_id',$centreId)->exists(),403);
        AppointmentWaitlist::create($data+['centre_id'=>$centreId,'status'=>'waiting','priority'=>$data['priority']??0,'created_by'=>$request->user()->id]);
        return back()->with('success','مراجع به لیست انتظار افزوده شد.');
    }
    public function promoteWaitlist(Request $request, AppointmentWaitlist $waitlist, AppointmentBookingService $booking)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'),403); abort_unless($waitlist->centre_id === $request->user()->centre_id,403);
        $data=$request->validate(['slot_id'=>'required|integer|exists:appointment_slots,id']); $slot=AppointmentSlot::findOrFail($data['slot_id']);
        abort_if($waitlist->status !== 'waiting',422,'این رکورد دیگر در انتظار نیست.');
        $appointment=$booking->book($slot->id,$waitlist->client_id,null,$request->user()->id,'رزرو از لیست انتظار');
        $waitlist->update(['status'=>'promoted','promoted_at'=>now(),'promoted_appointment_id'=>$appointment->id]);
        return redirect()->route('appointments.show',$appointment)->with('success','لیست انتظار به نوبت تبدیل شد.');
    }
    public function sms(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'),403); $date=Carbon::parse($request->input('date',now()->toDateString()))->toDateString(); $centreId=(int)$request->user()->centre_id;
        $upcoming=Appointment::visibleTo($request->user())->with('client.user')->whereDate('starts_at',$date)->whereIn('status',['pending','confirmed'])->get();
        foreach($upcoming as $a){$phone=$a->client?->user?->phone; if(!$phone || str_starts_with($phone,'TMP'))continue; SmsMessage::firstOrCreate(['appointment_id'=>$a->id,'type'=>'appointment_reminder'],['centre_id'=>$centreId,'client_id'=>$a->client_id,'phone_number'=>$phone,'body'=>'یادآوری نوبت شما در '.$a->starts_at->format('Y-m-d H:i'),'scheduled_for'=>now(),'status'=>'queued','created_by'=>$request->user()->id]);}
        return back()->with('success','یادآوری‌های امروز در صف ارسال قرار گرفت.');
    }
    public function report(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'),403); $date=Carbon::parse($request->input('date',now()->toDateString()))->toDateString(); $centreId=(int)$request->user()->centre_id;
        $appointments=Appointment::visibleTo($request->user())->whereDate('starts_at',$date); $byStatus=(clone $appointments)->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count','status');
        $calls=TelephoneConsultation::where('centre_id',$centreId)->whereDate('started_at',$date)->selectRaw('status,count(*) as count')->groupBy('status')->pluck('count','status');
        $sms=SmsMessage::where('centre_id',$centreId)->whereDate('scheduled_for',$date)->selectRaw('status,count(*) as count')->groupBy('status')->pluck('count','status');
        return view('operations.report',compact('date','byStatus','calls','sms'));
    }
    private function manage(Request $request, Appointment $appointment): void { abort_unless($request->user()->hasPermission('appointments.manage') && ($request->user()->isSuperAdmin() || $appointment->centre_id === $request->user()->centre_id),403); }
    private function requireComplete(Appointment $appointment, string $at): void
    {
        $required=DB::table('client_record_settings')->where('centre_id',$appointment->centre_id)->value('complete_required_on');
        if ($required === $at && $appointment->client?->profile_state !== 'complete')
            throw ValidationException::withMessages(['client'=>'پرونده مراجع باید تکمیل شود.']);
    }
}
