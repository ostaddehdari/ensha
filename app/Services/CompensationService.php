<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentCompensationSnapshot;
use App\Models\CompensationRule;
use App\Models\CounselorSettlement;
use App\Models\CounselorSettlementAdjustment;
use App\Models\CounselorSettlementItem;
use App\Models\FinancialSequence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompensationService
{
    public function createRule(int $centreId, User $actor, array $data): CompensationRule
    {
        return DB::transaction(function () use ($centreId,$actor,$data) {
            DB::table('centres')->where('id',$centreId)->lockForUpdate()->first();
            $from=Carbon::parse($data['valid_from'])->startOfDay();
            $scope=CompensationRule::where('centre_id',$centreId)
                ->where('topic_id',$data['topic_id']??null)
                ->where('counselor_id',$data['counselor_id']??null);
            $latest=(clone $scope)->lockForUpdate()->orderByDesc('version')->first();
            if ($latest && $from->lte($latest->valid_from)) {
                throw ValidationException::withMessages(['valid_from'=>'شروع نسخه جدید باید بعد از شروع آخرین نسخه این محدوده باشد.']);
            }
            if ($latest && (!$latest->valid_until || $latest->valid_until->gte($from))) {
                $latest->update(['valid_until'=>$from->copy()->subDay()->toDateString()]);
            }
            return CompensationRule::create([
                'public_id'=>(string)Str::uuid(),'centre_id'=>$centreId,'topic_id'=>$data['topic_id']??null,
                'counselor_id'=>$data['counselor_id']??null,'name'=>$data['name'],'beneficiary'=>$data['beneficiary'],
                'calculation_type'=>$data['calculation_type'],'value'=>(int)$data['value'],
                'version'=>(int)($latest?->version??0)+1,'valid_from'=>$from->toDateString(),
                'valid_until'=>$data['valid_until']??null,'is_active'=>true,'note'=>$data['note']??null,'created_by'=>$actor->id,
            ]);
        },3);
    }

    public function retireRule(CompensationRule $rule, User $actor): CompensationRule
    {
        $this->assertCentre((int)$rule->centre_id,$actor);
        if (!$rule->is_active) throw ValidationException::withMessages(['rule'=>'این قانون قبلاً غیرفعال شده است.']);
        $rule->update(['is_active'=>false,'valid_until'=>$rule->valid_until ?: today()->toDateString()]);
        return $rule->fresh();
    }

    public function snapshot(Appointment $appointment, ?int $actorId=null): AppointmentCompensationSnapshot
    {
        $existing=AppointmentCompensationSnapshot::where('appointment_id',$appointment->id)->first();
        if ($existing) return $existing;
        $appointment=Appointment::with(['topic','counselor'])->findOrFail($appointment->id);
        $date=($appointment->session_ended_at ?: $appointment->ends_at)->toDateString();
        $rule=$this->resolveRule($appointment,$date);
        if (!$rule) throw ValidationException::withMessages(['compensation_rule'=>'برای این مرکز قانون سهم فعالی وجود ندارد.']);
        [$centreShare,$counselorShare]=$this->calculate((int)$appointment->final_price,$rule);
        try {
            return AppointmentCompensationSnapshot::create([
                'public_id'=>(string)Str::uuid(),'appointment_id'=>$appointment->id,'centre_id'=>$appointment->centre_id,
                'counselor_id'=>$appointment->counselor_id,'topic_id'=>$appointment->topic_id,'rule_id'=>$rule->id,
                'rule_snapshot'=>[
                    'name'=>$rule->name,'version'=>$rule->version,'beneficiary'=>$rule->beneficiary,
                    'calculation_type'=>$rule->calculation_type,'value'=>(int)$rule->value,
                    'topic_id'=>$rule->topic_id,'counselor_id'=>$rule->counselor_id,
                    'valid_from'=>$rule->valid_from?->toDateString(),'valid_until'=>$rule->valid_until?->toDateString(),
                ],
                'gross_amount'=>(int)$appointment->final_price,
                'discount_amount'=>max(0,(int)$appointment->base_price-(int)$appointment->final_price),
                'collected_at_snapshot'=>(int)$appointment->paid_amount,'outstanding_at_snapshot'=>(int)$appointment->balance_amount,
                'centre_share_amount'=>$centreShare,'counselor_share_amount'=>$counselorShare,
                'currency'=>$appointment->currency?:'IRR','calculated_at'=>now(),'calculated_by'=>$actorId,'locked_at'=>now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return AppointmentCompensationSnapshot::where('appointment_id',$appointment->id)->firstOrFail();
        }
    }

    public function resolveRule(Appointment $appointment,string $date): ?CompensationRule
    {
        $rule = CompensationRule::effectiveOn($date)->where('centre_id',$appointment->centre_id)
            ->where(fn($q)=>$q->whereNull('topic_id')->orWhere('topic_id',$appointment->topic_id))
            ->where(fn($q)=>$q->whereNull('counselor_id')->orWhere('counselor_id',$appointment->counselor_id))
            ->orderByRaw('counselor_id IS NOT NULL DESC')->orderByRaw('topic_id IS NOT NULL DESC')
            ->orderByDesc('version')->first();
        if ($rule) return $rule;
        return CompensationRule::firstOrCreate(
            ['centre_id'=>$appointment->centre_id,'topic_id'=>null,'counselor_id'=>null,'beneficiary'=>'counselor','version'=>1],
            ['public_id'=>(string)Str::uuid(),'name'=>'قانون پیش‌فرض سهم مشاور','calculation_type'=>'percentage','value'=>7000,
             'valid_from'=>'2000-01-01','is_active'=>true,'note'=>'قانون پیش‌فرض خودکار ۷۰٪ مشاور']
        );
    }

    public function createSettlement(int $centreId,User $actor,int $counselorId,string $start,string $end,array $adjustments=[],?string $note=null): CounselorSettlement
    {
        return DB::transaction(function() use($centreId,$actor,$counselorId,$start,$end,$adjustments,$note) {
            DB::table('users')->where('id',$counselorId)->lockForUpdate()->first();
            $isCounselor=DB::table('user_role_centres as urc')->join('roles as r','r.id','=','urc.role_id')
                ->where('urc.user_id',$counselorId)->where('urc.centre_id',$centreId)->where('r.slug','counselor')->exists();
            if (!$isCounselor) throw ValidationException::withMessages(['counselor_id'=>'مشاور متعلق به این مرکز نیست.']);
            $snapshots=AppointmentCompensationSnapshot::with('appointment')
                ->where('centre_id',$centreId)->where('counselor_id',$counselorId)
                ->whereHas('appointment',fn($q)=>$q->where('status','completed')->whereDate('starts_at','>=',$start)->whereDate('starts_at','<=',$end))
                ->orderBy('id')->lockForUpdate()->get();
            $lines=[];
            foreach($snapshots as $snapshot) {
                $appointment=$snapshot->appointment;
                $gross=(int)$snapshot->gross_amount;
                $collected=min($gross,max(0,(int)$appointment->paid_amount));
                $eligibleCounselor=$gross>0?(int)floor((float)$snapshot->counselor_share_amount*((float)$collected/(float)$gross)):0;
                $prior=CounselorSettlementItem::where('appointment_id',$appointment->id)
                    ->whereHas('settlement',fn($q)=>$q->where('status','!=','cancelled'))
                    ->selectRaw('COALESCE(SUM(collected_amount),0) collected, COALESCE(SUM(counselor_share_amount),0) counselor')->first();
                $collectedDelta=max(0,$collected-(int)$prior->collected);
                $counselorDelta=max(0,$eligibleCounselor-(int)$prior->counselor);
                if ($collectedDelta===0 || $counselorDelta===0) continue;
                $lines[]=['snapshot'=>$snapshot,'appointment'=>$appointment,'collected'=>$collectedDelta,
                    'counselor'=>$counselorDelta,'centre'=>max(0,$collectedDelta-$counselorDelta),'eligible'=>$eligibleCounselor];
            }
            if (!$lines) throw ValidationException::withMessages(['period'=>'در این دوره سهم وصول‌شده و تسویه‌نشده‌ای وجود ندارد.']);
            $counselorTotal=array_sum(array_column($lines,'counselor'));
            $centreTotal=array_sum(array_column($lines,'centre'));
            $collectedTotal=array_sum(array_column($lines,'collected'));
            $deductions=collect($adjustments)->where('kind','deduction')->sum('amount');
            $bonuses=collect($adjustments)->where('kind','bonus')->sum('amount');
            $payable=max(0,$counselorTotal-(int)$deductions+(int)$bonuses);
            $settlement=CounselorSettlement::create([
                'public_id'=>(string)Str::uuid(),'settlement_number'=>$this->nextSettlementNumber($centreId),
                'centre_id'=>$centreId,'counselor_id'=>$counselorId,'period_start'=>$start,'period_end'=>$end,'status'=>'draft',
                'gross_collected_amount'=>$collectedTotal,'centre_share_amount'=>$centreTotal,'counselor_share_amount'=>$counselorTotal,
                'deductions_amount'=>$deductions,'bonuses_amount'=>$bonuses,'payable_amount'=>$payable,'paid_amount'=>0,
                'currency'=>'IRR','note'=>$note,'created_by'=>$actor->id,
            ]);
            foreach($lines as $line) CounselorSettlementItem::create([
                'settlement_id'=>$settlement->id,'appointment_id'=>$line['appointment']->id,'compensation_snapshot_id'=>$line['snapshot']->id,
                'collected_amount'=>$line['collected'],'centre_share_amount'=>$line['centre'],'counselor_share_amount'=>$line['counselor'],
                'calculation_snapshot'=>['gross_amount'=>(int)$line['snapshot']->gross_amount,'paid_amount'=>(int)$line['appointment']->paid_amount,
                    'eligible_counselor_share'=>$line['eligible'],'rule'=>$line['snapshot']->rule_snapshot,'calculated_at'=>now()->toIso8601String()],
            ]);
            foreach($adjustments as $adjustment) CounselorSettlementAdjustment::create([
                'settlement_id'=>$settlement->id,'kind'=>$adjustment['kind'],'title'=>$adjustment['title'],
                'amount'=>(int)$adjustment['amount'],'note'=>$adjustment['note']??null,'created_by'=>$actor->id,
            ]);
            return $settlement->fresh(['counselor','items.appointment','adjustments']);
        },3);
    }

    public function approve(CounselorSettlement $settlement,User $actor): CounselorSettlement
    {
        return DB::transaction(function() use($settlement,$actor) {
            $settlement=CounselorSettlement::lockForUpdate()->findOrFail($settlement->id);
            $this->assertCentre((int)$settlement->centre_id,$actor);
            if($settlement->status!=='draft') throw ValidationException::withMessages(['settlement'=>'فقط پیش‌نویس قابل تأیید است.']);
            $settlement->update(['status'=>'approved','approved_by'=>$actor->id,'approved_at'=>now()]);
            return $settlement->fresh();
        },3);
    }

    public function markPaid(CounselorSettlement $settlement,User $actor,string $reference): CounselorSettlement
    {
        return DB::transaction(function() use($settlement,$actor,$reference) {
            $settlement=CounselorSettlement::lockForUpdate()->findOrFail($settlement->id);
            $this->assertCentre((int)$settlement->centre_id,$actor);
            if($settlement->status!=='approved') throw ValidationException::withMessages(['settlement'=>'فقط تسویه تأییدشده قابل پرداخت است.']);
            $settlement->update(['status'=>'paid','paid_amount'=>$settlement->payable_amount,'paid_by'=>$actor->id,'paid_at'=>now(),'payment_reference'=>$reference]);
            return $settlement->fresh();
        },3);
    }

    public function cancel(CounselorSettlement $settlement,User $actor,string $reason): CounselorSettlement
    {
        return DB::transaction(function() use($settlement,$actor,$reason) {
            $settlement=CounselorSettlement::lockForUpdate()->findOrFail($settlement->id);
            $this->assertCentre((int)$settlement->centre_id,$actor);
            if($settlement->status!=='draft') throw ValidationException::withMessages(['settlement'=>'فقط پیش‌نویس قابل لغو است.']);
            $settlement->update(['status'=>'cancelled','cancelled_by'=>$actor->id,'cancelled_at'=>now(),'cancellation_reason'=>$reason]);
            return $settlement->fresh();
        },3);
    }

    private function calculate(int $gross,CompensationRule $rule): array
    {
        $specified=$rule->calculation_type==='percentage'
            ?(int)floor($gross*(min(10000,(int)$rule->value)/10000))
            :min($gross,(int)$rule->value);
        return $rule->beneficiary==='centre' ? [$specified,max(0,$gross-$specified)] : [max(0,$gross-$specified),$specified];
    }

    private function nextSettlementNumber(int $centreId): string
    {
        $year=(int)now()->format('Y');
        FinancialSequence::insertOrIgnore(['centre_id'=>$centreId,'sequence_key'=>'settlement','sequence_year'=>$year,'last_number'=>0,'created_at'=>now(),'updated_at'=>now()]);
        $sequence=FinancialSequence::where('centre_id',$centreId)->where('sequence_key','settlement')->where('sequence_year',$year)->lockForUpdate()->firstOrFail();
        $next=(int)$sequence->last_number+1; $sequence->update(['last_number'=>$next]);
        return sprintf('SET-%d-%04d-%06d',$centreId,$year,$next);
    }

    private function assertCentre(int $centreId,User $actor): void
    {
        if(!$actor->isSuperAdmin() && (int)$actor->centre_id!==$centreId) abort(403);
    }
}
