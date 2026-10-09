<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\CentreIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class IntegrationController extends Controller
{
    private const DRIVERS=['wordpress','sms_kavenegar','whisper','zarinpal'];

    public function index(Request $request,Centre $centre)
    {
        $this->authorizeCentre($request,$centre);
        $integrations=CentreIntegration::where('centre_id',$centre->id)->get()->keyBy('driver');
        return view('centres.integrations',compact('centre','integrations'));
    }

    public function update(Request $request,Centre $centre,string $driver)
    {
        $this->authorizeCentre($request,$centre); abort_unless(in_array($driver,self::DRIVERS,true),404);
        $data=$request->validate(['is_active'=>'nullable|boolean','endpoint'=>'nullable|url|max:500','sender'=>'nullable|string|max:30','model'=>'nullable|string|max:100','api_key'=>'nullable|string|max:1000','merchant_id'=>'nullable|string|max:200']);
        $integration=CentreIntegration::firstOrNew(['centre_id'=>$centre->id,'driver'=>$driver]);
        $settings=array_filter(['endpoint'=>$data['endpoint']??null,'sender'=>$data['sender']??null,'model'=>$data['model']??null],fn($v)=>$v!==null&&$v!=='');
        $secrets=$integration->secret_payload?:[];
        foreach(['api_key','merchant_id'] as $key)if(filled($data[$key]??null))$secrets[$key]=$data[$key];
        if($driver==='wordpress'&&$request->boolean('is_active')&&mb_strlen((string)($secrets['api_key']??''))<32)return back()->withErrors(['integration'=>'برای فعال‌سازی WordPress یک کلید قوی حداقل ۳۲ کاراکتری وارد کنید.']);
        $integration->fill(['is_active'=>$request->boolean('is_active'),'settings'=>$settings,'secret_payload'=>$secrets])->save();
        return back()->with('success','تنظیمات اتصال ذخیره شد.');
    }

    public function check(Request $request,Centre $centre,string $driver)
    {
        $this->authorizeCentre($request,$centre);$integration=CentreIntegration::where('centre_id',$centre->id)->where('driver',$driver)->firstOrFail();
        try{
            if($driver==='sms_kavenegar')Http::timeout(15)->get('https://api.kavenegar.com/v1/'.rawurlencode((string)$integration->secret('api_key')).'/account/info.json')->throw();
            elseif($driver==='whisper'){
                $endpoint=(string)data_get($integration->settings,'endpoint');abort_unless($endpoint!=='',422,'Endpoint ثبت نشده است.');
                $pcm=str_repeat("\0",3200);$wav='RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,16000,32000,2,16).'data'.pack('V',strlen($pcm)).$pcm;
                $response=Http::withToken((string)$integration->secret('api_key'))->acceptJson()->timeout(30)->attach('file',$wav,'ensha-health.wav',['Content-Type'=>'audio/wav'])->post($endpoint,['model'=>data_get($integration->settings,'model','whisper-1'),'language'=>'fa','response_format'=>'json']);
                $response->throw();abort_unless(is_string($response->json('text')??$response->json('transcript')??data_get($response->json(),'data.text')),502,'پاسخ Whisper قالب متن معتبر ندارد.');
            }
            elseif($driver==='wordpress')abort_unless(filled($integration->secret('api_key')),422,'کلید API ساخته نشده است.');
            elseif($driver==='zarinpal')abort_unless(filled($integration->secret('merchant_id')),422,'Merchant ID ثبت نشده است.');
            $integration->update(['last_checked_at'=>now(),'last_status'=>'ok','last_error'=>null]);
            return back()->with('success','اتصال با موفقیت بررسی شد.');
        }catch(\Throwable $e){$integration->update(['last_checked_at'=>now(),'last_status'=>'failed','last_error'=>mb_substr($e->getMessage(),0,2000)]);return back()->withErrors(['integration'=>'بررسی اتصال ناموفق بود: '.$e->getMessage()]);}
    }

    private function authorizeCentre(Request $request,Centre $centre): void
    { abort_unless($request->user()->hasPermission('integrations.manage')&&($request->user()->isSuperAdmin()||(int)$request->user()->centre_id===(int)$centre->id),403); }
}
