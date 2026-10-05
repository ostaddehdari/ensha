<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CounsellingCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CounselorWorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $user=$request->user(); abort_unless($user->centre_id && $user->hasPermission('appointments.view'),403);
        $week=max(-8,min(8,$request->integer('week',0)));
        $start=now()->startOfWeek(Carbon::SATURDAY)->addWeeks($week);
        $appointments=Appointment::visibleTo($user)->with(['client.user','topic','counselor'])
            ->where('starts_at','>=',$start)->where('starts_at','<',$start->copy()->addWeek())
            ->when($request->filled('topic_id'),fn ($q) => $q->where('topic_id',$request->integer('topic_id')))
            ->when($request->filled('status'),fn ($q) => $q->where('status',$request->string('status')))
            ->when($request->filled('mode'),fn ($q) => $q->where('mode',$request->string('mode')))
            ->when($request->filled('centre_id') && $user->isSuperAdmin(),fn ($q) => $q->where('centre_id',$request->integer('centre_id')))
            ->orderBy('starts_at')->get()->groupBy(fn ($a) => $a->starts_at->toDateString());
        $leaves=DB::table('counselor_leave_requests')->where('centre_id',$user->centre_id)
            ->where('counselor_id',$user->id)->orderByDesc('created_at')->limit(20)->get();
        $pending=$user->hasPermission('leave_requests.manage') ? DB::table('counselor_leave_requests as l')
            ->join('users as u','u.id','=','l.counselor_id')->where('l.centre_id',$user->centre_id)->where('l.status','pending')
            ->select('l.*','u.name')->get() : collect();
        return view('appointments.counselor-week',compact('week','start','appointments','leaves','pending'));
    }
    public function requestLeave(Request $request)
    {
        $user=$request->user(); abort_unless($user->assignedRole?->slug==='counselor',403);
        $d=$request->validate(['branch_id'=>'nullable|integer','starts_at'=>'required|date|after:now',
            'ends_at'=>'required|date|after:starts_at','reason'=>'required|string|max:1000']);
        abort_if(Carbon::parse($d['starts_at'])->diffInDays(Carbon::parse($d['ends_at']))>31,422,'درخواست باید حداکثر ۳۱ روز باشد.');
        if (! empty($d['branch_id'])) abort_unless(DB::table('centre_branches')->where('id',$d['branch_id'])->where('centre_id',$user->centre_id)->exists(),422);
        DB::table('counselor_leave_requests')->insert([...$d,'centre_id'=>$user->centre_id,'counselor_id'=>$user->id,
            'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
        return back()->with('success','درخواست مرخصی ثبت شد.');
    }
    public function reviewLeave(Request $request, int $leave)
    {
        abort_unless($request->user()->hasPermission('leave_requests.manage'),403);
        $d=$request->validate(['decision'=>'required|in:approved,rejected','review_note'=>'nullable|string|max:1000']);
        DB::transaction(function () use ($request,$leave,$d) {
            $row=DB::table('counselor_leave_requests')->where('id',$leave)->where('centre_id',$request->user()->centre_id)->lockForUpdate()->firstOrFail();
            if ($row->status!=='pending') throw ValidationException::withMessages(['decision'=>'این درخواست قبلاً بررسی شده است.']);
            if ($d['decision']==='approved') {
                $overlap=Appointment::where('counselor_id',$row->counselor_id)->whereNotIn('status',['cancelled','no_show'])
                    ->where('starts_at','<',$row->ends_at)->where('ends_at','>',$row->starts_at)->exists();
                if ($overlap) throw ValidationException::withMessages(['decision'=>'ابتدا نوبت‌های هم‌پوشان را جابه‌جا کنید.']);
                DB::table('counselor_leaves')->insert(['user_id'=>$row->counselor_id,'centre_id'=>$row->centre_id,
                    'starts_at'=>$row->starts_at,'ends_at'=>$row->ends_at,'reason'=>$row->reason,'created_at'=>now(),'updated_at'=>now()]);
                $begin=Carbon::parse($row->starts_at); $finish=Carbon::parse($row->ends_at);
                for ($day=$begin->copy()->startOfDay();$day->lt($finish);$day->addDay()) {
                    $from=$day->isSameDay($begin)?$begin->format('H:i:s'):'00:00:00';
                    $to=$day->isSameDay($finish)?$finish->format('H:i:s'):'23:59:59';
                    DB::table('schedule_exceptions')->insert(['centre_id'=>$row->centre_id,'branch_id'=>$row->branch_id,
                        'user_id'=>$row->counselor_id,'exception_date'=>$day->toDateString(),
                        'starts_at'=>$from,'ends_at'=>$to,'type'=>'unavailable','reason'=>'مرخصی تأییدشده: '.$row->reason,
                        'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
                }
                // Invalidate empty slots so the scheduler cannot offer stale availability.
                DB::table('appointment_slots')->where('counselor_id',$row->counselor_id)->where('centre_id',$row->centre_id)
                    ->where('starts_at','<',$row->ends_at)->where('ends_at','>',$row->starts_at)->where('booked_count',0)
                    ->update(['status'=>'blocked','updated_at'=>now()]);
            }
            DB::table('counselor_leave_requests')->where('id',$leave)->update(['status'=>$d['decision'],
                'reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),'review_note'=>$d['review_note']??null,'updated_at'=>now()]);
        });
        return back()->with('success','درخواست بررسی شد.');
    }
}
