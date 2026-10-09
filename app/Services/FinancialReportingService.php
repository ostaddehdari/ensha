<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialReportingService
{
    public function __construct(private JalaliDate $jalali) {}

    public function appointmentRows(int $centreId,string $from,string $to,?int $counselorId=null): Collection
    {
        return DB::table('appointments as a')
            ->join('users as counselor','counselor.id','=','a.counselor_id')
            ->join('clients as cl','cl.id','=','a.client_id')->join('users as client_user','client_user.id','=','cl.user_id')
            ->leftJoin('service_topics as topic','topic.id','=','a.topic_id')
            ->leftJoin('appointment_compensation_snapshots as snap','snap.appointment_id','=','a.id')
            ->where('a.centre_id',$centreId)->whereDate('a.starts_at','>=',$from)->whereDate('a.starts_at','<=',$to)
            ->when($counselorId,fn($q)=>$q->where('a.counselor_id',$counselorId))
            ->select(['a.id','a.appointment_number','a.starts_at','a.status','a.status_snapshot','a.base_price','a.discount_value_snapshot',
                'a.final_price','a.paid_amount','a.balance_amount','a.currency','a.counselor_id','counselor.name as counselor_name',
                'client_user.name as client_name','topic.name as topic_name','snap.centre_share_amount','snap.counselor_share_amount'])
            ->orderBy('a.starts_at')->get();
    }

    public function monthly(Collection $rows): Collection
    {
        return $rows->groupBy(fn($row)=>$this->jalali->monthKey($row->starts_at))->map(function($items,$key){
            return (object)['key'=>$key,'label'=>$this->jalali->monthLabel($key),'appointments'=>$items->count(),
                'final'=>(int)$items->sum('final_price'),'paid'=>(int)$items->sum('paid_amount'),'balance'=>(int)$items->sum('balance_amount'),
                'centre_share'=>(int)$items->sum(fn($r)=>(int)($r->centre_share_amount??0)),
                'counselor_share'=>(int)$items->sum(fn($r)=>(int)($r->counselor_share_amount??0))];
        })->sortKeysDesc()->values();
    }

    public function counselors(Collection $rows): Collection
    {
        return $rows->groupBy('counselor_id')->map(function($items){
            return (object)['counselor'=>$items->first()->counselor_name,'appointments'=>$items->count(),
                'final'=>(int)$items->sum('final_price'),'paid'=>(int)$items->sum('paid_amount'),'balance'=>(int)$items->sum('balance_amount'),
                'centre_share'=>(int)$items->sum(fn($r)=>(int)($r->centre_share_amount??0)),
                'counselor_share'=>(int)$items->sum(fn($r)=>(int)($r->counselor_share_amount??0))];
        })->sortByDesc('paid')->values();
    }

    public function cashRegisters(int $centreId,string $from,string $to): Collection
    {
        return DB::table('cash_register_sessions as s')->join('users as u','u.id','=','s.cashier_id')
            ->where('s.centre_id',$centreId)->whereDate('s.opened_at','>=',$from)->whereDate('s.opened_at','<=',$to)
            ->select('s.*','u.name as cashier_name')->orderByDesc('s.opened_at')->get();
    }

    public function settlements(int $centreId,string $from,string $to,?int $counselorId=null): Collection
    {
        return DB::table('counselor_settlements as s')->join('users as u','u.id','=','s.counselor_id')
            ->where('s.centre_id',$centreId)->whereDate('s.period_end','>=',$from)->whereDate('s.period_start','<=',$to)
            ->when($counselorId,fn($q)=>$q->where('s.counselor_id',$counselorId))
            ->select('s.*','u.name as counselor_name')->orderByDesc('s.period_end')->get();
    }

    public function collectionsMonthly(int $centreId,string $from,string $to): Collection
    {
        return DB::table('payment_transactions')
            ->where('centre_id',$centreId)->where('status','posted')
            ->whereDate('occurred_at','>=',$from)->whereDate('occurred_at','<=',$to)
            ->select('occurred_at','method','signed_amount')->orderBy('occurred_at')->get()
            ->groupBy(fn($row)=>$this->jalali->monthKey($row->occurred_at))
            ->map(function($items,$key){
                $method=fn(string $name)=>(int)$items->where('method',$name)->sum('signed_amount');
                return (object)['key'=>$key,'label'=>$this->jalali->monthLabel($key),'transactions'=>$items->count(),
                    'cash'=>$method('cash'),'card'=>$method('card'),'bank_transfer'=>$method('bank_transfer'),
                    'online'=>$method('online'),'other'=>$method('other')+$method('legacy'),
                    'net'=>(int)$items->sum('signed_amount')];
            })->sortKeysDesc()->values();
    }

    public function dashboard(int $centreId): array
    {
        [$start,$end,$monthKey]=$this->jalali->currentMonthRange();
        $monthRows=$this->appointmentRows($centreId,$start->toDateString(),$end->toDateString());
        $todayNet=(int)DB::table('payment_transactions')->where('centre_id',$centreId)->where('status','posted')->whereDate('occurred_at',today())->sum('signed_amount');
        $collections=$this->collectionsMonthly($centreId,$start->toDateString(),$end->toDateString());
        return ['month_key'=>$monthKey,'month_label'=>$this->jalali->monthLabel($monthKey),'start'=>$start,'end'=>$end,
            'today_net'=>$todayNet,'month_final'=>(int)$monthRows->sum('final_price'),'month_paid'=>(int)$collections->sum('net'),
            'month_balance'=>(int)$monthRows->sum('balance_amount'),'month_centre_share'=>(int)$monthRows->sum(fn($r)=>(int)($r->centre_share_amount??0)),
            'month_counselor_share'=>(int)$monthRows->sum(fn($r)=>(int)($r->counselor_share_amount??0)),
            'completed'=>$monthRows->where('status','completed')->count(),'open_registers'=>DB::table('cash_register_sessions')->where('centre_id',$centreId)->where('status','open')->count(),
            'approved_settlements'=>(int)DB::table('counselor_settlements')->where('centre_id',$centreId)->where('status','approved')->sum('payable_amount'),
            'counselors'=>$this->counselors($monthRows)->take(8),'months'=>$this->monthly($this->appointmentRows($centreId,now()->subMonths(11)->startOfMonth()->toDateString(),now()->toDateString()))];
    }
}
