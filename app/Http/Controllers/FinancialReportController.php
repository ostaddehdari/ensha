<?php

namespace App\Http\Controllers;

use App\Services\FinancialReportingService;
use App\Services\JalaliDate;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialReportController extends Controller
{
    public function dashboard(Request $request, FinancialReportingService $reports)
    {
        $this->authorizeCentre($request,'finance.analytics.view');
        $dashboard=$reports->dashboard((int)$request->user()->centre_id);
        return view('reports.dashboard',compact('dashboard'));
    }

    public function index(Request $request, FinancialReportingService $reports)
    {
        $this->authorizeCentre($request,'finance.analytics.view');
        [$from,$to,$counselorId]=$this->filters($request);
        $rows=$reports->appointmentRows((int)$request->user()->centre_id,$from,$to,$counselorId);
        $monthly=$reports->monthly($rows); $counselors=$reports->counselors($rows);
        $debtors=$rows->where('balance_amount','>',0)->sortByDesc('balance_amount')->values();
        $cash=$reports->cashRegisters((int)$request->user()->centre_id,$from,$to);
        $collections=$reports->collectionsMonthly((int)$request->user()->centre_id,$from,$to);
        $settlements=$reports->settlements((int)$request->user()->centre_id,$from,$to,$counselorId);
        $counselorOptions=$this->counselors((int)$request->user()->centre_id);
        $summary=['appointments'=>$rows->count(),'final'=>(int)$rows->sum('final_price'),'paid'=>(int)$rows->sum('paid_amount'),
            'balance'=>(int)$rows->sum('balance_amount'),'centre_share'=>(int)$rows->sum(fn($r)=>(int)($r->centre_share_amount??0)),
            'counselor_share'=>(int)$rows->sum(fn($r)=>(int)($r->counselor_share_amount??0))];
        return view('reports.financial',compact('from','to','counselorId','rows','monthly','collections','counselors','debtors','cash','settlements','counselorOptions','summary'));
    }

    public function export(Request $request,string $type,FinancialReportingService $reports,SimpleXlsxExporter $xlsx,JalaliDate $jalali)
    {
        $this->authorizeCentre($request,'finance.analytics.export');
        abort_unless(in_array($type,['appointments','monthly','collections','counselors','debtors','cash','settlements'],true),404);
        [$from,$to,$counselorId]=$this->filters($request);
        $centreId=(int)$request->user()->centre_id;
        $appointments=$reports->appointmentRows($centreId,$from,$to,$counselorId);
        [$title,$headers,$rows]=match($type){
            'appointments'=>['مالی مشاوره‌ها',['شماره نوبت','تاریخ شمسی','مراجع','مشاور','موضوع','وضعیت','تعرفه','تخفیف','مبلغ نهایی','وصول','مانده','سهم مرکز','سهم مشاور'],
                $appointments->map(fn($r)=>[$r->appointment_number,$jalali->format($r->starts_at),$r->client_name,$r->counselor_name,$r->topic_name,$r->status_snapshot?:$r->status,$r->base_price,$r->discount_value_snapshot,$r->final_price,$r->paid_amount,$r->balance_amount,$r->centre_share_amount??0,$r->counselor_share_amount??0])],
            'monthly'=>['گزارش ماه‌های شمسی',['ماه شمسی','تعداد مشاوره','مبلغ نهایی','وصول','مانده','سهم مرکز','سهم مشاور'],
                $reports->monthly($appointments)->map(fn($r)=>[$r->label,$r->appointments,$r->final,$r->paid,$r->balance,$r->centre_share,$r->counselor_share])],
            'collections'=>['وصول ماه‌های شمسی',['ماه شمسی','تعداد تراکنش','نقد','کارت‌خوان','انتقال بانکی','آنلاین','سایر/قدیمی','خالص وصول'],
                $reports->collectionsMonthly($centreId,$from,$to)->map(fn($r)=>[$r->label,$r->transactions,$r->cash,$r->card,$r->bank_transfer,$r->online,$r->other,$r->net])],
            'counselors'=>['گزارش مشاوران',['مشاور','تعداد مشاوره','مبلغ نهایی','وصول','مانده','سهم مرکز','سهم مشاور'],
                $reports->counselors($appointments)->map(fn($r)=>[$r->counselor,$r->appointments,$r->final,$r->paid,$r->balance,$r->centre_share,$r->counselor_share])],
            'debtors'=>['گزارش بدهکاران',['شماره نوبت','تاریخ شمسی','مراجع','مشاور','مبلغ نهایی','وصول','مانده'],
                $appointments->where('balance_amount','>',0)->map(fn($r)=>[$r->appointment_number,$jalali->format($r->starts_at),$r->client_name,$r->counselor_name,$r->final_price,$r->paid_amount,$r->balance_amount])],
            'cash'=>['گزارش صندوق',['شماره صندوق','صندوقدار','شروع شمسی','وضعیت','ابتدای صندوق','دریافت نقد','برگشت نقد','غیرنقد','مورد انتظار','شمارش‌شده','اختلاف'],
                $reports->cashRegisters($centreId,$from,$to)->map(fn($r)=>[$r->session_number,$r->cashier_name,$jalali->format($r->opened_at),$r->status,$r->opening_cash_amount,$r->cash_payments_amount,$r->cash_refunds_amount,$r->non_cash_net_amount,$r->expected_cash_amount,$r->counted_cash_amount,$r->difference_amount])],
            'settlements'=>['گزارش تسویه مشاوران',['شماره تسویه','مشاور','از تاریخ شمسی','تا تاریخ شمسی','وصول','سهم مرکز','سهم مشاور','کسورات','پاداش','قابل پرداخت','پرداخت‌شده','وضعیت'],
                $reports->settlements($centreId,$from,$to,$counselorId)->map(fn($r)=>[$r->settlement_number,$r->counselor_name,$jalali->format($r->period_start),$jalali->format($r->period_end),$r->gross_collected_amount,$r->centre_share_amount,$r->counselor_share_amount,$r->deductions_amount,$r->bonuses_amount,$r->payable_amount,$r->paid_amount,$r->status])],
        };
        $file=$xlsx->create($title,$headers,$rows);
        $name='ensha-'.$type.'-'.$from.'-'.$to.'.xlsx';
        $this->auditExport($request,$type,['from'=>$from,'to'=>$to,'counselor_id'=>$counselorId],$file,$name);
        return response()->download($file['path'],$name,['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    private function filters(Request $request): array
    {
        $data=$request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','counselor_id'=>'nullable|integer']);
        $from=$data['from']??now()->subMonths(5)->startOfMonth()->toDateString(); $to=$data['to']??now()->toDateString();
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to))>740,422,'بازه گزارش حداکثر دو سال است.');
        $counselorId=isset($data['counselor_id'])?(int)$data['counselor_id']:null;
        if($counselorId) abort_unless($this->counselors((int)$request->user()->centre_id)->contains('id',$counselorId),422,'مشاور متعلق به مرکز نیست.');
        return [$from,$to,$counselorId];
    }

    private function counselors(int $centreId){ return DB::table('users as u')->join('user_role_centres as urc','urc.user_id','=','u.id')->join('roles as r','r.id','=','urc.role_id')->where('urc.centre_id',$centreId)->where('r.slug','counselor')->select('u.id','u.name')->distinct()->orderBy('u.name')->get(); }
    private function authorizeCentre(Request $request,string $permission): void { abort_unless($request->user()->centre_id&&$request->user()->hasPermission($permission),403); }
    private function auditExport(Request $request,string $key,array $filters,array $file,string $name): void { DB::table('financial_report_exports')->insert(['public_id'=>(string)Str::uuid(),'centre_id'=>$request->user()->centre_id,'actor_id'=>$request->user()->id,'report_key'=>$key,'filters'=>json_encode($filters,JSON_UNESCAPED_UNICODE),'row_count'=>$file['rows'],'format'=>'xlsx','file_name'=>$name,'sha256'=>$file['sha256'],'exported_at'=>now(),'created_at'=>now(),'updated_at'=>now()]); }
}
