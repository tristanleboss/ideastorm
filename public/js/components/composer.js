/*
 * The idea composer: text, voice, video, photos and dropped or pasted documents, collected
 * client-side and posted in one multipart request so an idea is never stored half-finished.
 */
(function (IdeaStorm) {
    'use strict';

    const { api, store, toasts, utils } = IdeaStorm;

    let pendingId = 0;

    IdeaStorm.components.IdeaComposer = {
        components: {
            MediaCapture: IdeaStorm.components.MediaCapture,
        },
        props: {
            project: { type: Object, required: true },
            /** When set, the composer creates a version of this idea rather than a new one. */
            parent: { type: Object, default: null },
        },
        emits: ['created', 'cancel'],
        data() {
            return {
                title: '',
                body: '',
                versionLabel: '',
                pending: [],
                capture: null, // 'audio' | 'video' | null
                dragging: 0,
                submitting: false,
                errors: {},
            };
        },
        setup() {
            return { store, utils };
        },
        computed: {
            isVersion() {
                return this.parent !== null;
            },
            heading() {
                return this.isVersion ? 'Add a version of "' + this.parent.title + '"' : 'Submit an idea';
            },
            canSubmit() {
                return !this.submitting && this.title.trim().length >= 3 && (this.body.trim() !== '' || this.pending.length > 0);
            },
            acceptAttribute() {
                // Broad on purpose: the server has the real allowlist, this is only a hint to
                // the file picker so it does not show every file on the machine by default.
                return 'audio/*,video/*,image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.md';
            },
        },
        mounted() {
            // Pasting a screenshot straight into the composer is the fastest way to attach one.
            this.$refs.root.addEventListener('paste', this.onPaste);
        },
        unmounted() {
            this.$refs.root && this.$refs.root.removeEventListener('paste', this.onPaste);
            this.pending.forEach((item) => {
                if (item.previewUrl) {
                    URL.revokeObjectURL(item.previewUrl);
                }
            });
        },
        methods: {
            /** Adds a File to the pending list after the checks the server would also make. */
            addFile(file, durationSeconds) {
                if (!file || file.size === 0) {
                    toasts.error('That file is empty.');
                    return;
                }
                if (file.size > store.uploads.maxSize) {
                    toasts.error('"' + file.name + '" is ' + utils.bytes(file.size) + ', over the ' + store.uploads.maxSizeLabel + ' limit.');
                    return;
                }

                const kind = file.type.startsWith('audio/') ? 'audio'
                    : file.type.startsWith('video/') ? 'video'
                        : file.type.startsWith('image/') ? 'image' : 'document';

                this.pending.push({
                    id: ++pendingId,
                    file,
                    kind,
                    name: file.name,
                    size: file.size,
                    durationSeconds: durationSeconds || null,
                    previewUrl: kind === 'document' ? null : URL.createObjectURL(file),
                });
            },

            addFiles(fileList) {
                Array.from(fileList || []).forEach((file) => this.addFile(file));
            },

            removePending(item) {
                const index = this.pending.findIndex((candidate) => candidate.id === item.id);
                if (index !== -1) {
                    if (item.previewUrl) {
                        URL.revokeObjectURL(item.previewUrl);
                    }
                    this.pending.splice(index, 1);
                }
            },

            onPickFiles(event) {
                this.addFiles(event.target.files);
                event.target.value = '';
            },

            onCaptured(payload) {
                this.addFile(payload.file, payload.durationSeconds);
            },

            onPaste(event) {
                const items = (event.clipboardData && event.clipboardData.items) || [];
                let handled = false;
                Array.from(items).forEach((item) => {
                    if (item.kind !== 'file') {
                        return;
                    }
                    const file = item.getAsFile();
                    if (file) {
                        // Clipboard images arrive as "image.png"; give them a distinguishable name.
                        const named = file.name && file.name !== 'image.png'
                            ? file
                            : new File([file], 'pasted-' + Date.now() + '.' + (file.type.split('/')[1] || 'png'), { type: file.type });
                        this.addFile(named);
                        handled = true;
                    }
                });

                if (handled) {
                    event.preventDefault();
                }
            },

            // Counting enter/leave events avoids the flicker you get when the pointer moves
            // over a child element inside the drop zone.
            onDragEnter() { this.dragging += 1; },
            onDragLeave() { this.dragging = Math.max(0, this.dragging - 1); },
            onDrop(event) {
                this.dragging = 0;
                if (event.dataTransfer && event.dataTransfer.files.length) {
                    this.addFiles(event.dataTransfer.files);
                }
            },

            async submit() {
                if (!this.canSubmit) {
                    return;
                }

                this.submitting = true;
                this.errors = {};

                const form = new FormData();
                form.append('title', this.title.trim());
                form.append('body', this.body.trim());
                if (this.isVersion) {
                    form.append('versionOf', String(this.parent.id));
                    form.append('versionLabel', this.versionLabel.trim());
                }
                // `durations[]` is aligned by index with `files[]` on the server side.
                this.pending.forEach((item) => {
                    form.append('files[]', item.file, item.file.name);
                    form.append('durations[]', item.durationSeconds === null ? '' : String(item.durationSeconds));
                });

                try {
                    const data = await api.ideas.create(this.project.slug, form);
                    toasts.success(this.isVersion ? 'Version added.' : 'Idea submitted.');
                    this.$emit('created', data.idea);
                } catch (error) {
                    if (error.fields) {
                        this.errors = error.fields;
                    }
                    toasts.fromError(error);
                } finally {
                    this.submitting = false;
                }
            },
        },
        template: `
            <section
                class="card composer"
                ref="root"
                @dragenter.prevent="onDragEnter"
                @dragover.prevent
                @dragleave.prevent="onDragLeave"
                @drop.prevent="onDrop"
            >
                <h2 class="composer__title">{{ heading }}</h2>

                <div :class="['composer__dropzone', { 'composer__dropzone--active': dragging > 0 }]">
                    <div class="field" :class="{ 'field--invalid': errors.title }">
                        <label for="idea-title">Title</label>
                        <input id="idea-title" type="text" v-model="title" maxlength="180" placeholder="One line that captures it" required>
                        <p v-for="message in errors.title" :key="message" class="field__error">{{ message }}</p>
                    </div>

                    <div v-if="isVersion" class="field">
                        <label for="idea-version-label">Version label <span class="faint">(optional)</span></label>
                        <input id="idea-version-label" type="text" v-model="versionLabel" maxlength="120" placeholder="e.g. Mobile-first take">
                        <p class="field__hint">A short name to tell this version apart from the others.</p>
                    </div>

                    <div class="field" :class="{ 'field--invalid': errors.body }">
                        <label for="idea-body">Describe it</label>
                        <textarea id="idea-body" v-model="body" rows="5" placeholder="What is the idea, and why is it worth doing? You can also paste a screenshot here."></textarea>
                        <p v-for="message in errors.body" :key="message" class="field__error">{{ message }}</p>
                    </div>

                    <div class="composer__tools">
                        <button type="button" class="btn btn--sm" @click="capture = capture === 'audio' ? null : 'audio'">🎙️ Record voice note</button>
                        <button type="button" class="btn btn--sm" @click="capture = capture === 'video' ? null : 'video'">🎬 Record video</button>
                        <button type="button" class="btn btn--sm" @click="$refs.cameraInput.click()">📷 Camera / gallery</button>
                        <button type="button" class="btn btn--sm" @click="$refs.fileInput.click()">📎 Attach files</button>
                    </div>

                    <p class="composer__hint">
                        Drag files anywhere onto this box, or paste an image straight from your clipboard.
                        Up to {{ store.uploads.maxSizeLabel }} per file.
                    </p>

                    <input ref="fileInput" type="file" multiple class="visually-hidden" :accept="acceptAttribute" @change="onPickFiles">
                    <!-- capture= asks a phone for its camera app rather than the file browser. -->
                    <input ref="cameraInput" type="file" multiple class="visually-hidden" accept="image/*,video/*" capture="environment" @change="onPickFiles">

                    <MediaCapture
                        v-if="capture"
                        :key="capture"
                        :mode="capture"
                        :max-seconds="capture === 'video' ? 180 : 300"
                        @captured="onCaptured"
                        @close="capture = null"
                    />

                    <div v-if="pending.length" class="attachments">
                        <p class="faint" style="margin:0">{{ pending.length }} attachment{{ pending.length === 1 ? '' : 's' }} ready to go</p>
                        <figure v-for="item in pending" :key="item.id" class="attachment" style="margin:0">
                            <figcaption class="attachment__head">
                                <span class="attachment__icon" aria-hidden="true">{{ utils.iconFor(item.kind) }}</span>
                                <span class="attachment__name">{{ item.name }}</span>
                                <span class="faint">{{ utils.bytes(item.size) }}</span>
                                <span v-if="item.durationSeconds" class="faint">· {{ utils.duration(item.durationSeconds) }}</span>
                                <span class="spacer"></span>
                                <button type="button" class="attachment-pill__remove" :aria-label="'Remove ' + item.name" @click="removePending(item)">×</button>
                            </figcaption>
                            <audio v-if="item.kind === 'audio'" controls :src="item.previewUrl"></audio>
                            <video v-else-if="item.kind === 'video'" controls playsinline :src="item.previewUrl"></video>
                            <img v-else-if="item.kind === 'image'" class="attachment__image" :src="item.previewUrl" :alt="item.name">
                        </figure>
                    </div>
                </div>

                <div class="composer__actions">
                    <button type="button" class="btn btn--primary" :disabled="!canSubmit" @click="submit">
                        {{ submitting ? 'Sending…' : (isVersion ? 'Add version' : 'Submit idea') }}
                    </button>
                    <button type="button" class="btn btn--ghost" @click="$emit('cancel')">Cancel</button>
                    <span v-if="!canSubmit && !submitting" class="faint">A title and either a description or an attachment are needed.</span>
                </div>
            </section>
        `,
    };
})(window.IdeaStorm);
