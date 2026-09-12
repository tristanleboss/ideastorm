/*
 * Small presentational components shared across the app: avatars, toasts, modals, the
 * attachment renderers and the vote control.
 */
(function (IdeaStorm) {
    'use strict';

    const { utils, store } = IdeaStorm;

    /** A person's picture, falling back to their initials when there is none. */
    IdeaStorm.components.UserAvatar = {
        props: {
            user: { type: Object, default: null },
            size: { type: String, default: '' },
        },
        computed: {
            classes() {
                return ['avatar', this.size ? 'avatar--' + this.size : ''];
            },
        },
        template: `
            <img v-if="user && user.avatarUrl" :class="classes" :src="user.avatarUrl" alt="" referrerpolicy="no-referrer">
            <span v-else :class="[classes, 'avatar--initials']" aria-hidden="true">{{ user ? user.initials : '?' }}</span>
        `,
    };

    /** Notification stack in the bottom-right corner. */
    IdeaStorm.components.ToastStack = {
        setup() {
            return { toasts: IdeaStorm.toasts };
        },
        template: `
            <div class="toasts" aria-live="polite" aria-atomic="false">
                <div v-for="toast in toasts.items" :key="toast.id" :class="['toast', 'toast--' + toast.tone]" role="status">
                    <span>{{ toast.message }}</span>
                    <button type="button" class="toast__close" @click="toasts.dismiss(toast.id)" aria-label="Dismiss">×</button>
                </div>
            </div>
        `,
    };

    /**
     * Dialog with a backdrop. Closes on Escape and on a backdrop click, and moves focus
     * inside when it opens so keyboard users are not left behind on the page underneath.
     */
    IdeaStorm.components.AppModal = {
        props: {
            title: { type: String, default: '' },
        },
        emits: ['close'],
        mounted() {
            this.previousFocus = document.activeElement;
            document.addEventListener('keydown', this.onKeydown);
            document.body.style.overflow = 'hidden';
            this.$nextTick(() => {
                const target = this.$refs.panel.querySelector('input, textarea, select, button');
                if (target) {
                    target.focus();
                }
            });
        },
        unmounted() {
            document.removeEventListener('keydown', this.onKeydown);
            document.body.style.overflow = '';
            if (this.previousFocus && this.previousFocus.focus) {
                this.previousFocus.focus();
            }
        },
        methods: {
            onKeydown(event) {
                if (event.key === 'Escape') {
                    this.$emit('close');
                }
            },
            onBackdrop(event) {
                if (event.target === event.currentTarget) {
                    this.$emit('close');
                }
            },
        },
        template: `
            <div class="modal-backdrop" @mousedown="onBackdrop">
                <div class="modal" ref="panel" role="dialog" aria-modal="true" :aria-label="title">
                    <div class="modal__head">
                        <h2 class="modal__title">{{ title }}</h2>
                        <button type="button" class="btn btn--ghost btn--sm" @click="$emit('close')" aria-label="Close">×</button>
                    </div>
                    <slot></slot>
                </div>
            </div>
        `,
    };

    /** Shown wherever an action needs an account the visitor does not have. */
    IdeaStorm.components.SignInPrompt = {
        props: {
            message: { type: String, default: 'Sign in to join the conversation.' },
        },
        template: `
            <p class="empty">
                {{ message }}
                <br>
                <a class="btn btn--primary btn--sm" style="margin-top:.7rem" href="/login">Sign in</a>
                <a class="btn btn--ghost btn--sm" style="margin-top:.7rem" href="/register">Create an account</a>
            </p>
        `,
    };

    /**
     * Renders one attachment the way its kind deserves: a player for audio and video, the
     * picture itself for images, and a download card for documents.
     */
    IdeaStorm.components.AttachmentView = {
        props: {
            attachment: { type: Object, required: true },
            removable: { type: Boolean, default: false },
        },
        emits: ['remove'],
        setup() {
            return { utils };
        },
        template: `
            <figure class="attachment" style="margin:0">
                <figcaption class="attachment__head">
                    <span class="attachment__icon" aria-hidden="true">{{ utils.iconFor(attachment.kind) }}</span>
                    <span class="attachment__name">{{ attachment.filename }}</span>
                    <span class="faint">{{ attachment.sizeLabel }}</span>
                    <span v-if="attachment.durationSeconds" class="faint">· {{ utils.duration(attachment.durationSeconds) }}</span>
                    <span class="spacer"></span>
                    <a class="faint" :href="attachment.downloadUrl">Download</a>
                    <button v-if="removable" type="button" class="attachment-pill__remove" @click="$emit('remove', attachment)" :aria-label="'Remove ' + attachment.filename">×</button>
                </figcaption>

                <audio v-if="attachment.kind === 'audio'" controls preload="metadata" :src="attachment.url"></audio>
                <video v-else-if="attachment.kind === 'video'" controls preload="metadata" playsinline :src="attachment.url"></video>
                <img v-else-if="attachment.kind === 'image'" class="attachment__image" :src="attachment.url" :alt="attachment.filename" loading="lazy">
            </figure>
        `,
    };

    /** The list of attachments hanging off an idea. */
    IdeaStorm.components.AttachmentList = {
        components: { AttachmentView: IdeaStorm.components.AttachmentView },
        props: {
            attachments: { type: Array, default: () => [] },
            removable: { type: Boolean, default: false },
        },
        emits: ['remove'],
        template: `
            <div v-if="attachments.length" class="attachments">
                <AttachmentView
                    v-for="attachment in attachments"
                    :key="attachment.id"
                    :attachment="attachment"
                    :removable="removable"
                    @remove="$emit('remove', $event)"
                />
            </div>
        `,
    };

    /**
     * Up/down control. Clicking the arrow you already picked takes the vote back, and the
     * tally updates optimistically so the click feels instant — the server's answer replaces
     * it a moment later, or the previous state is restored if the call fails.
     */
    IdeaStorm.components.VoteControl = {
        props: {
            idea: { type: Object, required: true },
        },
        emits: ['voted'],
        data() {
            return { busy: false };
        },
        setup() {
            return { store };
        },
        computed: {
            canVote() {
                return store.isSignedIn && this.idea.permissions.vote;
            },
            title() {
                return store.isSignedIn ? '' : 'Sign in to vote';
            },
        },
        methods: {
            async cast(value) {
                if (!store.isSignedIn) {
                    IdeaStorm.toasts.push('Sign in to vote on ideas.');
                    return;
                }
                if (this.busy || !this.canVote) {
                    return;
                }

                const snapshot = JSON.parse(JSON.stringify(this.idea.votes));
                const next = this.idea.votes.mine === value ? 0 : value;

                // Optimistic update.
                this.idea.votes.score += next - this.idea.votes.mine;
                this.idea.votes.mine = next;
                this.busy = true;

                try {
                    const data = await IdeaStorm.api.ideas.vote(this.idea.id, value);
                    this.$emit('voted', data.idea);
                } catch (error) {
                    Object.assign(this.idea.votes, snapshot);
                    IdeaStorm.toasts.fromError(error);
                } finally {
                    this.busy = false;
                }
            },
        },
        template: `
            <div class="vote">
                <button
                    type="button"
                    class="vote__btn"
                    :class="{ 'vote__btn--active': idea.votes.mine === 1 }"
                    :disabled="busy"
                    :title="title"
                    :aria-pressed="idea.votes.mine === 1"
                    :aria-label="'Vote up (' + idea.votes.up + ' up so far)'"
                    @click="cast(1)"
                >▲</button>
                <span class="vote__score" :title="idea.votes.up + ' up · ' + idea.votes.down + ' down'">{{ idea.votes.score }}</span>
                <button
                    type="button"
                    class="vote__btn vote__btn--down"
                    :class="{ 'vote__btn--active': idea.votes.mine === -1 }"
                    :disabled="busy"
                    :title="title"
                    :aria-pressed="idea.votes.mine === -1"
                    :aria-label="'Vote down (' + idea.votes.down + ' down so far)'"
                    @click="cast(-1)"
                >▼</button>
            </div>
        `,
    };
})(window.IdeaStorm);
