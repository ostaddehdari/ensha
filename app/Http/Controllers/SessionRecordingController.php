<?php

namespace App\Http\Controllers;

use App\Jobs\TranscribeSessionRecording;
use App\Models\Appointment;
use App\Models\ClientConsent;
use App\Models\CounsellingSession;
use App\Models\SessionRecording;
use App\Models\CentreIntegration;
use App\Services\ClinicalAudioVault;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SessionRecordingController extends Controller
{
    private const MIME_TYPES = [
        'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'video/webm',
    ];

    public function consent(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        abort_unless(in_array($appointment->status, ['confirmed', 'arrived', 'in_session'], true), 422, 'رضایت ضبط برای این وضعیت نوبت قابل ثبت نیست.');
        $data = $request->validate([
            'confirmed' => ['accepted'],
            'capture_method' => ['required', Rule::in(['verbal', 'written', 'digital'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $consent = DB::transaction(function () use ($request, $appointment, $data) {
            ClientConsent::where('client_id', $appointment->client_id)
                ->where('consent_type', 'recording')->where('is_granted', true)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'is_granted' => false]);

            return ClientConsent::create([
                'client_id' => $appointment->client_id,
                'case_id' => $appointment->case_id,
                'consent_type' => 'recording',
                'document_version' => 'recording-v1.0',
                'is_granted' => true,
                'granted_at' => now(),
                'captured_by' => $request->user()->id,
                'evidence' => [
                    'appointment_id' => $appointment->id,
                    'capture_method' => $data['capture_method'],
                    'statement' => 'مراجع با ضبط صوت این جلسه، نگهداری امن و استفاده بالینی آن موافقت کرد.',
                    'ip' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                ],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        Audit::record('ثبت رضایت صریح ضبط جلسه', $request, 'warning', [
            'appointment_id' => $appointment->id,
            'consent_id' => $consent->id,
            'capture_method' => $data['capture_method'],
        ], $appointment, 'session.recording.consent');

        return back()->with('success', 'رضایت ضبط جلسه ثبت شد. اکنون امکان شروع ضبط وجود دارد.');
    }

    public function initialize(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        abort_unless($appointment->status === 'in_session', 422, 'ضبط فقط پس از شروع جلسه مجاز است.');
        $data = $request->validate([
            'consent_id' => ['required', 'integer'],
            'mime_type' => ['required', Rule::in(self::MIME_TYPES)],
        ]);
        $session = CounsellingSession::where('appointment_id', $appointment->id)->where('status', 'in_progress')->first();
        abort_unless($session, 422, 'جلسه فعال پیدا نشد. ابتدا جلسه را شروع کنید.');
        $consent = $this->validConsent($appointment, (int) $data['consent_id']);
        abort_unless($consent, 422, 'رضایت معتبر ضبط جلسه پیدا نشد.');

        $stale = SessionRecording::where('appointment_id', $appointment->id)
            ->where('counselor_id', $request->user()->id)->whereIn('status', ['uploading', 'sealing'])
            ->where('created_at', '<', now()->subMinutes(30))->get();
        foreach ($stale as $old) {
            Storage::disk('local')->deleteDirectory('ensha-audio-chunks/'.$old->public_id);
            $old->update(['status' => 'failed', 'transcription_error' => 'بارگذاری نیمه‌تمام منقضی شد.']);
        }

        $recording = SessionRecording::create([
            'public_id' => (string) Str::uuid(),
            'centre_id' => $appointment->centre_id,
            'appointment_id' => $appointment->id,
            'counselling_session_id' => $session->id,
            'client_id' => $appointment->client_id,
            'counselor_id' => $appointment->counselor_id,
            'consent_id' => $consent->id,
            'disk' => config('clinical_audio.disk', 'local'),
            'mime_type' => $data['mime_type'],
            'status' => 'uploading',
            'transcript_language' => config('clinical_audio.transcription.language', 'fa'),
            'consent_snapshot' => [
                'consent_id' => $consent->id,
                'document_version' => $consent->document_version,
                'granted_at' => $consent->granted_at?->toIso8601String(),
                'capture_method' => data_get($consent->evidence, 'capture_method'),
            ],
            'recorded_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        Audit::record('شروع ضبط امن جلسه', $request, 'warning', [
            'appointment_id' => $appointment->id,
            'recording_id' => $recording->public_id,
            'consent_id' => $consent->id,
        ], $recording, 'session.recording.started');

        return response()->json([
            'recording_id' => $recording->public_id,
            'chunk_url' => route('counselor.recordings.chunk', [$appointment, $recording]),
            'finalize_url' => route('counselor.recordings.finalize', [$appointment, $recording]),
        ], 201);
    }

    public function chunk(Request $request, Appointment $appointment, SessionRecording $recording)
    {
        $this->authorizeRecordingManage($request, $appointment, $recording);
        $maxChunkKb = max(1024, (int) ceil(config('clinical_audio.chunk_bytes', 6291456) / 1024));
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0', 'max:9999'],
            'chunk' => ['required', 'file', 'max:'.$maxChunkKb],
        ]);
        abort_unless($recording->status === 'uploading', 409, 'این ضبط دیگر قطعه جدید نمی‌پذیرد.');
        $index = (int) $data['index'];
        $name = str_pad((string) $index, 6, '0', STR_PAD_LEFT).'.part';
        $directory = 'ensha-audio-chunks/'.$recording->public_id;
        $path = $directory.'/'.$name;

        if (Storage::disk('local')->exists($path)) {
            return response()->json(['received' => $index, 'duplicate' => true]);
        }

        $incomingSize = (int) $request->file('chunk')->getSize();
        $maximum = (int) config('clinical_audio.max_bytes', 262144000);
        abort_if($recording->size_bytes + $incomingSize > $maximum, 413, 'حجم کل ضبط بیش از حد مجاز است.');
        $stored = $request->file('chunk')->storeAs($directory, $name, 'local');
        abort_unless($stored, 500, 'ذخیره قطعه صوت ناموفق بود.');

        DB::transaction(function () use ($recording, $incomingSize) {
            $locked = SessionRecording::whereKey($recording->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'uploading', 409, 'وضعیت ضبط هنگام بارگذاری تغییر کرده است.');
            abort_if($locked->size_bytes + $incomingSize > (int) config('clinical_audio.max_bytes', 262144000), 413, 'حجم کل ضبط بیش از حد مجاز است.');
            $locked->increment('received_chunks');
            $locked->increment('size_bytes', $incomingSize);
        });

        return response()->json(['received' => $index]);
    }

    public function finalize(Request $request, Appointment $appointment, SessionRecording $recording, ClinicalAudioVault $vault)
    {
        $this->authorizeRecordingManage($request, $appointment, $recording);
        $data = $request->validate([
            'chunk_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:43200'],
        ]);
        abort_unless($recording->status === 'uploading', 409, 'این ضبط قابل نهایی‌سازی نیست.');
        $recording->update(['status' => 'sealing', 'chunk_count' => $data['chunk_count']]);

        $chunkDirectory = 'ensha-audio-chunks/'.$recording->public_id;
        $chunks = [];
        for ($index = 0; $index < $data['chunk_count']; $index++) {
            $path = $chunkDirectory.'/'.str_pad((string) $index, 6, '0', STR_PAD_LEFT).'.part';
            if (! Storage::disk('local')->exists($path)) {
                $recording->update(['status' => 'uploading']);
                abort(422, "قطعه شماره {$index} دریافت نشده است.");
            }
            $chunks[] = Storage::disk('local')->path($path);
        }

        $extension = $this->extensionForMime((string) $recording->mime_type);
        $relative = 'ensha-audio/'.$recording->centre_id.'/'.now()->format('Y/m').'/'.$recording->public_id.'.eaudio';
        $absolute = Storage::disk('local')->path($relative);
        try {
            $result = $vault->sealChunks($chunks, $absolute);
            $recording->update([
                'disk' => 'local',
                'path' => $relative,
                'original_name' => 'session-'.$appointment->appointment_number.'.'.$extension,
                'size_bytes' => $result['size_bytes'],
                'sha256' => $result['sha256'],
                'plaintext_sha256' => $result['plaintext_sha256'],
                'duration_seconds' => $data['duration_seconds'],
                'received_chunks' => $data['chunk_count'],
                'status' => 'ready',
                'completed_at' => now(),
                'retention_expires_at' => now()->addDays((int) config('clinical_audio.retention_days',365)),
            ]);
            Storage::disk('local')->deleteDirectory($chunkDirectory);
        } catch (\Throwable $e) {
            $recording->update(['status' => 'failed', 'transcription_error' => Str::limit($e->getMessage(), 1000, '')]);
            report($e);
            abort(500, 'رمزگذاری و نهایی‌سازی فایل صوت ناموفق بود.');
        }

        Audit::record('تکمیل و رمزگذاری ضبط جلسه', $request, 'warning', [
            'appointment_id' => $appointment->id,
            'recording_id' => $recording->public_id,
            'duration_seconds' => $data['duration_seconds'],
            'size_bytes' => $result['size_bytes'],
            'sha256' => $result['sha256'],
        ], $recording, 'session.recording.completed');

        return response()->json(['message' => 'ضبط با موفقیت و به‌صورت رمزگذاری‌شده ذخیره شد.', 'reload' => true]);
    }

    public function retention(Request $request)
    {
        abort_unless($request->user()->hasPermission('session_recordings.retention'),403);
        $rows=SessionRecording::with(['appointment.client.user','counselor'])->where('centre_id',$request->user()->centre_id)
            ->whereNotNull('retention_expires_at')->orderBy('retention_expires_at')->paginate(50);
        $summary=['expired'=>SessionRecording::where('centre_id',$request->user()->centre_id)->where('legal_hold',false)->whereNull('purged_at')->where('retention_expires_at','<=',now())->count(),
            'held'=>SessionRecording::where('centre_id',$request->user()->centre_id)->where('legal_hold',true)->count(),
            'purged'=>SessionRecording::withTrashed()->where('centre_id',$request->user()->centre_id)->whereNotNull('purged_at')->count()];
        return view('counselor.recording-retention',compact('rows','summary'));
    }

    public function legalHold(Request $request,SessionRecording $recording)
    {
        abort_unless($request->user()->hasPermission('session_recordings.retention')&&(int)$recording->centre_id===(int)$request->user()->centre_id,403);
        $data=$request->validate(['reason'=>'required|string|min:10|max:2000']);
        $recording->update(['legal_hold'=>true,'legal_hold_reason'=>$data['reason'],'legal_hold_by'=>$request->user()->id,'legal_hold_at'=>now()]);
        DB::table('session_recording_retention_audits')->insert(['session_recording_id'=>$recording->id,'centre_id'=>$recording->centre_id,'actor_id'=>$request->user()->id,'action'=>'legal_hold','reason'=>$data['reason'],'file_sha256'=>$recording->sha256,'created_at'=>now(),'updated_at'=>now()]);
        return back()->with('success','Legal Hold فعال شد؛ فایل با Retention خودکار حذف نمی‌شود.');
    }

    public function releaseLegalHold(Request $request,SessionRecording $recording)
    {
        abort_unless($request->user()->hasPermission('session_recordings.retention')&&(int)$recording->centre_id===(int)$request->user()->centre_id,403);
        $data=$request->validate(['reason'=>'required|string|min:10|max:2000']);
        DB::table('session_recording_retention_audits')->insert(['session_recording_id'=>$recording->id,'centre_id'=>$recording->centre_id,'actor_id'=>$request->user()->id,'action'=>'legal_hold_released','reason'=>$data['reason'],'file_sha256'=>$recording->sha256,'created_at'=>now(),'updated_at'=>now()]);
        $recording->update(['legal_hold'=>false,'legal_hold_reason'=>null,'legal_hold_by'=>null,'legal_hold_at'=>null]);
        return back()->with('success','Legal Hold برداشته شد.');
    }

    public function stream(Request $request, SessionRecording $recording, ClinicalAudioVault $vault)
    {
        $this->authorizeRecordingView($request, $recording);
        abort_unless($recording->isReady(), 409, 'فایل صوت آماده پخش نیست.');
        abort_unless($recording->disk === 'local' && Storage::disk('local')->exists($recording->path), 404);
        $absolute = Storage::disk('local')->path($recording->path);
        abort_unless(hash_equals((string) $recording->sha256, (string) hash_file('sha256', $absolute)), 409, 'یکپارچگی فایل صوت تأیید نشد.');
        Audit::record('پخش صوت محرمانه جلسه', $request, 'warning', ['recording_id' => $recording->public_id], $recording, 'session.recording.streamed');

        return response()->stream(function () use ($vault, $absolute) {
            $vault->stream($absolute, function (string $plain) {
                echo $plain;
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            });
        }, 200, [
            'Content-Type' => $recording->mime_type ?: 'audio/webm',
            'Content-Disposition' => 'inline; filename="'.addslashes($recording->original_name ?: 'session-audio.webm').'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'none',
        ]);
    }

    public function requestTranscription(Request $request, SessionRecording $recording)
    {
        $this->authorizeRecordingTranscript($request, $recording);
        abort_unless($recording->isReady(), 409, 'فایل صوت آماده پیاده‌سازی نیست.');
        abort_if($recording->transcriptIsFinalized(), 409, 'متن این ضبط نهایی شده است.');
        $centreWhisper=CentreIntegration::where('centre_id',$recording->centre_id)->where('driver','whisper')->where('is_active',true)->first();
        $hasCentreWhisper=filled(data_get($centreWhisper?->settings,'endpoint'));
        abort_unless($hasCentreWhisper||filled(config('clinical_audio.transcription.endpoint')), 422, 'سرویس تبدیل صوت به متن تنظیم نشده است؛ متن را می‌توانید دستی ثبت کنید.');
        abort_if(in_array($recording->transcript_status, ['queued', 'processing'], true), 409, 'درخواست پیاده‌سازی قبلاً در صف قرار گرفته است.');
        $recording->update(['transcript_status' => 'queued', 'transcription_error' => null]);
        TranscribeSessionRecording::dispatch($recording->id)->onQueue('clinical');
        Audit::record('ارسال صوت جلسه برای پیاده‌سازی', $request, 'warning', ['recording_id' => $recording->public_id], $recording, 'session.recording.transcription.queued');
        return back()->with('success', 'درخواست تبدیل صوت به متن در صف قرار گرفت.');
    }

    public function transcript(Request $request, SessionRecording $recording)
    {
        $this->authorizeRecordingTranscript($request, $recording);
        abort_if($recording->transcriptIsFinalized(), 409, 'متن نهایی قابل ویرایش نیست.');
        $data = $request->validate([
            'transcript_text' => ['required', 'string', 'max:500000'],
            'action' => ['required', Rule::in(['save', 'finalize'])],
        ]);
        $finalized = $data['action'] === 'finalize';
        $recording->update([
            'transcript_text' => trim($data['transcript_text']),
            'transcript_status' => $finalized ? 'finalized' : 'draft',
            'transcribed_at' => $recording->transcribed_at ?: now(),
            'transcript_finalized_by' => $finalized ? $request->user()->id : null,
            'transcript_finalized_at' => $finalized ? now() : null,
            'transcript_hash' => $finalized ? hash('sha256', trim($data['transcript_text'])) : null,
            'transcription_error' => null,
        ]);
        Audit::record($finalized ? 'نهایی‌سازی متن صوت جلسه' : 'ذخیره پیش‌نویس متن صوت جلسه', $request, 'warning', [
            'recording_id' => $recording->public_id,
            'transcript_hash' => $recording->fresh()->transcript_hash,
        ], $recording, $finalized ? 'session.recording.transcript.finalized' : 'session.recording.transcript.saved');
        return back()->with('success', $finalized ? 'متن صوت نهایی و قفل شد.' : 'پیش‌نویس متن صوت ذخیره شد.');
    }

    public function destroy(Request $request, Appointment $appointment, SessionRecording $recording)
    {
        $this->authorizeRecordingManage($request, $appointment, $recording);
        abort_if($recording->legal_hold,409,'این ضبط تحت Legal Hold است و حذف آن مجاز نیست.');
        abort_if($recording->transcriptIsFinalized(), 409, 'ضبط دارای متن نهایی است و قابل حذف مستقیم نیست.');
        $metadata = ['recording_id' => $recording->public_id, 'sha256' => $recording->sha256, 'appointment_id' => $appointment->id];
        if ($recording->path) {
            Storage::disk($recording->disk)->delete($recording->path);
        }
        Storage::disk('local')->deleteDirectory('ensha-audio-chunks/'.$recording->public_id);
        $recording->update(['status' => 'deleted']);
        DB::table('session_recording_retention_audits')->insert(['session_recording_id'=>$recording->id,'centre_id'=>$recording->centre_id,'actor_id'=>$request->user()->id,'action'=>'manual_deleted','reason'=>'حذف دستی مجاز توسط مشاور','file_sha256'=>$recording->sha256,'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);
        $recording->delete();
        Audit::record('حذف ضبط جلسه', $request, 'warning', $metadata, $appointment, 'session.recording.deleted');
        return back()->with('success', 'ضبط جلسه حذف شد.');
    }

    private function validConsent(Appointment $appointment, int $consentId): ?ClientConsent
    {
        return ClientConsent::whereKey($consentId)->where('client_id', $appointment->client_id)
            ->where('consent_type', 'recording')->where('is_granted', true)->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString()))
            ->first();
    }

    private function authorizeManage(Request $request, Appointment $appointment): void
    {
        abort_unless($request->user()->hasPermission('session_recordings.manage'), 403);
        abort_unless(Appointment::visibleTo($request->user())->whereKey($appointment->id)->exists(), 404);
        abort_unless($request->user()->isSuperAdmin() || (int) $appointment->counselor_id === (int) $request->user()->id, 403);
    }

    private function authorizeRecordingManage(Request $request, Appointment $appointment, SessionRecording $recording): void
    {
        $this->authorizeManage($request, $appointment);
        abort_unless((int) $recording->appointment_id === (int) $appointment->id, 404);
        abort_unless($request->user()->isSuperAdmin() || (int) $recording->counselor_id === (int) $request->user()->id, 403);
    }

    private function authorizeRecordingView(Request $request, SessionRecording $recording): void
    {
        abort_unless($request->user()->hasPermission('session_recordings.view'), 403);
        abort_unless($request->user()->isSuperAdmin() || (int) $recording->counselor_id === (int) $request->user()->id, 404);
        abort_unless(Appointment::visibleTo($request->user())->whereKey($recording->appointment_id)->exists(), 404);
    }

    private function authorizeRecordingTranscript(Request $request, SessionRecording $recording): void
    {
        abort_unless($request->user()->hasPermission('session_transcripts.manage'), 403);
        $this->authorizeRecordingView($request, $recording);
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'audio/ogg' => 'ogg',
            'audio/mp4' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            default => 'webm',
        };
    }
}
