<?php

namespace App\Http\Controllers;

use App\Models\StaffPayrollRun;
use App\Services\JalaliDate;
use App\Services\PayrollService;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollReportController extends Controller
{
    public function index(Request $request,PayrollService $payroll,JalaliDate $jalali)
    {
        $this->authorizePermission($request,'payroll.view');
        [$defaultFrom,$defaultTo]=$jalali->currentMonthRange();
        $data=$request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from']);
        $from=$data['from']??$defaultFrom->toDateString(); $to=$data['to']??$defaultTo->toDateString();
        $rows=$payroll->calculate((int)$request->user()->centre_id,$from,$to);
        $runs=StaffPayrollRun::with('creator')->where('centre_id',$request->user()->centre_id)->latest()->limit(20)->get();
        $summary=['staff'=>$rows->count(),'worked'=>(int)$rows->sum('worked_minutes'),'overtime'=>(int)$rows->sum('overtime_minutes'),'shortfall'=>(int)$rows->sum('shortfall_minutes'),'payable'=>(int)$rows->sum('payable_amount')];
        return view('reports.payroll',compact('from','to','rows','runs','summary'));
    }

    public function store(Request $request,PayrollService $payroll)
    {
        $this->authorizePermission($request,'payroll.manage');
        $data=$request->validate(['from'=>'required|date','to'=>'required|date|after_or_equal:from','note'=>'nullable|string|max:2000']);
        $run=$payroll->createRun((int)$request->user()->centre_id,$request->user(),$data['from'],$data['to'],$data['note']??null);
        return redirect()->route('reports.payroll.runs.show',$run)->with('success','دوره حقوق Snapshot شد.');
    }

    public function show(Request $request,StaffPayrollRun $run)
    {
        $this->authorizeRun($request,$run,'payroll.view');
        $run->load(['items.staff','creator','locker']);
        return view('reports.payroll-run',compact('run'));
    }

    public function lock(Request $request,StaffPayrollRun $run,PayrollService $payroll)
    {
        $this->authorizeRun($request,$run,'payroll.manage');
        $payroll->lock($run,$request->user());
        return back()->with('success','دوره حقوق قفل شد و دیگر قابل تغییر نیست.');
    }

    public function exportLive(Request $request,PayrollService $payroll,SimpleXlsxExporter $xlsx,JalaliDate $jalali)
    {
        $this->authorizePermission($request,'payroll.export');
        $data=$request->validate(['from'=>'required|date','to'=>'required|date|after_or_equal:from']);
        $rows=$payroll->calculate((int)$request->user()->centre_id,$data['from'],$data['to']);
        return $this->download($request,$xlsx,$jalali,$rows,$data['from'],$data['to'],'payroll-live');
    }

    public function exportRun(Request $request,StaffPayrollRun $run,SimpleXlsxExporter $xlsx,JalaliDate $jalali)
    {
        $this->authorizeRun($request,$run,'payroll.export');
        $run->load('items.staff');
        $rows=$run->items->map(fn($i)=>(object)['staff_name'=>$i->staff?->display_name,'worked_minutes'=>$i->worked_minutes,'worked_days'=>$i->worked_days,'late_minutes'=>$i->late_minutes,'early_minutes'=>$i->early_minutes,'overtime_minutes'=>$i->overtime_minutes,'shortfall_minutes'=>$i->shortfall_minutes,'base_pay_amount'=>$i->base_pay_amount,'hourly_pay_amount'=>$i->hourly_pay_amount,'overtime_pay_amount'=>$i->overtime_pay_amount,'payable_amount'=>$i->payable_amount]);
        return $this->download($request,$xlsx,$jalali,$rows,$run->period_start->toDateString(),$run->period_end->toDateString(),$run->run_number);
    }

    private function download(Request $request,SimpleXlsxExporter $xlsx,JalaliDate $jalali,$rows,string $from,string $to,string $key)
    {
        $headers=['کارمند','از تاریخ شمسی','تا تاریخ شمسی','روز کاری','کارکرد دقیقه','تأخیر','خروج زودتر','اضافه‌کار','کسری','حقوق پایه/شیفت','حقوق ساعتی','مبلغ اضافه‌کار','قابل پرداخت'];
        $data=$rows->map(fn($r)=>[$r->staff_name,$jalali->format($from),$jalali->format($to),$r->worked_days,$r->worked_minutes,$r->late_minutes,$r->early_minutes,$r->overtime_minutes,$r->shortfall_minutes,$r->base_pay_amount,$r->hourly_pay_amount,$r->overtime_pay_amount,$r->payable_amount]);
        $file=$xlsx->create('کارکرد و حقوق',$headers,$data); $name='ensha-'.$key.'-'.$from.'-'.$to.'.xlsx';
        DB::table('financial_report_exports')->insert(['public_id'=>(string)Str::uuid(),'centre_id'=>$request->user()->centre_id,'actor_id'=>$request->user()->id,'report_key'=>'payroll','filters'=>json_encode(['from'=>$from,'to'=>$to],JSON_UNESCAPED_UNICODE),'row_count'=>$file['rows'],'format'=>'xlsx','file_name'=>$name,'sha256'=>$file['sha256'],'exported_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        return response()->download($file['path'],$name,['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    private function authorizePermission(Request $request,string $permission): void { abort_unless($request->user()->centre_id&&$request->user()->hasPermission($permission),403); }
    private function authorizeRun(Request $request,StaffPayrollRun $run,string $permission): void { $this->authorizePermission($request,$permission); abort_unless((int)$run->centre_id===(int)$request->user()->centre_id,403); }
}
