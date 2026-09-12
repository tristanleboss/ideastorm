/*
 * Project directory, project detail and the team panel where members are assigned to roles.
 */
(function (IdeaStorm) {
    'use strict';

    const { api, store, toasts, utils } = IdeaStorm;

    /** Landing page: every project, searchable, plus the form to start a new one. */
    IdeaStorm.components.ProjectDirectory = {
        components: {
            AppModal: IdeaStorm.components.AppModal,
        },
        emits: ['open'],
        data() {
            return {
                projects: [],
                loading: true,
                search: '',
                creating: false,
                draft: { name: '', summary: '', description: '', accentColor: '#4f46e5' },
                errors: {},
                saving: false,
            };
        },
        setup() {
            return { store, utils };
        },
        created() {
            this.debouncedSearch = utils.debounce(() => this.load(), 300);
        },
        mounted() {
            this.load();
        },
        watch: {
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
                    const data = await api.projects.list(this.search, this.controller.signal);
                    this.projects = data.projects;
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        toasts.fromError(error);
                    }
                } finally {
                    this.loading = false;
                }
            },

            async createProject() {
                this.saving = true;
                this.errors = {};
                try {
                    const data = await api.projects.create(this.draft);
                    toasts.success('Project created — you are its owner.');
                    this.creating = false;
                    this.draft = { name: '', summary: '', description: '', accentColor: '#4f46e5' };
                    this.$emit('open', data.project.slug);
                } catch (error) {
                    this.errors = error.fields || {};
                    toasts.fromError(error);
                } finally {
                    this.saving = false;
                }
            },
        },
        template: `
            <div>
                <header class="page-head">
                    <div class="page-head__title">
                        <h1>Projects</h1>
                        <span class="badge">{{ projects.length }}</span>
                        <span class="spacer"></span>
                        <button v-if="store.isSignedIn" type="button" class="btn btn--primary btn--sm" @click="creating = true">+ New project</button>
                    </div>
                    <p class="page-head__lead">
                        Pick a project to see who is working on it and what people have suggested.
                        Reading is open to everyone; adding an idea takes an account.
                    </p>
                </header>

                <div class="toolbar">
                    <input class="toolbar__search" type="search" v-model="search" placeholder="Search projects…" aria-label="Search projects">
                </div>

                <div v-if="loading" class="project-grid">
                    <div class="skeleton skeleton--card"></div>
                    <div class="skeleton skeleton--card"></div>
                    <div class="skeleton skeleton--card"></div>
                </div>

                <p v-else-if="!projects.length" class="empty">
                    {{ search ? 'No project matches “' + search + '”.' : 'No projects yet.' }}
                </p>

                <div v-else class="project-grid">
                    <a
                        v-for="project in projects"
                        :key="project.id"
                        class="card project-card"
                        :style="{ '--accent': project.accentColor }"
                        :href="'/projects/' + project.slug"
                        @click.prevent="$emit('open', project.slug)"
                    >
                        <div class="row row--wrap" style="gap:.4rem">
                            <span class="project-card__name">{{ project.name }}</span>
                            <span class="spacer"></span>
                            <span class="badge" :class="project.acceptsIdeas ? 'badge--success' : ''">{{ project.status.label }}</span>
                        </div>
                        <p class="project-card__summary">{{ project.summary }}</p>
                        <div class="project-card__meta">
                            <span>💡 {{ project.ideaCount }} idea{{ project.ideaCount === 1 ? '' : 's' }}</span>
                            <span>👥 {{ project.memberCount }} member{{ project.memberCount === 1 ? '' : 's' }}</span>
                        </div>
                    </a>
                </div>

                <AppModal v-if="creating" title="Start a project" @close="creating = false">
                    <form @submit.prevent="createProject">
                        <div class="field" :class="{ 'field--invalid': errors.name }">
                            <label for="project-name">Name</label>
                            <input id="project-name" type="text" v-model="draft.name" required maxlength="180">
                            <p v-for="message in errors.name" :key="message" class="field__error">{{ message }}</p>
                        </div>

                        <div class="field" :class="{ 'field--invalid': errors.summary }">
                            <label for="project-summary">One-line summary</label>
                            <input id="project-summary" type="text" v-model="draft.summary" required maxlength="400">
                            <p v-for="message in errors.summary" :key="message" class="field__error">{{ message }}</p>
                        </div>

                        <div class="field">
                            <label for="project-description">What is this project about? <span class="faint">(optional)</span></label>
                            <textarea id="project-description" v-model="draft.description" rows="4"></textarea>
                        </div>

                        <div class="field">
                            <label for="project-accent">Accent colour</label>
                            <input id="project-accent" type="color" v-model="draft.accentColor">
                        </div>

                        <div class="row">
                            <button type="submit" class="btn btn--primary" :disabled="saving">{{ saving ? 'Creating…' : 'Create project' }}</button>
                            <button type="button" class="btn btn--ghost" @click="creating = false">Cancel</button>
                        </div>
                    </form>
                </AppModal>
            </div>
        `,
    };

    /** The team behind a project: who holds which role, and the controls to change that. */
    IdeaStorm.components.MemberPanel = {
        components: {
            UserAvatar: IdeaStorm.components.UserAvatar,
            AppModal: IdeaStorm.components.AppModal,
        },
        props: {
            project: { type: Object, required: true },
            members: { type: Array, default: () => [] },
        },
        emits: ['changed'],
        data() {
            return {
                adding: false,
                query: '',
                results: [],
                searching: false,
                selected: null,
                role: 'contributor',
                focus: '',
                saving: false,
            };
        },
        setup() {
            return { store, utils };
        },
        created() {
            this.debouncedSearch = utils.debounce(() => this.runSearch(), 300);
        },
        computed: {
            canManage() {
                return this.project.permissions.manageMembers;
            },
            /** Members bucketed by role so the panel reads like an org chart, not a flat list. */
            grouped() {
                const groups = [];
                store.vocabulary.projectRoles.forEach((role) => {
                    const people = this.members.filter((member) => member.role.value === role.value);
                    if (people.length) {
                        groups.push({ role, people });
                    }
                });
                return groups;
            },
        },
        watch: {
            query() { this.debouncedSearch(); },
        },
        methods: {
            async runSearch() {
                if (!store.isSignedIn) {
                    return;
                }
                this.searching = true;
                if (this.controller) {
                    this.controller.abort();
                }
                this.controller = new AbortController();

                try {
                    const data = await api.users.search(this.query, this.controller.signal);
                    const assigned = new Set(this.members.map((member) => member.user.id));
                    this.results = data.users.filter((user) => !assigned.has(user.id));
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        toasts.fromError(error);
                    }
                } finally {
                    this.searching = false;
                }
            },

            openAdd() {
                this.adding = true;
                this.query = '';
                this.selected = null;
                this.role = 'contributor';
                this.focus = '';
                this.runSearch();
            },

            async add() {
                if (!this.selected) {
                    return;
                }
                this.saving = true;
                try {
                    const data = await api.projects.addMember(this.project.slug, {
                        userId: this.selected.id,
                        role: this.role,
                        focus: this.focus,
                    });
                    this.$emit('changed', { type: 'added', member: data.member });
                    toasts.success(this.selected.displayName + ' joined as ' + data.member.role.label.toLowerCase() + '.');
                    this.adding = false;
                } catch (error) {
                    toasts.fromError(error);
                } finally {
                    this.saving = false;
                }
            },

            async changeRole(member, event) {
                const value = event.target.value;
                try {
                    const data = await api.projects.updateMember(this.project.slug, member.id, { role: value });
                    this.$emit('changed', { type: 'updated', member: data.member });
                } catch (error) {
                    event.target.value = member.role.value;
                    toasts.fromError(error);
                }
            },

            async remove(member) {
                if (!window.confirm('Remove ' + member.user.displayName + ' from this project?')) {
                    return;
                }
                try {
                    await api.projects.removeMember(this.project.slug, member.id);
                    this.$emit('changed', { type: 'removed', member });
                } catch (error) {
                    toasts.fromError(error);
                }
            },
        },
        template: `
            <section class="card" style="padding:1rem">
                <div class="row row--between" style="margin-bottom:.8rem">
                    <h2 style="margin:0;font-size:1.05rem">Team</h2>
                    <button v-if="canManage" type="button" class="btn btn--sm" @click="openAdd">+ Assign someone</button>
                </div>

                <p v-if="!members.length" class="faint" style="margin:0">Nobody is assigned to this project yet.</p>

                <div v-for="group in grouped" :key="group.role.value" style="margin-bottom:.9rem">
                    <p class="idea__versions-title" :title="group.role.description">{{ group.role.label }}</p>
                    <ul class="member-list">
                        <li v-for="member in group.people" :key="member.id" class="member">
                            <UserAvatar :user="member.user" />
                            <div class="member__body">
                                <div class="member__name">{{ member.user.displayName }}</div>
                                <div v-if="member.focus" class="member__focus">{{ member.focus }}</div>
                                <div v-else class="member__focus">Joined {{ utils.absoluteDate(member.joinedAt) }}</div>
                            </div>
                            <div v-if="canManage" class="member__actions">
                                <select
                                    :value="member.role.value"
                                    @change="changeRole(member, $event)"
                                    style="width:auto;font-size:.8rem;padding:.22rem .35rem"
                                    :aria-label="'Role for ' + member.user.displayName"
                                >
                                    <option v-for="role in store.vocabulary.projectRoles" :key="role.value" :value="role.value">{{ role.label }}</option>
                                </select>
                                <button type="button" class="btn btn--ghost btn--sm" :aria-label="'Remove ' + member.user.displayName" @click="remove(member)">×</button>
                            </div>
                        </li>
                    </ul>
                </div>

                <AppModal v-if="adding" title="Assign someone to this project" @close="adding = false">
                    <div class="field">
                        <label for="member-search">Find an account</label>
                        <input id="member-search" type="search" v-model="query" placeholder="Name or email">
                        <p class="field__hint">People need an IdeaStorm account before they can be assigned.</p>
                    </div>

                    <p v-if="searching" class="faint">Searching…</p>
                    <p v-else-if="!results.length" class="faint">No account matches that search.</p>

                    <ul v-else class="member-list" style="max-height:240px;overflow:auto">
                        <li
                            v-for="user in results"
                            :key="user.id"
                            class="member"
                            :style="{ borderColor: selected && selected.id === user.id ? 'var(--accent)' : '' }"
                        >
                            <UserAvatar :user="user" />
                            <div class="member__body">
                                <div class="member__name">{{ user.displayName }}</div>
                                <div class="member__focus">{{ user.email }}</div>
                            </div>
                            <button type="button" class="btn btn--sm" @click="selected = user">
                                {{ selected && selected.id === user.id ? 'Selected' : 'Select' }}
                            </button>
                        </li>
                    </ul>

                    <template v-if="selected">
                        <div class="field" style="margin-top:1rem">
                            <label for="member-role">Role</label>
                            <select id="member-role" v-model="role">
                                <option v-for="option in store.vocabulary.projectRoles" :key="option.value" :value="option.value">
                                    {{ option.label }} — {{ option.description }}
                                </option>
                            </select>
                        </div>

                        <div class="field">
                            <label for="member-focus">What are they focused on? <span class="faint">(optional)</span></label>
                            <input id="member-focus" type="text" v-model="focus" maxlength="180" placeholder="e.g. owns the onboarding flow">
                        </div>
                    </template>

                    <div class="row" style="margin-top:.6rem">
                        <button type="button" class="btn btn--primary" :disabled="!selected || saving" @click="add">
                            {{ saving ? 'Assigning…' : 'Assign' }}
                        </button>
                        <button type="button" class="btn btn--ghost" @click="adding = false">Cancel</button>
                    </div>
                </AppModal>
            </section>
        `,
    };

    /** A single project: what it is, who is on it, and its board of ideas. */
    IdeaStorm.components.ProjectDetail = {
        components: {
            IdeaBoard: IdeaStorm.components.IdeaBoard,
            MemberPanel: IdeaStorm.components.MemberPanel,
            AppModal: IdeaStorm.components.AppModal,
        },
        props: {
            slug: { type: String, required: true },
            focusIdeaId: { type: Number, default: null },
        },
        emits: ['back'],
        data() {
            return {
                project: null,
                members: [],
                counts: {},
                loading: true,
                notFound: false,
                editing: false,
                draft: {},
                saving: false,
            };
        },
        setup() {
            return { store, utils };
        },
        mounted() {
            this.load();
        },
        watch: {
            slug() { this.load(); },
        },
        methods: {
            async load() {
                this.loading = true;
                this.notFound = false;
                try {
                    const data = await api.projects.show(this.slug);
                    this.project = data.project;
                    this.members = data.members;
                    this.counts = data.ideaCountsByStatus;
                    document.title = this.project.name + ' · IdeaStorm';
                } catch (error) {
                    if (error.status === 404) {
                        this.notFound = true;
                    } else {
                        toasts.fromError(error);
                    }
                } finally {
                    this.loading = false;
                }
            },

            onMemberChanged(event) {
                if (event.type === 'added') {
                    this.members.push(event.member);
                } else if (event.type === 'removed') {
                    const index = this.members.findIndex((member) => member.id === event.member.id);
                    if (index !== -1) {
                        this.members.splice(index, 1);
                    }
                } else {
                    const index = this.members.findIndex((member) => member.id === event.member.id);
                    if (index !== -1) {
                        this.members.splice(index, 1, event.member);
                    }
                }
                this.project.memberCount = this.members.length;
            },

            onIdeaCountChanged(delta) {
                this.project.ideaCount = Math.max(0, this.project.ideaCount + delta);
                // Freshly submitted ideas always land in "proposed".
                if (delta > 0) {
                    this.counts.proposed = (this.counts.proposed || 0) + delta;
                }
            },

            openEdit() {
                this.draft = {
                    name: this.project.name,
                    summary: this.project.summary,
                    description: this.project.description || '',
                    accentColor: this.project.accentColor,
                    status: this.project.status.value,
                };
                this.editing = true;
            },

            async saveProject() {
                this.saving = true;
                try {
                    const data = await api.projects.update(this.project.slug, this.draft);
                    this.project = data.project;
                    this.editing = false;
                    toasts.success('Project updated.');
                } catch (error) {
                    toasts.fromError(error);
                } finally {
                    this.saving = false;
                }
            },
        },
        template: `
            <div>
                <p style="margin-bottom:1rem">
                    <a href="/" @click.prevent="$emit('back')">← All projects</a>
                </p>

                <template v-if="loading">
                    <div class="skeleton skeleton--line" style="width:40%;height:1.6rem"></div>
                    <div class="skeleton skeleton--line" style="width:70%"></div>
                    <div class="skeleton skeleton--card" style="margin-top:1.2rem"></div>
                </template>

                <p v-else-if="notFound" class="empty">That project does not exist (or was removed).</p>

                <template v-else-if="project">
                    <header class="card project-hero" :style="{ '--accent': project.accentColor }">
                        <div class="project-hero__top">
                            <div class="flex-body">
                                <h1 class="project-hero__title">{{ project.name }}</h1>
                                <p class="project-hero__summary">{{ project.summary }}</p>
                            </div>
                            <div class="row row--wrap" style="gap:.4rem">
                                <span v-if="project.viewerRole" class="badge badge--accent" :title="project.viewerRole.label">You: {{ project.viewerRole.label }}</span>
                                <span class="badge" :class="project.acceptsIdeas ? 'badge--success' : ''">{{ project.status.label }}</span>
                                <button v-if="project.permissions.edit" type="button" class="btn btn--sm" @click="openEdit">Edit</button>
                            </div>
                        </div>

                        <p v-if="project.description" class="project-hero__description">{{ project.description }}</p>

                        <div class="project-hero__stats">
                            <span>💡 {{ project.ideaCount }} idea{{ project.ideaCount === 1 ? '' : 's' }}</span>
                            <span>👥 {{ members.length }} member{{ members.length === 1 ? '' : 's' }}</span>
                            <span>📅 Started {{ utils.absoluteDate(project.createdAt) }}</span>
                        </div>
                    </header>

                    <div class="layout-split">
                        <IdeaBoard
                            :project="project"
                            :counts="counts"
                            :focus-idea-id="focusIdeaId"
                            @idea-count-changed="onIdeaCountChanged"
                        />

                        <MemberPanel
                            :project="project"
                            :members="members"
                            @changed="onMemberChanged"
                        />
                    </div>

                    <AppModal v-if="editing" title="Edit project" @close="editing = false">
                        <form @submit.prevent="saveProject">
                            <div class="field">
                                <label for="edit-name">Name</label>
                                <input id="edit-name" type="text" v-model="draft.name" maxlength="180" required>
                            </div>
                            <div class="field">
                                <label for="edit-summary">One-line summary</label>
                                <input id="edit-summary" type="text" v-model="draft.summary" maxlength="400" required>
                            </div>
                            <div class="field">
                                <label for="edit-description">Description</label>
                                <textarea id="edit-description" v-model="draft.description" rows="5"></textarea>
                            </div>
                            <div class="field">
                                <label for="edit-status">Status</label>
                                <select id="edit-status" v-model="draft.status">
                                    <option v-for="option in store.vocabulary.projectStatuses" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="edit-accent">Accent colour</label>
                                <input id="edit-accent" type="color" v-model="draft.accentColor">
                            </div>
                            <div class="row">
                                <button type="submit" class="btn btn--primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                                <button type="button" class="btn btn--ghost" @click="editing = false">Cancel</button>
                            </div>
                        </form>
                    </AppModal>
                </template>
            </div>
        `,
    };
})(window.IdeaStorm);
