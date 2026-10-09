(() => {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    async function responseData(response) {
        const type = response.headers.get('content-type') || '';
        const data = type.includes('application/json') ? await response.json() : {message: await response.text()};
        if (!response.ok) {
            const validation = data.errors ? Object.values(data.errors).flat().join(' ') : '';
            throw new Error(validation || data.message || `خطای سرور (${response.status})`);
        }
        return data;
    }

    async function postForm(url, formData) {
        return responseData(await fetch(url, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            credentials: 'same-origin',
            body: formData,
        }));
    }

    function formatSeconds(seconds) {
        const value = Math.max(0, Math.floor(seconds));
        return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
    }

    const recorderRoot = document.querySelector('[data-audio-recorder]');
    if (recorderRoot) {
        const startButton = recorderRoot.querySelector('[data-record-start]');
        const stopButton = recorderRoot.querySelector('[data-record-stop]');
        const timer = recorderRoot.querySelector('[data-record-timer]');
        const state = recorderRoot.querySelector('[data-record-state]');
        const error = recorderRoot.querySelector('[data-record-error]');
        const progress = recorderRoot.querySelector('[data-record-progress]');
        let mediaRecorder = null;
        let mediaStream = null;
        let uploadChain = Promise.resolve();
        let uploadError = null;
        let recordingApi = null;
        let chunkIndex = 0;
        let startedAt = 0;
        let timerHandle = null;

        const setError = (message) => {
            error.textContent = message;
            error.hidden = !message;
        };

        const supportedMime = () => {
            const choices = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
            return choices.find((type) => window.MediaRecorder?.isTypeSupported(type)) || '';
        };

        const stopTracks = () => {
            mediaStream?.getTracks().forEach((track) => track.stop());
            mediaStream = null;
        };

        startButton.addEventListener('click', async () => {
            setError('');
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
                setError('مرورگر یا اتصال فعلی امکان ضبط امن میکروفن را ندارد. صفحه را با HTTPS و مرورگر به‌روز باز کنید.');
                return;
            }
            startButton.disabled = true;
            state.textContent = 'در انتظار اجازه دسترسی به میکروفن…';
            try {
                mediaStream = await navigator.mediaDevices.getUserMedia({
                    audio: {echoCancellation: true, noiseSuppression: true, autoGainControl: true},
                    video: false,
                });
                const mimeType = supportedMime();
                const init = new FormData();
                init.append('consent_id', recorderRoot.dataset.consentId);
                init.append('mime_type', (mimeType || 'audio/webm').split(';')[0]);
                recordingApi = await postForm(recorderRoot.dataset.initUrl, init);
                chunkIndex = 0;
                uploadError = null;
                uploadChain = Promise.resolve();
                mediaRecorder = mimeType ? new MediaRecorder(mediaStream, {mimeType, audioBitsPerSecond: 64000}) : new MediaRecorder(mediaStream);
                startedAt = Date.now();

                mediaRecorder.addEventListener('dataavailable', (event) => {
                    if (!event.data || event.data.size === 0) return;
                    const current = chunkIndex++;
                    uploadChain = uploadChain.then(async () => {
                        state.textContent = `در حال ضبط و ارسال امن قطعه ${current + 1}…`;
                        const body = new FormData();
                        body.append('index', String(current));
                        body.append('chunk', event.data, `chunk-${current}.webm`);
                        await postForm(recordingApi.chunk_url, body);
                    }).catch((reason) => {
                        uploadError = reason;
                        throw reason;
                    });
                });

                mediaRecorder.addEventListener('stop', async () => {
                    clearInterval(timerHandle);
                    progress.hidden = false;
                    state.textContent = 'در حال تکمیل بارگذاری و رمزگذاری فایل…';
                    try {
                        await uploadChain;
                        if (uploadError) throw uploadError;
                        if (chunkIndex < 1) throw new Error('هیچ داده صوتی دریافت نشد.');
                        const body = new FormData();
                        body.append('chunk_count', String(chunkIndex));
                        body.append('duration_seconds', String(Math.max(1, Math.round((Date.now() - startedAt) / 1000))));
                        const result = await postForm(recordingApi.finalize_url, body);
                        state.textContent = result.message || 'فایل صوت با موفقیت ذخیره شد.';
                        window.setTimeout(() => window.location.reload(), 700);
                    } catch (reason) {
                        setError(reason.message || 'ذخیره ضبط ناموفق بود.');
                        state.textContent = 'ضبط متوقف شد اما تکمیل فایل ناموفق بود.';
                        startButton.disabled = false;
                        progress.hidden = true;
                    } finally {
                        stopTracks();
                    }
                });

                mediaRecorder.start(5000);
                startButton.hidden = true;
                stopButton.hidden = false;
                state.textContent = 'ضبط فعال است؛ قطعات صوت به‌صورت امن ارسال می‌شوند.';
                timerHandle = window.setInterval(() => {
                    timer.textContent = formatSeconds((Date.now() - startedAt) / 1000);
                }, 500);
            } catch (reason) {
                stopTracks();
                startButton.disabled = false;
                setError(reason.message || 'شروع ضبط ناموفق بود.');
                state.textContent = 'ضبط آغاز نشد.';
            }
        });

        stopButton.addEventListener('click', () => {
            if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
            stopButton.disabled = true;
            state.textContent = 'در حال توقف ضبط…';
            mediaRecorder.stop();
        });

        window.addEventListener('beforeunload', (event) => {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    document.querySelectorAll('[data-secure-audio]').forEach((button) => {
        button.addEventListener('click', async () => {
            const item = button.closest('.recording-item');
            const player = item?.querySelector('[data-secure-audio-player]');
            if (!player) return;
            button.disabled = true;
            const original = button.innerHTML;
            button.textContent = 'در حال دریافت و رمزگشایی…';
            try {
                if (!player.src) {
                    const response = await fetch(button.dataset.secureAudio, {
                        headers: {'Accept': 'audio/*', 'X-CSRF-TOKEN': csrf},
                        credentials: 'same-origin',
                    });
                    if (!response.ok) {
                        const data = await responseData(response);
                        throw new Error(data.message || 'دریافت صوت ناموفق بود.');
                    }
                    player.src = URL.createObjectURL(await response.blob());
                }
                player.hidden = false;
                await player.play();
            } catch (reason) {
                window.alert(reason.message || 'پخش امن فایل ناموفق بود.');
            } finally {
                button.disabled = false;
                button.innerHTML = original;
            }
        });
    });
})();
