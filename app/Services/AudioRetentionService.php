<?php

namespace App\Services;

use App\Models\SessionRecording;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class AudioRetentionService
{
    public function purgeExpired(?int $centreId=null,?int $actorId=null,bool $dryRun=false): array
    {
        $query=SessionRecording::where('legal_hold',false)->whereNull('purged_at')->whereNotNull('retention_expires_at')->where('retention_expires_at','<=',now());
        if($centreId)$query->where('centre_id',$centreId);
        $result=['eligible'=>0,'purged'=>0,'missing'=>0,'failed'=>0];
        $query->orderBy('id')->chunkById(100,function($rows) use(&$result,$actorId,$dryRun){
            foreach($rows as $recording){
                $result['eligible']++;
                if($dryRun)continue;
                try{
                    $exists=$recording->path&&Storage::disk($recording->disk?:'local')->exists($recording->path);
                    if($exists&&!Storage::disk($recording->disk?:'local')->delete($recording->path))throw new \RuntimeException('حذف فایل از دیسک ناموفق بود.');
                    if(!$exists)$result['missing']++;
                    DB::transaction(function() use($recording,$actorId,$exists){
                        DB::table('session_recording_retention_audits')->insert(['session_recording_id'=>$recording->id,'centre_id'=>$recording->centre_id,'actor_id'=>$actorId,'action'=>'retention_purged','reason'=>$exists?'انقضای دوره نگهداری':'فایل پیش از حذف روی دیسک موجود نبود','file_sha256'=>$recording->sha256,'metadata'=>json_encode(['path'=>$recording->path,'disk'=>$recording->disk],JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);
                        $recording->update(['status'=>'purged','path'=>null,'purged_at'=>now(),'purged_by'=>$actorId,'purge_reason'=>'retention_expired']);
                    });
                    $result['purged']++;
                }catch(\Throwable $e){$result['failed']++;report($e);}
            }
        });
        return $result;
    }
}
