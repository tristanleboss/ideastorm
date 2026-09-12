/*
 * The idea board: cards, their versions, the discussion under each one, and the filters
 * above them.
 */
(function (IdeaStorm) {
    'use strict';

    const { api, store, toasts, utils } = IdeaStorm;

    /** The discussion under an idea. Comments load the first time the thread is opened. */
    IdeaStorm.components.CommentThread = {
        components: {
            UserAvatar: IdeaStorm.components.UserAvatar,
            SignInPrompt: IdeaStorm.components.SignInPrompt,
        },
        props: {
            idea: { type: Object, required: true },
        },
        emits: ['count-changed'],
        data() {
            return {
                comments: [],
                loading: true,
                draft: '',
                replyTo: null,
                replyDraft: '',
                editing: null,
                editDraft: '',
                posting: false,
            };
        },
        setup() {
            return { store, utils };
        },
        mounted() {
            this.load();
        },
        methods: {
            async load() {
                this.loading = true;
                try {
                    const data = await api.comments.list(this.idea.id);
                    this.comments = data.comments;
                } catch (error) {
                    toasts.fromError(error);
                } finally {
                    this.loading = false;
                }
            },

            countAll() {
                return this.comments.reduce((total, comment) => total + 1 + comment.replies.length, 0);
            },

            async post(body, parentId) {
                const text = (body || '').trim();
                if (text === '' || this.posting) {
                    return;
                }

                this.posting = true;
                try {
                    const data = await api.comments.create(this.idea.id, { body: text, parentId: parentId || null });
                    const comment = data.comment;

                    if (comment.parentId) {
                        const parent = this.comments.find((candidate) => candidate.id === comment.parentId);
                        if (parent) {
                            parent.replies.push(comment);
                        } else {
                            this.comments.push(comment);
                        }
                    } else {
                        this.comments.push(comment);
                    }

                    this.draft = '';
                    this.replyDraft = '';
                    this.replyTo = null;
                    this.$emit('count-changed', this.countAll());
                } catch (error) {
                    toasts.fromError(error);
                } finally {
                    this.posting = false;
                }
            },

            startEdit(comment) {
                this.editing = comment.id;
                this.editDraft = comment.body;
            },

            async saveEdit(comment) {
                const text = this.editDraft.trim();
                if (text === '') {
                    return;
                }
                try {
                    const data = await api.comments.update(comment.id, text);
                    Object.assign(comment, data.comment, { replies: comment.replies });
                    this.editing = null;
                } catch (error) {
                    toasts.fromError(error);
                }
            },

            async remove(comment, parent) {
                if (!window.confirm('Delete this comment?')) {
                    return;
                }
                try {
                    await api.comments.remove(comment.id);
                    const list = parent ? parent.replies : this.comments;
                    const index = list.findIndex((candidate) => candidate.id === comment.id);
                    if (index !== -1) {
                        list.splice(index, 1);
                    }
                    this.$emit('count-changed', this.countAll());
                } catch (error) {
                    toasts.fromError(error);
                }
            },
        },
        template: `
            <div class="comments">
                <p v-if="loading" class="faint">Loading the discussion…</p>

                <template v-else>
                    <p v-if="!comments.length" class="faint">No comments yet. Be the first to weigh in.</p>

                    <div class="comment-list">
                        <article v-for="comment in comments" :key="comment.id" class="comment">
                            <UserAvatar :user="comment.author" size="sm" />
                            <div class="comment__body">
                                <div class="comment__bubble">
                                    <p class="comment__meta">
                                        <span class="comment__name">{{ comment.author.displayName }}</span>
                                        <time :datetime="comment.createdAt" :title="utils.absoluteDateTime(comment.createdAt)">{{ utils.relativeTime(comment.createdAt) }}</time>
                                        <span v-if="comment.editedAt">· edited</span>
                                    </p>

                                    <template v-if="editing === comment.id">
                                        <textarea v-model="editDraft" rows="3"></textarea>
                                        <div class="row" style="margin-top:.4rem">
                                            <button type="button" class="btn btn--primary btn--sm" @click="saveEdit(comment)">Save</button>
                                            <button type="button" class="btn btn--ghost btn--sm" @click="editing = null">Cancel</button>
                                        </div>
                                    </template>
                                    <p v-else class="comment__text">{{ comment.body }}</p>
                                </div>

                                <div class="comment__actions">
                                    <button v-if="store.isSignedIn" type="button" @click="replyTo = replyTo === comment.id ? null : comment.id">Reply</button>
                                    <button v-if="comment.permissions.edit" type="button" @click="startEdit(comment)">Edit</button>
                                    <button v-if="comment.permissions.delete" type="button" @click="remove(comment, null)">Delete</button>
                                </div>

                                <div v-if="comment.replies.length || replyTo === comment.id" class="comment__replies">
                                    <article v-for="reply in comment.replies" :key="reply.id" class="comment">
                                        <UserAvatar :user="reply.author" size="sm" />
                                        <div class="comment__body">
                                            <div class="comment__bubble">
                                                <p class="comment__meta">
                                                    <span class="comment__name">{{ reply.author.displayName }}</span>
                                                    <time :datetime="reply.createdAt" :title="utils.absoluteDateTime(reply.createdAt)">{{ utils.relativeTime(reply.createdAt) }}</time>
                                                    <span v-if="reply.editedAt">· edited</span>
                                                </p>
                                                <template v-if="editing === reply.id">
                                                    <textarea v-model="editDraft" rows="3"></textarea>
                                                    <div class="row" style="margin-top:.4rem">
                                                        <button type="button" class="btn btn--primary btn--sm" @click="saveEdit(reply)">Save</button>
                                                        <button type="button" class="btn btn--ghost btn--sm" @click="editing = null">Cancel</button>
                                                    </div>
                                                </template>
                                                <p v-else class="comment__text">{{ reply.body }}</p>
                                            </div>
                                            <div class="comment__actions">
                                                <button v-if="reply.permissions.edit" type="button" @click="startEdit(reply)">Edit</button>
                                                <button v-if="reply.permissions.delete" type="button" @click="remove(reply, comment)">Delete</button>
                                            </div>
                                        </div>
                                    </article>

                                    <form v-if="replyTo === comment.id" class="comment-form" @submit.prevent="post(replyDraft, comment.id)">
                                        <div class="comment-form__body">
                                            <textarea v-model="replyDraft" rows="2" :placeholder="'Reply to ' + comment.author.displayName + '…'"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn--primary btn--sm" :disabled="posting || !replyDraft.trim()">Reply</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    </div>

                    <form v-if="store.isSignedIn" class="comment-form" @submit.prevent="post(draft, null)">
                        <UserAvatar :user="store.user" size="sm" />
                        <div class="comment-form__body">
                            <textarea v-model="draft" rows="2" placeholder="Add a comment…" aria-label="Add a comment"></textarea>
                        </div>
                        <button type="submit" class="btn btn--primary btn--sm" :disabled="posting || !draft.trim()">Post</button>
                    </form>
                    <SignInPrompt v-else message="Sign in to join the discussion." />
                </template>
            </div>
        `,
    };

    /** One idea, with its versions folded underneath it. */
    IdeaStorm.components.IdeaCard = {
        components: {
            UserAvatar: IdeaStorm.components.UserAvatar,
            VoteControl: IdeaStorm.components.VoteControl,
            AttachmentList: IdeaStorm.components.AttachmentList,
            CommentThread: IdeaStorm.components.CommentThread,
        },
        props: {
            idea: { type: Object, required: true },
            /** Versions render in a lighter frame and drop the actions that do not apply. */
            nested: { type: Boolean, default: false },
            expanded: { type: Boolean, default: false },
        },
        emits: ['updated', 'removed', 'branch', 'open'],
        data() {
            return {
                showComments: this.expanded,
                showVersions: true,
                editing: false,
                editTitle: this.idea.title,
                editBody: this.idea.body || '',
                saving: false,
            };
        },
        setup() {
            return { store, utils };
        },
        computed: {
            permalink() {
                return '/projects/' + this.idea.projectSlug + '/ideas/' + this.idea.id;
            },
            statusBadgeClass() {
                return ['badge', utils.statusTone(this.idea.status.value)];
            },
            versionCount() {
                return (this.idea.versions || []).length;
            },
        },
        watch: {
            expanded(value) {
                this.showComments = value;
            },
        },
        methods: {
            onVoted(updated) {
                Object.assign(this.idea, updated);
                this.$emit('updated', this.idea);
            },

            async changeStatus(event) {
                const value = event.target.value;
                try {
                    const data = await api.ideas.setStatus(this.idea.id, value);
                    Object.assign(this.idea, data.idea);
                    toasts.success('Status updated.');
                    this.$emit('updated', this.idea);
                } catch (error) {
                    toasts.fromError(error);
                }
            },

            async saveEdit() {
                this.saving = true;
                try {
                    const data = await api.ideas.update(this.idea.id, { title: this.editTitle, body: this.editBody });
                    Object.assign(this.idea, data.idea);
                    this.editing = false;
                    this.$emit('updated', this.idea);
                } catch (error) {
                    toasts.fromError(error);
                } finally {
                    this.saving = false;
                }
            },

            async removeIdea() {
                const what = this.idea.isVersion ? 'this version' : 'this idea and every version of it';
                if (!window.confirm('Delete ' + what + '? This cannot be undone.')) {
                    return;
                }
                try {
                    await api.ideas.remove(this.idea.id);
                    this.$emit('removed', this.idea);
                } catch (error) {
                    toasts.fromError(error);
                }
            },

            async removeAttachment(attachment) {
                try {
                    await api.ideas.removeAttachment(attachment.id);
                    const index = this.idea.attachments.findIndex((candidate) => candidate.id === attachment.id);
                    if (index !== -1) {
                        this.idea.attachments.splice(index, 1);
                    }
                } catch (error) {
                    toasts.fromError(error);
                }
            },

            copyLink() {
                const url = window.location.origin + this.permalink;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(
                        () => toasts.success('Link copied.'),
                        () => toasts.error('Could not copy the link.'),
                    );
                } else {
                    window.prompt('Copy this link:', url);
                }
            },
        },
        template: `
            <article :class="['card', 'idea', { 'idea--version': nested }]" :id="'idea-' + idea.id">
                <VoteControl :idea="idea" @voted="onVoted" />

                <div>
                    <div class="idea__head">
                        <div class="flex-body">
                            <template v-if="editing">
                                <div class="field"><input type="text" v-model="editTitle" maxlength="180" aria-label="Title"></div>
                                <div class="field"><textarea v-model="editBody" rows="5" aria-label="Description"></textarea></div>
                                <div class="row">
                                    <button type="button" class="btn btn--primary btn--sm" :disabled="saving" @click="saveEdit">Save</button>
                                    <button type="button" class="btn btn--ghost btn--sm" @click="editing = false">Cancel</button>
                                </div>
                            </template>

                            <template v-else>
                                <h3 class="idea__title">
                                    <a :href="permalink" @click.prevent="$emit('open', idea)">{{ idea.title }}</a>
                                </h3>
                                <p class="idea__byline">
                                    <UserAvatar :user="idea.author" size="sm" />
                                    <span>{{ idea.author.displayName }}</span>
                                    <span>·</span>
                                    <time :datetime="idea.createdAt" :title="utils.absoluteDateTime(idea.createdAt)">{{ utils.relativeTime(idea.createdAt) }}</time>
                                </p>
                            </template>
                        </div>

                        <div class="row row--wrap" style="gap:.35rem">
                            <span v-if="idea.versionLabel" class="badge badge--accent">{{ idea.versionLabel }}</span>
                            <span :class="statusBadgeClass">{{ idea.status.label }}</span>
                        </div>
                    </div>

                    <p v-if="idea.body && !editing" class="idea__body">{{ idea.body }}</p>

                    <AttachmentList
                        :attachments="idea.attachments"
                        :removable="idea.permissions.edit"
                        @remove="removeAttachment"
                    />

                    <div class="idea__footer">
                        <button type="button" class="btn btn--ghost btn--sm" @click="showComments = !showComments">
                            💬 {{ idea.commentCount }} comment{{ idea.commentCount === 1 ? '' : 's' }}
                        </button>

                        <button v-if="!nested && idea.permissions.branch" type="button" class="btn btn--ghost btn--sm" @click="$emit('branch', idea)">
                            🌿 Add a version
                        </button>

                        <button type="button" class="btn btn--ghost btn--sm" @click="copyLink">🔗 Copy link</button>

                        <span class="spacer"></span>

                        <label v-if="idea.permissions.triage" class="row" style="gap:.35rem">
                            <span class="visually-hidden">Status</span>
                            <select :value="idea.status.value" @change="changeStatus" style="width:auto;font-size:.82rem;padding:.25rem .4rem">
                                <option v-for="option in store.vocabulary.ideaStatuses" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </label>

                        <button v-if="idea.permissions.edit && !editing" type="button" class="btn btn--ghost btn--sm" @click="editing = true">Edit</button>
                        <button v-if="idea.permissions.delete" type="button" class="btn btn--ghost btn--sm" @click="removeIdea">Delete</button>
                    </div>

                    <CommentThread
                        v-if="showComments"
                        :idea="idea"
                        @count-changed="idea.commentCount = $event"
                    />

                    <div v-if="versionCount" class="idea__versions">
                        <p class="idea__versions-title">
                            <button type="button" class="btn btn--ghost btn--sm" style="padding:0" @click="showVersions = !showVersions">
                                {{ showVersions ? '▾' : '▸' }} {{ versionCount }} version{{ versionCount === 1 ? '' : 's' }} of this idea
                            </button>
                        </p>

                        <template v-if="showVersions">
                            <IdeaCard
                                v-for="version in idea.versions"
                                :key="version.id"
                                :idea="version"
                                nested
                                @updated="$emit('updated', $event)"
                                @removed="$emit('removed', $event)"
                                @open="$emit('open', $event)"
                            />
                        </template>
                    </div>
                </div>
            </article>
        `,
    };
    // Registered by name so the component can render versions of itself.
    IdeaStorm.components.IdeaCard.components.IdeaCard = IdeaStorm.components.IdeaCard;

    /** Filters, sorting and the list of ideas for one project. */
    IdeaStorm.components.IdeaBoard = {
        components: {
            IdeaCard: IdeaStorm.components.IdeaCard,
            IdeaComposer: IdeaStorm.components.IdeaComposer,
            SignInPrompt: IdeaStorm.components.SignInPrompt,
        },
        props: {
            project: { type: Object, required: true },
            counts: { type: Object, default: () => ({}) },
            focusIdeaId: { type: Number, default: null },
        },
        emits: ['idea-count-changed'],
        data() {
            return {
                ideas: [],
                loading: true,
                status: 'all',
                sort: 'recent',
                search: '',
                composing: false,
                branchingFrom: null,
            };
        },
        setup() {
            return { store, utils };
        },
        computed: {
            total() {
                return Object.values(this.counts).reduce((sum, value) => sum + value, 0);
            },
            composerOpen() {
                return this.composing || this.branchingFrom !== null;
            },
        },
        created() {
            this.debouncedSearch = utils.debounce(() => this.load(), 300);
        },
        mounted() {
            this.load().then(() => this.scrollToFocus());
        },
        watch: {
            status() { this.load(); },
            sort() { this.load(); },
            search() { this.debouncedSearch(); },
        },
        methods: {
            async load() {
                this.loading = true;
                if (this.controller) {
                    this.controller.abort();
                }
                this.controller = new AbortController();

                try {
                    const data = await api.ideas.list(
                        this.project.slug,
                        { status: this.status, sort: this.sort, q: this.search },
                        this.controller.signal,
                    );
                    this.ideas = data.ideas;
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        toasts.fromError(error);
                    }
                } finally {
                    this.loading = false;
                }
            },

            scrollToFocus() {
                if (!this.focusIdeaId) {
                    return;
                }
                this.$nextTick(() => {
                    const node = document.getElementById('idea-' + this.focusIdeaId);
                    if (node) {
                        node.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        node.style.outline = '2px solid var(--accent)';
                        window.setTimeout(() => { node.style.outline = ''; }, 2500);
                    }
                });
            },

            onCreated(idea) {
                if (idea.isVersion) {
                    const parent = this.ideas.find((candidate) => candidate.id === idea.parentId);
                    if (parent) {
                        parent.versions.push(idea);
                    } else {
                        this.load();
                    }
                } else {
                    this.ideas.unshift(idea);
                    // Versions are not counted as ideas of their own, here or on the server.
                    this.$emit('idea-count-changed', 1);
                }
                this.composing = false;
                this.branchingFrom = null;
            },

            onRemoved(idea) {
                const index = this.ideas.findIndex((candidate) => candidate.id === idea.id);
                if (index !== -1) {
                    this.ideas.splice(index, 1);
                    this.$emit('idea-count-changed', -1);
                    return;
                }

                // A version: find the parent it hangs off and drop it there.
                this.ideas.forEach((parent) => {
                    const versionIndex = (parent.versions || []).findIndex((candidate) => candidate.id === idea.id);
                    if (versionIndex !== -1) {
                        parent.versions.splice(versionIndex, 1);
                    }
                });
            },

            startBranch(idea) {
                this.branchingFrom = idea;
                this.composing = false;
                this.$nextTick(() => {
                    const node = this.$refs.composerAnchor;
                    if (node) {
                        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
            },

            openIdea(idea) {
                window.history.pushState({}, '', '/projects/' + idea.projectSlug + '/ideas/' + idea.id);
                const node = document.getElementById('idea-' + idea.id);
                if (node) {
                    node.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            },
        },
        template: `
            <div>
                <div class="toolbar">
                    <input class="toolbar__search" type="search" v-model="search" placeholder="Search ideas…" aria-label="Search ideas">

                    <label class="row" style="gap:.4rem">
                        <span class="faint">Sort</span>
                        <select v-model="sort" style="width:auto">
                            <option value="recent">Newest first</option>
                            <option value="top">Most votes</option>
                            <option value="discussed">Most discussed</option>
                            <option value="versions">Most versions</option>
                        </select>
                    </label>

                    <span class="spacer"></span>

                    <button
                        v-if="store.isSignedIn && project.permissions.submitIdea"
                        type="button"
                        class="btn btn--primary"
                        @click="composing = !composing; branchingFrom = null"
                    >{{ composing ? 'Close' : '+ Submit an idea' }}</button>
                    <span v-else-if="store.isSignedIn" class="badge">{{ project.status.label }}</span>
                </div>

                <div class="chips" style="margin-bottom:1.1rem">
                    <button type="button" class="chip" :aria-pressed="status === 'all'" @click="status = 'all'">
                        All<span class="chip__count">{{ total }}</span>
                    </button>
                    <button
                        v-for="option in store.vocabulary.ideaStatuses"
                        :key="option.value"
                        type="button"
                        class="chip"
                        :aria-pressed="status === option.value"
                        @click="status = option.value"
                    >
                        {{ option.label }}<span class="chip__count">{{ counts[option.value] || 0 }}</span>
                    </button>
                </div>

                <div ref="composerAnchor">
                    <IdeaComposer
                        v-if="composerOpen"
                        :key="branchingFrom ? 'branch-' + branchingFrom.id : 'new'"
                        :project="project"
                        :parent="branchingFrom"
                        style="margin-bottom:1.2rem"
                        @created="onCreated"
                        @cancel="composing = false; branchingFrom = null"
                    />
                </div>

                <SignInPrompt
                    v-if="!store.isSignedIn"
                    message="Anyone can read the ideas here. Sign in to add one, comment or vote."
                    style="margin-bottom:1.2rem"
                />

                <div v-if="loading" class="idea-list">
                    <div class="skeleton skeleton--card"></div>
                    <div class="skeleton skeleton--card"></div>
                </div>

                <p v-else-if="!ideas.length" class="empty">
                    {{ search ? 'No idea matches “' + search + '”.' : 'No ideas here yet — the board is wide open.' }}
                </p>

                <div v-else class="idea-list">
                    <IdeaCard
                        v-for="idea in ideas"
                        :key="idea.id"
                        :idea="idea"
                        :expanded="idea.id === focusIdeaId"
                        @branch="startBranch"
                        @removed="onRemoved"
                        @open="openIdea"
                    />
                </div>
            </div>
        `,
    };
})(window.IdeaStorm);
