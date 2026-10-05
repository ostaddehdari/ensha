<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffAttendanceController extends Controller
{
    public function index(Request $request)
    {
        $user=$request->user(); abort_unless($user->centre_id && $user->hasPermission('appointments.view'),403);
        $centre=(int) $user->centre_id;
        $mine=DB::table('staff_work_sessions')->where('centre_id',$centre)->where('staff_id',$user->id)
            ->where('started_at','>=',now()->startOfMonth()->startOfDay())->orderByDesc('started_at')->get();
        $open=$mine->firstWhere('status','open');
        $today=$this->minutes($mine,now()->startOfDay()); $week=$this->minutes($mine,now()->startOfWeek());
        $month=$this->minutes($mine,now()->startOfMonth());
        $isManager=$user->hasPermission('attendance.view');
        $query=DB::table('staff_work_sessions as s')->join('users as u','u.id','=','s.staff_id')->where('s.centre_id',$centre)
            ->when($request->filled('staff_id'),fn ($q) => $q->where('s.staff_id',$request->integer('staff_id')))
            ->when($request->filled('branch_id'),fn ($q) => $q->where('s.branch_id',$request->integer('branch_id')))
            ->when($request->filled('from'),fn ($q) => $q->whereDate('s.started_at','>=',$request->date('from')))
            ->when($request->filled('to'),fn ($q) => $q->whereDate('s.started_at','<=',$request->date('to')));
        $summary=['total_minutes'=>0,'days'=>0,'average_minutes'=>0,'late_minutes'=>0,'early_minutes'=>0,'overtime_minutes'=>0,'shortfall_minutes'=>0];
        if ($isManager) {
            $rows=(clone $query)->select('s.*')->orderByDesc('s.started_at')->limit(1000)->get();
            $summary['total_minutes']=$rows->sum('duration_minutes');
            $summary['days']=$rows->groupBy(fn ($s) => substr($s->started_at,0,10))->count();
            $summary['average_minutes']=$summary['days'] ? (int) round($summary['total_minutes']/$summary['days']) : 0;
            foreach ($rows as $s) {
                $rule=DB::table('staff_pay_rules')->where('centre_id',$centre)->where('staff_id',$s->staff_id)
                    ->where('valid_from','<=',substr($s->started_at,0,10))
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until','>=',substr($s->started_at,0,10)))
                    ->orderByDesc('valid_from')->first();
                if (! $rule || ! $s->ended_at) continue;
                $worked=(int) $s->duration_minutes; $target=(int) $rule->daily_target_minutes;
                if ($target) {
                    $summary['overtime_minutes']+=max(0,$worked-$target);
                    $summary['shortfall_minutes']+=max(0,$target-$worked);
                }
                if ($rule->shift_starts_at) $summary['late_minutes']+=max(0,Carbon::parse($s->started_at)->diffInMinutes(Carbon::parse(substr($s->started_at,0,10).' '.$rule->shift_starts_at),false)*-1);
                if ($rule->shift_ends_at) $summary['early_minutes']+=max(0,Carbon::parse($s->ended_at)->diffInMinutes(Carbon::parse(substr($s->started_at,0,10).' '.$rule->shift_ends_at),false));
            }
        }
        $report=$isManager ? $query->select('s.*','u.name')->orderByDesc('s.started_at')->paginate(40) : null;
        $branches=DB::table('centre_branches')->where('centre_id',$centre)->get();
        return view('operations.attendance',compact('mine','open','today','week','month','report','branches','isManager','summary'));
    }
    private function minutes($sessions, Carbon $from): int
    {
        return $sessions->filter(fn ($s) => Carbon::parse($s->started_at)->gte($from))
            ->sum(fn ($s) => $s->duration_minutes ?? max(0,Carbon::parse($s->started_at)->diffInMinutes(now())));
    }
    public function start(Request $request)
    {
        $user=$request->user(); abort_unless($user->centre_id && $user->hasPermission('appointments.view'),403);
        $d=$request->validate(['branch_id'=>'nullable|integer']);
        if (! empty($d['branch_id'])) abort_unless(DB::table('centre_branches')->where('id',$d['branch_id'])->where('centre_id',$user->centre_id)->exists(),422);
        DB::transaction(function () use ($request,$user,$d) {
            DB::table('users')->where('id',$user->id)->lockForUpdate()->first();
            if (DB::table('staff_work_sessions')->where('staff_id',$user->id)->where('status','open')->exists())
                throw ValidationException::withMessages(['session'=>'شیفت باز دارید.']);
            DB::table('staff_work_sessions')->insert(['centre_id'=>$user->centre_id,'branch_id'=>$d['branch_id']??null,'staff_id'=>$user->id,
                'started_at'=>now(),'started_ip'=>$request->ip(),'device_info'=>substr((string) $request->userAgent(),0,255),
                'status'=>'open','created_at'=>now(),'updated_at'=>now()]);
        });
        return back()->with('success','شروع کار ثبت شد.');
    }
    public function end(Request $request)
    {
        DB::transaction(function () use ($request) {
            $user=$request->user(); DB::table('users')->where('id',$user->id)->lockForUpdate()->first();
            $s=DB::table('staff_work_sessions')->where('staff_id',$user->id)->where('status','open')->lockForUpdate()->first();
            if (! $s) throw ValidationException::withMessages(['session'=>'شیفت بازی پیدا نشد.']);
            DB::table('staff_work_sessions')->where('id',$s->id)->update(['ended_at'=>now(),
                'ended_ip'=>$request->ip(),'duration_minutes'=>max(0,Carbon::parse($s->started_at)->diffInMinutes(now())),
                'status'=>'closed','updated_at'=>now()]);
        });
        return back()->with('success','پایان کار ثبت شد.');
    }
    public function correct(Request $request, int $session)
    {
        abort_unless($request->user()->hasPermission('attendance.manage'),403);
        $d=$request->validate(['started_at'=>'required|date','ended_at'=>'required|date|after:started_at','reason'=>'required|string|min:5|max:1000']);
        $s=DB::table('staff_work_sessions')->where('id',$session)->where('centre_id',$request->user()->centre_id)->firstOrFail();
        DB::transaction(function () use ($request,$s,$d) {
            DB::table('staff_work_session_audits')->insert(['session_id'=>$s->id,'actor_id'=>$request->user()->id,
                'old_start'=>$s->started_at,'old_end'=>$s->ended_at,'new_start'=>$d['started_at'],'new_end'=>$d['ended_at'],
                'reason'=>$d['reason'],'created_at'=>now()]);
            DB::table('staff_work_sessions')->where('id',$s->id)->update(['started_at'=>$d['started_at'],'ended_at'=>$d['ended_at'],
                'duration_minutes'=>Carbon::parse($d['started_at'])->diffInMinutes(Carbon::parse($d['ended_at'])),
                'status'=>'corrected','notes'=>$d['reason'],'updated_at'=>now()]);
        });
        return back()->with('success','اصلاح با سابقه حسابرسی ثبت شد.');
    }
    public function payRule(Request $request)
    {
        abort_unless($request->user()->hasPermission('attendance.manage'),403);
        $d=$request->validate(['staff_id'=>'required|integer','model'=>['required',Rule::in(['fixed','hourly','shift','fixed_overtime','combined'])],
            'base_amount'=>'required|integer|min:0','hourly_amount'=>'required|integer|min:0','overtime_amount'=>'required|integer|min:0',
            'valid_from'=>'required|date','valid_until'=>'nullable|date|after_or_equal:valid_from',
            'daily_target_minutes'=>'nullable|integer|min:1|max:1440','shift_starts_at'=>'nullable|date_format:H:i','shift_ends_at'=>'nullable|date_format:H:i']);
        abort_unless(DB::table('user_role_centres')->where('user_id',$d['staff_id'])->where('centre_id',$request->user()->centre_id)->exists(),422);
        DB::table('staff_pay_rules')->insert([...$d,'centre_id'=>$request->user()->centre_id,'created_at'=>now(),'updated_at'=>now()]);
        return back()->with('success','قاعده دستمزد ثبت شد.');
    }
}
