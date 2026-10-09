<section class="report-section recording-panel" data-recording-panel>
    <div class="recording-title">
        <div>
            <span class="ensha-eyebrow">Stage 08 · صوت محرمانه</span>
            <h3>ضبط و متن جلسه</h3>
        </div>
        <span class="recording-security"><i class="ki-filled ki-shield-tick"></i> رمزگذاری سرتاسری در فضای خصوصی</span>
    </div>

    @if(! $recordingConsent)
        <div class="consent-required">
            <strong>پیش از ضبط، رضایت صریح مراجع الزامی است.</strong>
            <p>به مراجع توضیح دهید که صوت جلسه با هدف مستندسازی بالینی، در فضای خصوصی مرکز و به‌صورت رمزگذاری‌شده نگهداری می‌شود.</p>
        </div>
        @if($canRecord && in_array($appointment->status, ['confirmed','arrived','in_session'], true))
            <form method="post" action="{{ route('counselor.recordings.consent', $appointment) }}" class="consent-form">
                @csrf
                <label>شیوه دریافت رضایت
                    <select class="kt-select" name="capture_method" required>
                        <option value="verbal">شفاهی در حضور مشاور</option>
                        <option value="written">فرم کتبی</option>
                        <option value="digital">تأیید دیجیتال</option>
                    </select>
                </label>
                <label>توضیح اختیاری
                    <input class="kt-input" name="notes" maxlength="1000" placeholder="مثلاً رضایت در ابتدای جلسه اعلام شد">
                </label>
                <label class="consent-check"><input type="checkbox" name="confirmed" value="1" required> تأیید می‌کنم مراجع پس از دریافت توضیحات، صریحاً با ضبط این جلسه موافقت کرد.</label>
                <button class="kt-btn kt-btn-primary"><i class="ki-filled ki-check-circle"></i> ثبت رضایت ضبط</button>
            </form>
        @endif
    @else
        <div class="consent-valid">
            <i class="ki-filled ki-shield-tick"></i>
            <div><strong>رضایت معتبر ثبت شده است</strong><small>ثبت در <span data-jalali-datetime="{{ $recordingConsent->granted_at?->toIso8601String() }}">{{ $recordingConsent->granted_at?->format('Y-m-d H:i') }}</span> · {{ ['verbal'=>'شفاهی','written'=>'کتبی','digital'=>'دیجیتال'][data_get($recordingConsent->evidence,'capture_method')] ?? 'ثبت‌شده در پرونده' }}</small></div>
        </div>

        @if($canRecord && $appointment->status === 'in_session')
            <div class="audio-recorder"
                 data-audio-recorder
                 data-init-url="{{ route('counselor.recordings.initialize', $appointment) }}"
                 data-consent-id="{{ $recordingConsent->id }}">
                <div class="recorder-main">
                    <button type="button" class="record-button" data-record-start><span></span> شروع ضبط</button>
                    <button type="button" class="kt-btn kt-btn-danger" data-record-stop hidden><i class="ki-filled ki-stop"></i> توقف و ذخیره امن</button>
                    <strong class="recording-timer" data-record-timer>00:00</strong>
                </div>
                <div class="recording-progress" data-record-progress hidden><span></span></div>
                <p class="recording-state" data-record-state>برای آغاز ضبط، اجازه دسترسی مرورگر به میکروفن را تأیید کنید.</p>
                <p class="recording-error" data-record-error role="alert" hidden></p>
            </div>
        @elseif($canRecord && $appointment->status !== 'completed')
            <div class="recording-hint"><i class="ki-filled ki-information-2"></i> برای فعال‌شدن ضبط، ابتدا دکمه «شروع جلسه» را بزنید.</div>
        @endif
    @endif

    <div class="recording-list">
        <h4>ضبط‌های این جلسه <span>{{ $recordings->count() }}</span></h4>
        @forelse($recordings as $recording)
            <article class="recording-item">
                <div class="recording-meta">
                    <div class="recording-icon"><i class="ki-filled ki-microphone"></i></div>
                    <div>
                        <strong>ضبط {{ $loop->remaining + 1 }}</strong>
                        <small>
                            <span data-jalali-datetime="{{ $recording->recorded_at?->toIso8601String() }}">{{ $recording->recorded_at?->format('Y-m-d H:i') }}</span>
                            · {{ $recording->duration_seconds ? sprintf('%02d:%02d', intdiv($recording->duration_seconds,60), $recording->duration_seconds%60) : 'مدت نامشخص' }}
                            · {{ $recording->size_bytes ? number_format($recording->size_bytes / 1048576, 1).' MB' : '—' }}
                        </small>
                    </div>
                    <span class="recording-status status-{{ $recording->status }}">{{ ['uploading'=>'در حال بارگذاری','sealing'=>'در حال رمزگذاری','ready'=>'آماده و امن','failed'=>'ناموفق','deleted'=>'حذف‌شده'][$recording->status] ?? $recording->status }}</span>
                </div>

                @if($recording->isReady())
                    <div class="recording-actions">
                        @if($canListen)<button type="button" class="kt-btn kt-btn-outline" data-secure-audio="{{ route('counselor.recordings.stream', $recording) }}"><i class="ki-filled ki-play"></i> پخش امن</button>@endif
                        @if($canTranscribe && ! $recording->transcriptIsFinalized() && $transcriptionAvailable && !in_array($recording->transcript_status,['queued','processing'],true))
                            <form method="post" action="{{ route('counselor.recordings.transcribe', $recording) }}">@csrf<button class="kt-btn kt-btn-outline"><i class="ki-filled ki-abstract-26"></i> تبدیل خودکار به متن</button></form>
                        @endif
                        @if($canRecord && ! $recording->transcriptIsFinalized())
                            <form method="post" action="{{ route('counselor.recordings.destroy', [$appointment,$recording]) }}" onsubmit="return confirm('این فایل صوت برای همیشه حذف شود؟')">@csrf @method('DELETE')<button class="kt-btn kt-btn-outline text-danger"><i class="ki-filled ki-trash"></i> حذف</button></form>
                        @endif
                    </div>
                    @if($canListen)<audio controls preload="none" class="secure-audio" data-secure-audio-player hidden></audio>@endif

                    @if($canListen)
                    <div class="transcript-box">
                        <div class="transcript-heading">
                            <strong>متن پیاده‌شده</strong>
                            <span class="transcript-status status-{{ $recording->transcript_status }}">{{ ['not_requested'=>'ثبت نشده','queued'=>'در صف پردازش','processing'=>'در حال پردازش','draft'=>'پیش‌نویس','finalized'=>'نهایی و قفل‌شده','failed'=>'پردازش ناموفق'][$recording->transcript_status] ?? $recording->transcript_status }}</span>
                        </div>
                        @if($recording->transcription_error)<p class="recording-error">{{ $recording->transcription_error }}</p>@endif
                        @if($recording->transcriptIsFinalized())
                            <div class="final-transcript">{{ $recording->transcript_text }}</div>
                            <small dir="ltr">SHA-256: {{ $recording->transcript_hash }}</small>
                        @elseif($canTranscribe)
                            <form method="post" action="{{ route('counselor.recordings.transcript', $recording) }}" class="transcript-form">
                                @csrf @method('PUT')
                                <textarea class="kt-input" name="transcript_text" maxlength="500000" required placeholder="متن جلسه را دستی وارد کنید یا پس از تبدیل خودکار اصلاح کنید.">{{ old('transcript_text', $recording->transcript_text) }}</textarea>
                                <div class="recording-actions">
                                    <button class="kt-btn kt-btn-outline" name="action" value="save"><i class="ki-filled ki-save-2"></i> ذخیره پیش‌نویس</button>
                                    <button class="kt-btn kt-btn-success" name="action" value="finalize" onclick="return confirm('متن نهایی قفل می‌شود. ادامه می‌دهید؟')"><i class="ki-filled ki-lock"></i> نهایی‌سازی متن</button>
                                </div>
                            </form>
                        @endif
                    </div>
                    @else
                        <div class="recording-hint"><i class="ki-filled ki-lock"></i> برای این نقش فقط فراداده ضبط قابل ممیزی است؛ محتوای صوت و متن نمایش داده نمی‌شود.</div>
                    @endif
                @endif
            </article>
        @empty
            <div class="recording-empty"><i class="ki-filled ki-microphone"></i><p>هنوز صوتی برای این جلسه ثبت نشده است.</p></div>
        @endforelse
    </div>
</section>
