<?php

namespace App\Services;

use App\Models\CentreIntegration;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SmsProviderService
{
    public function send(SmsMessage $message): void
    {
        $integration=CentreIntegration::where('centre_id',$message->centre_id)->where('driver','sms_kavenegar')->where('is_active',true)->first();
        if(!$integration) throw new RuntimeException('Provider پیامک این مرکز فعال نیست.');
        $key=(string)$integration->secret('api_key'); if($key==='')throw new RuntimeException('کلید Kavenegar ثبت نشده است.');
        $response=Http::asForm()->timeout(20)->retry(2,500)->post('https://api.kavenegar.com/v1/'.rawurlencode($key).'/sms/send.json',[
            'receptor'=>$message->phone_number,'message'=>$message->body,'sender'=>data_get($integration->settings,'sender'),
        ])->throw();
        $entry=$response->json('entries.0');
        $message->update(['status'=>'sent','sent_at'=>now(),'provider_message_id'=>(string)data_get($entry,'messageid'),
            'provider_response'=>json_encode($response->json(),JSON_UNESCAPED_UNICODE),'last_error'=>null]);
    }

    public function sendDue(int $limit=100): array
    {
        $result=['sent'=>0,'failed'=>0];
        SmsMessage::where('status','queued')->where(fn($q)=>$q->whereNull('scheduled_for')->orWhere('scheduled_for','<=',now()))->orderBy('id')->limit($limit)->get()->each(function($message)use(&$result){
            $message->increment('attempts');$message->update(['last_attempt_at'=>now()]);
            try{$this->send($message);$result['sent']++;}catch(\Throwable $e){$message->update(['status'=>$message->attempts>=3?'failed':'queued','last_error'=>mb_substr($e->getMessage(),0,2000)]);$result['failed']++;report($e);}
        });
        return $result;
    }
}
