/*
 * IdeaStorm frontend — API client, shared state and formatting helpers.
 *
 * There is no build step: Vue comes from a CDN and these files are plain scripts that hang
 * their exports off the single `window.IdeaStorm` namespace, loaded in dependency order by
 * templates/app.html.twig.
 */
window.IdeaStorm = window.IdeaStorm || {};

(function (IdeaStorm) {
    'use strict';

    const { reactive } = window.Vue;

    /**
     * The folder the app is served from: "" at a domain root, "/idees" when it lives under
     * http://localhost/idees. Every URL the frontend builds goes through url() so the same
     * code runs in either place.
     */
    const meta = document.querySelector('meta[name="base-path"]');
    const basePath = ((meta && meta.getAttribute('content')) || '').replace(/\/+$/, '');

    IdeaStorm.basePath = basePath;
    /** "/api/projects" -> "/idees/api/projects" (a no-op at the domain root). */
    IdeaStorm.url = (path) => basePath + path;
    /** The inverse: "/idees/projects/x" -> "/projects/x", for matching routes. */
    IdeaStorm.stripBase = (pathname) => (
        basePath && pathname.startsWith(basePath) ? (pathname.slice(basePath.length) || '/') : pathname
    );

    /** An API call that came back with a non-2xx status. */
    class ApiError extends Error {
        constructor(message, status, payload) {
            super(message);
            this.name = 'ApiError';
            this.status = status;
            this.payload = payload || {};
            /** Field-level validation messages, keyed by property path. */
            this.fields = this.payload.fields || {};
            this.code = this.payload.error || 'error';
        }

        get isUnauthenticated() {
            return this.status === 401;
        }
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return (meta && meta.getAttribute('content')) || '';
    }

    /**
     * One place where every request is made, so the CSRF header, the JSON decoding and the
     * error shape are handled identically no matter which component is calling.
     */
    async function request(method, path, options) {
        const settings = options || {};
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        let body;

        if (settings.body instanceof FormData) {
            // Let the browser set Content-Type so it can add the multipart boundary.
            body = settings.body;
        } else if (settings.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(settings.body);
        }

        if (method !== 'GET' && method !== 'HEAD') {
            headers['X-CSRF-Token'] = csrfToken();
        }

        let url = IdeaStorm.url(path);
        if (settings.query) {
            const params = new URLSearchParams();
            Object.entries(settings.query).forEach(([key, value]) => {
                if (value !== undefined && value !== null && value !== '') {
                    params.append(key, value);
                }
            });
            const queryString = params.toString();
            if (queryString) {
                url += (url.includes('?') ? '&' : '?') + queryString;
            }
        }

        let response;
        try {
            response = await fetch(url, {
                method,
                headers,
                body,
                credentials: 'same-origin',
                signal: settings.signal,
            });
        } catch (error) {
            if (error.name === 'AbortError') {
                throw error;
            }
            throw new ApiError('Could not reach the server. Check your connection and try again.', 0, {});
        }

        const isJson = (response.headers.get('Content-Type') || '').includes('application/json');
        const payload = isJson ? await response.json().catch(() => ({})) : {};

        if (!response.ok) {
            throw new ApiError(payload.message || 'Something went wrong (' + response.status + ').', response.status, payload);
        }

        return payload;
    }

    const api = {
        ApiError,
        csrfToken,
        get: (path, options) => request('GET', path, options),
        post: (path, body, options) => request('POST', path, Object.assign({ body }, options)),
        patch: (path, body, options) => request('PATCH', path, Object.assign({ body }, options)),
        delete: (path, options) => request('DELETE', path, options),

        session: () => request('GET', '/api/session'),

        projects: {
            list: (search, signal) => request('GET', '/api/projects', { query: { q: search }, signal }),
            show: (slug) => request('GET', '/api/projects/' + encodeURIComponent(slug)),
            create: (data) => request('POST', '/api/projects', { body: data }),
            update: (slug, data) => request('PATCH', '/api/projects/' + encodeURIComponent(slug), { body: data }),
            members: (slug) => request('GET', '/api/projects/' + encodeURIComponent(slug) + '/members'),
            addMember: (slug, data) => request('POST', '/api/projects/' + encodeURIComponent(slug) + '/members', { body: data }),
            updateMember: (slug, id, data) => request('PATCH', '/api/projects/' + encodeURIComponent(slug) + '/members/' + id, { body: data }),
            removeMember: (slug, id) => request('DELETE', '/api/projects/' + encodeURIComponent(slug) + '/members/' + id),
        },

        ideas: {
            list: (slug, params, signal) => request('GET', '/api/projects/' + encodeURIComponent(slug) + '/ideas', { query: params, signal }),
            show: (id) => request('GET', '/api/ideas/' + id),
            create: (slug, formData) => request('POST', '/api/projects/' + encodeURIComponent(slug) + '/ideas', { body: formData }),
            update: (id, data) => request('PATCH', '/api/ideas/' + id, { body: data }),
            remove: (id) => request('DELETE', '/api/ideas/' + id),
            setStatus: (id, status) => request('POST', '/api/ideas/' + id + '/status', { body: { status } }),
            vote: (id, value) => request('POST', '/api/ideas/' + id + '/vote', { body: { value } }),
            addAttachments: (id, formData) => request('POST', '/api/ideas/' + id + '/attachments', { body: formData }),
            removeAttachment: (id) => request('DELETE', '/api/attachments/' + id),
        },

        comments: {
            list: (ideaId) => request('GET', '/api/ideas/' + ideaId + '/comments'),
            create: (ideaId, data) => request('POST', '/api/ideas/' + ideaId + '/comments', { body: data }),
            update: (id, body) => request('PATCH', '/api/comments/' + id, { body: { body } }),
            remove: (id) => request('DELETE', '/api/comments/' + id),
        },

        users: {
            search: (term, signal) => request('GET', '/api/users', { query: { q: term }, signal }),
        },
    };

    /** Application-wide state: who is signed in, and what the enum pickers should offer. */
    const store = reactive({
        user: null,
        ready: false,
        socialProviders: [],
        vocabulary: { ideaStatuses: [], projectStatuses: [], projectRoles: [], attachmentKinds: [] },
        uploads: { maxSize: 64 * 1024 * 1024, maxSizeLabel: '64 MB', acceptedMimeTypes: [] },

        get isSignedIn() {
            return this.user !== null;
        },

        /** Reads the JSON island rendered by the Twig shell, so boot does not block on fetch. */
        hydrate() {
            const node = document.getElementById('ideastorm-bootstrap');
            if (!node) {
                return;
            }
            try {
                const data = JSON.parse(node.textContent || '{}');
                this.user = data.user || null;
            } catch (error) {
                console.warn('IdeaStorm: could not read the bootstrap payload.', error);
            }
        },

        async load() {
            try {
                const data = await api.session();
                this.user = data.user || null;
                this.socialProviders = data.socialProviders || [];
                this.vocabulary = data.vocabulary || this.vocabulary;
                this.uploads = data.uploads || this.uploads;
            } catch (error) {
                console.warn('IdeaStorm: could not load the session.', error);
            } finally {
                this.ready = true;
            }
        },

        statusLabel(kind, value) {
            const list = this.vocabulary[kind] || [];
            const match = list.find((entry) => entry.value === value);
            return match ? match.label : value;
        },
    });

    /** Transient notifications shown in the bottom-right corner. */
    const toasts = reactive({
        items: [],
        nextId: 1,

        push(message, tone) {
            const id = this.nextId++;
            this.items.push({ id, message, tone: tone || 'info' });
            window.setTimeout(() => this.dismiss(id), tone === 'error' ? 8000 : 4500);
            return id;
        },
        success(message) { return this.push(message, 'success'); },
        error(message) { return this.push(message, 'error'); },
        dismiss(id) {
            const index = this.items.findIndex((item) => item.id === id);
            if (index !== -1) {
                this.items.splice(index, 1);
            }
        },
        /** Turns an ApiError into a toast, with a nudge to sign in when that is the problem. */
        fromError(error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            if (error instanceof ApiError && error.isUnauthenticated) {
                this.error('Please sign in to do that.');
                return;
            }
            this.error((error && error.message) || 'Something went wrong.');
        },
    });

    const utils = {
        /** "3 minutes ago", "yesterday", or a plain date once it is more than a week old. */
        relativeTime(iso) {
            if (!iso) {
                return '';
            }
            const date = new Date(iso);
            const seconds = Math.round((Date.now() - date.getTime()) / 1000);

            if (seconds < 45) return 'just now';
            const table = [
                [60, 'second'], [3600, 'minute'], [86400, 'hour'], [604800, 'day'],
            ];
            const divisors = { second: 1, minute: 60, hour: 3600, day: 86400 };
            for (const [limit, unit] of table) {
                if (seconds < limit) {
                    const value = Math.round(seconds / divisors[unit]);
                    return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(-value, unit);
                }
            }
            return utils.absoluteDate(iso);
        },

        absoluteDate(iso) {
            if (!iso) {
                return '';
            }
            return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(iso));
        },

        absoluteDateTime(iso) {
            if (!iso) {
                return '';
            }
            return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
        },

        /** 95 -> "1:35". Used for recording lengths. */
        duration(seconds) {
            if (seconds === null || seconds === undefined || Number.isNaN(seconds)) {
                return '';
            }
            const total = Math.max(0, Math.round(seconds));
            const minutes = Math.floor(total / 60);
            return minutes + ':' + String(total % 60).padStart(2, '0');
        },

        bytes(size) {
            const units = ['B', 'KB', 'MB', 'GB'];
            let value = Number(size) || 0;
            let unit = 0;
            while (value >= 1024 && unit < units.length - 1) {
                value /= 1024;
                unit += 1;
            }
            return (value < 10 && unit > 0 ? value.toFixed(1) : Math.round(value)) + ' ' + units[unit];
        },

        /** Debounces search-as-you-type so a keystroke does not become a request. */
        debounce(fn, wait) {
            let timer = null;
            return function debounced(...args) {
                window.clearTimeout(timer);
                timer = window.setTimeout(() => fn.apply(this, args), wait || 250);
            };
        },

        iconFor(kind) {
            return { audio: '🎙️', video: '🎬', image: '🖼️', document: '📄', other: '📎' }[kind] || '📎';
        },

        /** Maps an idea status onto one of the badge colour variants. */
        statusTone(status) {
            return {
                proposed: '', under_review: 'badge--warning', accepted: 'badge--success',
                rejected: 'badge--danger', shipped: 'badge--accent',
            }[status] || '';
        },
    };

    IdeaStorm.api = api;
    IdeaStorm.store = store;
    IdeaStorm.toasts = toasts;
    IdeaStorm.utils = utils;
    IdeaStorm.components = IdeaStorm.components || {};
})(window.IdeaStorm);
