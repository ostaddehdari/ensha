<?php

namespace App\Services;

use App\Models\FinancialSequence;
use App\Models\StaffPayrollItem;
use App\Models\StaffPayrollRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function __construct(private JalaliDate $jalali) {}

    public function calculate(int $centreId,string $from,string $to): Collection
    {
        $sessions=DB::table('staff_work_sessions')->where('centre_id',$centreId)->whereIn('status',['closed','corrected'])
            ->whereNotNull('ended_at')->whereDate('started_at','>=',$from)->whereDate('started_at','<=',$to)->orderBy('started_at')->get();
        $staffIds=$sessions->pluck('staff_id')->unique()->values();
        $users=User::whereIn('id',$staffIds)->get()->keyBy('id');
        $rules=DB::table('staff_pay_rules')->where('centre_id',$centreId)->whereIn('staff_id',$staffIds)->orderBy('valid_from')->get()->groupBy('staff_id');
        return $sessions->groupBy('staff_id')->map(function($staffSessions,$staffId) use($users,$rules){
            $days=$staffSessions->groupBy(fn($s)=>substr($s->started_at,0,10));
            $worked=$late=$early=$overtime=$shortfall=$hourlyPay=$overtimePay=$shiftPay=0; $usedRules=[]; $latestRule=null;
            foreach($days as $date=>$daySessions){
                $rule=collect($rules->get($staffId,collect()))->filter(fn($r)=>$r->valid_from<=$date&&(!$r->valid_until||$r->valid_until>=$date))->sortByDesc('valid_from')->first();
                $minutes=(int)$daySessions->sum('duration_minutes'); $worked+=$minutes;
                if(!$rule) continue;
                $latestRule=$rule; $usedRules[$rule->id]=$this->ruleSnapshot($rule);
                $target=(int)($rule->daily_target_minutes??0); $dayOver=$target?max(0,$minutes-$target):0;
                $overtime+=$dayOver; $shortfall+=$target?max(0,$target-$minutes):0;
                $first=Carbon::parse($daySessions->min('started_at')); $last=Carbon::parse($daySessions->max('ended_at'));
                if($rule->shift_starts_at){$expected=Carbon::parse($date.' '.$rule->shift_starts_at);$late+=max(0,$expected->diffInMinutes($first,false));}
                if($rule->shift_ends_at){$expected=Carbon::parse($date.' '.$rule->shift_ends_at);$early+=max(0,$last->diffInMinutes($expected,false));}
                if(in_array($rule->model,['hourly','combined'],true)) $hourlyPay+=(int)round($minutes*(int)$rule->hourly_amount/60);
                if(in_array($rule->model,['fixed_overtime','combined'],true)) $overtimePay+=(int)round($dayOver*(int)$rule->overtime_amount/60);
                if($rule->model==='shift') $shiftPay+=(int)$rule->base_amount;
            }
            $basePay=$latestRule&&in_array($latestRule->model,['fixed','fixed_overtime','combined'],true)?(int)$latestRule->base_amount:0;
            if($latestRule?->model==='shift') $basePay=$shiftPay;
            return (object)['staff_id'=>(int)$staffId,'staff_name'=>$users->get($staffId)?->display_name??('کارمند '.$staffId),
                'worked_minutes'=>$worked,'worked_days'=>$days->count(),'late_minutes'=>$late,'early_minutes'=>$early,
                'overtime_minutes'=>$overtime,'shortfall_minutes'=>$shortfall,'base_pay_amount'=>$basePay,
                'hourly_pay_amount'=>$hourlyPay,'overtime_pay_amount'=>$overtimePay,'payable_amount'=>$basePay+$hourlyPay+$overtimePay,
                'pay_rule_id'=>$latestRule?->id,'rule_snapshot'=>array_values($usedRules)];
        })->sortBy('staff_name')->values();
    }

    public function createRun(int $centreId,User $actor,string $from,string $to,?string $note=null): StaffPayrollRun
    {
        $this->assertCentreAccess($centreId,$actor);
        $start=Carbon::parse($from); $end=Carbon::parse($to);
        if($start->diffInDays($end)>35) throw ValidationException::withMessages(['period_end'=>'دوره حقوق نمی‌تواند بیشتر از ۳۵ روز باشد.']);
        $rows=$this->calculate($centreId,$from,$to);
        if($rows->isEmpty()) throw ValidationException::withMessages(['period_start'=>'در این دوره حضور بسته‌شده‌ای وجود ندارد.']);
        return DB::transaction(function() use($centreId,$actor,$from,$to,$note,$rows){
            DB::table('centres')->where('id',$centreId)->lockForUpdate()->firstOrFail();
            if(StaffPayrollRun::where('centre_id',$centreId)->whereDate('period_start',$from)->whereDate('period_end',$to)->exists()) {
                throw ValidationException::withMessages(['period_start'=>'برای این مرکز و بازه قبلاً یک دوره حقوق ساخته شده است.']);
            }
            $run=StaffPayrollRun::create(['public_id'=>(string)Str::uuid(),'run_number'=>$this->nextNumber($centreId),
                'centre_id'=>$centreId,'period_start'=>$from,'period_end'=>$to,'jalali_period'=>$this->jalali->monthKey($from),
                'status'=>'draft','total_payable_amount'=>(int)$rows->sum('payable_amount'),'total_worked_minutes'=>(int)$rows->sum('worked_minutes'),
                'staff_count'=>$rows->count(),'created_by'=>$actor->id,'note'=>$note]);
            foreach($rows as $row) StaffPayrollItem::create(['payroll_run_id'=>$run->id,'staff_id'=>$row->staff_id,'pay_rule_id'=>$row->pay_rule_id,
                'rule_snapshot'=>$row->rule_snapshot,'worked_minutes'=>$row->worked_minutes,'worked_days'=>$row->worked_days,
                'late_minutes'=>$row->late_minutes,'early_minutes'=>$row->early_minutes,'overtime_minutes'=>$row->overtime_minutes,
                'shortfall_minutes'=>$row->shortfall_minutes,'base_pay_amount'=>$row->base_pay_amount,'hourly_pay_amount'=>$row->hourly_pay_amount,
                'overtime_pay_amount'=>$row->overtime_pay_amount,'payable_amount'=>$row->payable_amount]);
            return $run->fresh(['items.staff']);
        },3);
    }

    public function lock(StaffPayrollRun $run,User $actor): StaffPayrollRun
    {
        $this->assertCentreAccess((int)$run->centre_id,$actor);
        return DB::transaction(function() use($run,$actor){
            $run=StaffPayrollRun::lockForUpdate()->findOrFail($run->id);
            if($run->status!=='draft') throw ValidationException::withMessages(['payroll'=>'این دوره قبلاً قفل شده است.']);
            $run->update(['status'=>'locked','locked_by'=>$actor->id,'locked_at'=>now()]);
            return $run->fresh();
        },3);
    }

    private function ruleSnapshot(object $rule): array { return ['id'=>$rule->id,'model'=>$rule->model,'base_amount'=>(int)$rule->base_amount,'hourly_amount'=>(int)$rule->hourly_amount,'overtime_amount'=>(int)$rule->overtime_amount,'daily_target_minutes'=>$rule->daily_target_minutes,'shift_starts_at'=>$rule->shift_starts_at,'shift_ends_at'=>$rule->shift_ends_at,'valid_from'=>$rule->valid_from,'valid_until'=>$rule->valid_until]; }
    private function assertCentreAccess(int $centreId,User $actor): void
    {
        abort_unless($actor->isSuperAdmin()||(int)$actor->centre_id===$centreId,403);
    }
    private function nextNumber(int $centreId): string
    {
        $year=(int)now()->format('Y');
        FinancialSequence::insertOrIgnore(['centre_id'=>$centreId,'sequence_key'=>'payroll','sequence_year'=>$year,'last_number'=>0,'created_at'=>now(),'updated_at'=>now()]);
        $sequence=FinancialSequence::where('centre_id',$centreId)->where('sequence_key','payroll')->where('sequence_year',$year)->lockForUpdate()->firstOrFail();
        $next=(int)$sequence->last_number+1; $sequence->update(['last_number'=>$next]);
        return sprintf('PAY-%d-%04d-%06d',$centreId,$year,$next);
    }
}
