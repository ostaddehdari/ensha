<?php

namespace App\Jobs;

use App\Models\SessionRecording;
use App\Services\ClinicalAudioVault;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use App\Models\CentreIntegration;

class TranscribeSessionRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 900;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $recordingId) {}

    public function handle(ClinicalAudioVault $vault): void
    {
        $recording = SessionRecording::findOrFail($this->recordingId);
        if ($recording->transcriptIsFinalized()) {
            return;
        }
        $integration=CentreIntegration::where('centre_id',$recording->centre_id)->where('driver','whisper')->where('is_active',true)->first();
        $endpoint = (string) (data_get($integration?->settings,'endpoint') ?: config('clinical_audio.transcription.endpoint'));
        if ($endpoint === '') {
            throw new RuntimeException('نشانی سرویس تبدیل صوت به متن تنظیم نشده است.');
        }
        if (! $recording->isReady() || $recording->disk !== 'local' || ! Storage::disk('local')->exists($recording->path)) {
            throw new RuntimeException('فایل صوت آماده یا در دسترس نیست.');
        }

        $recording->update(['transcript_status' => 'processing', 'transcription_error' => null]);
        $temporary = null;
        $stream = null;
        try {
            $temporary = $vault->decryptToTemporary(Storage::disk('local')->path($recording->path));
            $stream = fopen($temporary, 'rb');
            $request = Http::timeout((int) config('clinical_audio.transcription.timeout', 900))
                ->acceptJson();
            $token=(string)($integration?->secret('api_key') ?: config('clinical_audio.transcription.token'));
            if (filled($token)) {
                $request = $request->withToken($token);
            }
            $response = $request->attach(
                'file',
                $stream,
                $recording->original_name ?: 'session-audio.webm',
                ['Content-Type' => $recording->mime_type ?: 'audio/webm']
            )->post($endpoint, [
                'model' => data_get($integration?->settings,'model') ?: config('clinical_audio.transcription.model', 'whisper-1'),
                'language' => $recording->transcript_language ?: 'fa',
                'response_format' => 'json',
            ])->throw();
            $text = $response->json('text') ?? $response->json('transcript') ?? data_get($response->json(), 'data.text');
            if (! is_string($text) || trim($text) === '') {
                throw new RuntimeException('پاسخ سرویس فاقد متن معتبر است.');
            }
            $recording->update([
                'transcript_text' => trim($text),
                'transcript_status' => 'draft',
                'transcription_provider' => config('clinical_audio.transcription.provider', 'whisper-compatible'),
                'transcription_error' => null,
                'transcribed_at' => now(),
            ]);
            Audit::record('تبدیل صوت جلسه به پیش‌نویس متن', null, 'warning', [
                'recording_id' => $recording->public_id,
                'provider' => $recording->transcription_provider,
            ], $recording, 'session.recording.transcribed', $recording->creator);
        } catch (\Throwable $e) {
            $recording->update(['transcript_status' => 'failed', 'transcription_error' => Str::limit($e->getMessage(), 2000, '')]);
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($temporary) {
                @unlink($temporary);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        SessionRecording::whereKey($this->recordingId)->update([
            'transcript_status' => 'failed',
            'transcription_error' => Str::limit($exception?->getMessage() ?: 'خطای نامشخص پردازش صوت', 2000, ''),
        ]);
    }
}
