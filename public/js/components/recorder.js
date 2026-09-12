/*
 * In-browser recording.
 *
 * MediaRecorder gives us WhatsApp-style voice notes and camera clips without a single
 * plugin, but it is picky: it needs a secure context (https, or localhost), the container
 * formats differ per browser, and the tracks keep the microphone light on until they are
 * explicitly stopped. All of that is handled here so the composer only has to listen for a
 * `captured` event carrying a ready-to-upload File.
 */
(function (IdeaStorm) {
    'use strict';

    const { utils } = IdeaStorm;

    /** First container the browser admits it can write, most preferred first. */
    function pickMimeType(mode) {
        const candidates = mode === 'video'
            ? ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4']
            : ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/aac'];

        if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) {
            return '';
        }

        return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
    }

    function extensionFor(mimeType, mode) {
        if (mimeType.includes('mp4')) return 'mp4';
        if (mimeType.includes('ogg')) return 'ogg';
        if (mimeType.includes('aac')) return 'aac';
        if (mimeType.includes('webm')) return 'webm';
        return mode === 'video' ? 'webm' : 'webm';
    }

    IdeaStorm.components.MediaCapture = {
        props: {
            mode: { type: String, default: 'audio' },
            /** Recording stops on its own at this point, so nobody uploads an hour by accident. */
            maxSeconds: { type: Number, default: 300 },
        },
        emits: ['captured', 'close'],
        data() {
            return {
                state: 'idle', // idle | requesting | ready | recording | error
                seconds: 0,
                level: 0,
                error: '',
                facingMode: 'user',
                hasMultipleCameras: false,
            };
        },
        computed: {
            isVideo() {
                return this.mode === 'video';
            },
            label() {
                return this.isVideo ? 'Video recording' : 'Voice note';
            },
            supported() {
                return Boolean(
                    navigator.mediaDevices
                    && navigator.mediaDevices.getUserMedia
                    && typeof MediaRecorder !== 'undefined',
                );
            },
            /** getUserMedia is only handed out on https (localhost counts as secure). */
            secureContext() {
                return window.isSecureContext !== false;
            },
            remaining() {
                return Math.max(0, this.maxSeconds - this.seconds);
            },
            timeLabel() {
                return utils.duration(this.seconds);
            },
        },
        mounted() {
            if (!this.supported || !this.secureContext) {
                this.state = 'error';
                this.error = !this.secureContext
                    ? 'Recording needs a secure connection (https). Open the site over https and try again — you can still attach a file you recorded elsewhere.'
                    : 'This browser cannot record media. You can still attach an existing audio or video file.';
                return;
            }
            this.start();
        },
        unmounted() {
            this.teardown();
        },
        methods: {
            async start() {
                this.state = 'requesting';
                this.error = '';

                const constraints = this.isVideo
                    ? { audio: true, video: { facingMode: this.facingMode, width: { ideal: 1280 }, height: { ideal: 720 } } }
                    : { audio: true };

                try {
                    this.stream = await navigator.mediaDevices.getUserMedia(constraints);
                } catch (error) {
                    this.state = 'error';
                    this.error = this.describeError(error);
                    return;
                }

                if (this.isVideo && this.$refs.preview) {
                    this.$refs.preview.srcObject = this.stream;
                    this.$refs.preview.muted = true;
                    this.$refs.preview.play().catch(() => {});
                }

                if (!this.isVideo) {
                    this.startMeter();
                }

                await this.detectCameras();
                this.state = 'ready';
            },

            async detectCameras() {
                if (!this.isVideo || !navigator.mediaDevices.enumerateDevices) {
                    return;
                }
                try {
                    const devices = await navigator.mediaDevices.enumerateDevices();
                    this.hasMultipleCameras = devices.filter((device) => device.kind === 'videoinput').length > 1;
                } catch (error) {
                    this.hasMultipleCameras = false;
                }
            },

            describeError(error) {
                switch (error && error.name) {
                    case 'NotAllowedError':
                    case 'SecurityError':
                        return 'Permission denied. Allow access to your ' + (this.isVideo ? 'camera and microphone' : 'microphone') + ' in the browser, then try again.';
                    case 'NotFoundError':
                    case 'DevicesNotFoundError':
                        return 'No ' + (this.isVideo ? 'camera' : 'microphone') + ' was found on this device.';
                    case 'NotReadableError':
                        return 'Another application is already using the ' + (this.isVideo ? 'camera' : 'microphone') + '.';
                    default:
                        return 'Recording could not be started: ' + ((error && error.message) || 'unknown error') + '.';
                }
            },

            /** Drives the little level bar, so people can see the microphone is picking them up. */
            startMeter() {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextClass) {
                    return;
                }

                this.audioContext = new AudioContextClass();
                const source = this.audioContext.createMediaStreamSource(this.stream);
                this.analyser = this.audioContext.createAnalyser();
                this.analyser.fftSize = 512;
                source.connect(this.analyser);

                const buffer = new Uint8Array(this.analyser.frequencyBinCount);
                const tick = () => {
                    if (!this.analyser) {
                        return;
                    }
                    this.analyser.getByteTimeDomainData(buffer);
                    let peak = 0;
                    for (let i = 0; i < buffer.length; i += 1) {
                        peak = Math.max(peak, Math.abs(buffer[i] - 128) / 128);
                    }
                    this.level = Math.min(100, Math.round(peak * 160));
                    this.meterFrame = window.requestAnimationFrame(tick);
                };
                tick();
            },

            record() {
                if (this.state !== 'ready' || !this.stream) {
                    return;
                }

                const mimeType = pickMimeType(this.mode);
                try {
                    this.recorder = new MediaRecorder(this.stream, mimeType ? { mimeType } : undefined);
                } catch (error) {
                    this.state = 'error';
                    this.error = 'This browser refused to start a recording: ' + error.message;
                    return;
                }

                this.chunks = [];
                this.recorder.ondataavailable = (event) => {
                    if (event.data && event.data.size > 0) {
                        this.chunks.push(event.data);
                    }
                };
                this.recorder.onstop = () => this.finish(mimeType);
                this.recorder.start(1000);

                this.seconds = 0;
                this.state = 'recording';
                this.timer = window.setInterval(() => {
                    this.seconds += 1;
                    if (this.seconds >= this.maxSeconds) {
                        this.stop();
                    }
                }, 1000);
            },

            stop() {
                if (this.recorder && this.recorder.state !== 'inactive') {
                    this.recorder.stop();
                }
                window.clearInterval(this.timer);
                this.timer = null;
            },

            finish(mimeType) {
                const type = (this.chunks[0] && this.chunks[0].type) || mimeType || (this.isVideo ? 'video/webm' : 'audio/webm');
                const blob = new Blob(this.chunks, { type });
                const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
                const name = (this.isVideo ? 'video-' : 'voice-note-') + stamp + '.' + extensionFor(type, this.mode);

                const file = new File([blob], name, { type, lastModified: Date.now() });
                this.$emit('captured', { file, durationSeconds: this.seconds });
                this.teardown();
                this.$emit('close');
            },

            cancel() {
                if (this.recorder && this.recorder.state !== 'inactive') {
                    // Drop what was captured instead of handing it to finish().
                    this.recorder.onstop = null;
                    this.recorder.stop();
                }
                this.teardown();
                this.$emit('close');
            },

            async flipCamera() {
                this.facingMode = this.facingMode === 'user' ? 'environment' : 'user';
                this.teardown();
                await this.start();
            },

            /** Releases the camera and microphone — without this the device light stays on. */
            teardown() {
                window.clearInterval(this.timer);
                this.timer = null;

                if (this.meterFrame) {
                    window.cancelAnimationFrame(this.meterFrame);
                    this.meterFrame = null;
                }
                this.analyser = null;

                if (this.audioContext) {
                    this.audioContext.close().catch(() => {});
                    this.audioContext = null;
                }
                if (this.stream) {
                    this.stream.getTracks().forEach((track) => track.stop());
                    this.stream = null;
                }
                if (this.$refs.preview) {
                    this.$refs.preview.srcObject = null;
                }
                this.recorder = null;
            },
        },
        template: `
            <div class="recorder">
                <div v-if="state === 'error'">
                    <p class="flash flash--error" style="margin:0" role="alert">{{ error }}</p>
                    <div class="row" style="margin-top:.7rem">
                        <button type="button" class="btn btn--sm" @click="start" v-if="supported && secureContext">Try again</button>
                        <button type="button" class="btn btn--ghost btn--sm" @click="cancel">Close</button>
                    </div>
                </div>

                <template v-else>
                    <div class="recorder__head">
                        <span v-if="state === 'recording'" class="recorder__dot" aria-hidden="true"></span>
                        <strong>{{ label }}</strong>
                        <span class="recorder__time" role="timer" aria-live="off">{{ timeLabel }}</span>
                        <span v-if="state === 'recording'" class="faint">{{ utils.duration(remaining) }} left</span>
                        <span v-else-if="state === 'requesting'" class="faint">Waiting for permission…</span>
                        <span class="spacer"></span>

                        <button
                            v-if="isVideo && hasMultipleCameras && state === 'ready'"
                            type="button" class="btn btn--sm" @click="flipCamera"
                        >Flip camera</button>
                        <button v-if="state === 'ready'" type="button" class="btn btn--primary btn--sm" @click="record">Record</button>
                        <button v-if="state === 'recording'" type="button" class="btn btn--primary btn--sm" @click="stop">Stop &amp; attach</button>
                        <button type="button" class="btn btn--ghost btn--sm" @click="cancel">Cancel</button>
                    </div>

                    <video v-if="isVideo" ref="preview" class="recorder__preview" playsinline muted></video>

                    <div v-else class="recorder__meter" role="presentation">
                        <div class="recorder__meter-fill" :style="{ width: level + '%' }"></div>
                    </div>
                </template>
            </div>
        `,
        setup() {
            return { utils };
        },
    };
})(window.IdeaStorm);
