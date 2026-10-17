/*
 * Boots the Vue application and keeps it in step with the address bar.
 *
 * Routing is deliberately tiny: three URL shapes, matched with a regular expression and
 * kept in sync through the History API, so a project or an idea can be linked to directly
 * while navigation inside the app stays a single fetch rather than a page load.
 */
(function (IdeaStorm) {
    'use strict';

    const mount = document.getElementById('app');
    if (!mount) {
        return;
    }

    if (!window.Vue) {
        mount.innerHTML = '<div class="shell"><p class="flash flash--error">'
            + 'IdeaStorm could not load Vue. Check your network connection, or serve the bundled copy '
            + 'from <code>public/js/vendor/vue.global.prod.js</code>.</p></div>';
        return;
    }

    const { createApp } = window.Vue;
    const { store, components } = IdeaStorm;

    /** Turns a path into {name, slug, ideaId}. */
    function parseRoute(fullPathname) {
        const pathname = IdeaStorm.stripBase(fullPathname);
        const ideaMatch = pathname.match(/^\/projects\/([a-z0-9-]+)\/ideas\/(\d+)\/?$/);
        if (ideaMatch) {
            return { name: 'idea', slug: ideaMatch[1], ideaId: Number(ideaMatch[2]) };
        }

        const projectMatch = pathname.match(/^\/projects\/([a-z0-9-]+)\/?$/);
        if (projectMatch) {
            return { name: 'project', slug: projectMatch[1], ideaId: null };
        }

        return { name: 'home', slug: null, ideaId: null };
    }

    const App = {
        components: {
            ProjectDirectory: components.ProjectDirectory,
            ProjectDetail: components.ProjectDetail,
            ToastStack: components.ToastStack,
        },
        data() {
            return {
                route: parseRoute(window.location.pathname),
            };
        },
        setup() {
            return { store };
        },
        mounted() {
            window.addEventListener('popstate', this.onPopState);
        },
        unmounted() {
            window.removeEventListener('popstate', this.onPopState);
        },
        methods: {
            onPopState() {
                this.route = parseRoute(window.location.pathname);
            },

            navigate(path) {
                const target = IdeaStorm.url(path);
                if (window.location.pathname !== target) {
                    window.history.pushState({}, '', target);
                }
                this.route = parseRoute(target);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },

            openProject(slug) {
                this.navigate('/projects/' + slug);
            },

            goHome() {
                document.title = 'IdeaStorm';
                this.navigate('/');
            },
        },
        template: `
            <div class="shell">
                <ProjectDirectory
                    v-if="route.name === 'home'"
                    @open="openProject"
                />
                <ProjectDetail
                    v-else
                    :key="route.slug"
                    :slug="route.slug"
                    :focus-idea-id="route.ideaId"
                    @back="goHome"
                />
            </div>
            <ToastStack />
        `,
    };

    // The bootstrap payload gives the first render an accurate signed-in state; the full
    // session (vocabulary, upload limits, social providers) follows over AJAX.
    store.hydrate();

    const app = createApp(App);
    app.config.errorHandler = function (error, instance, info) {
        console.error('IdeaStorm: unhandled component error', error, info);
        IdeaStorm.toasts.error('Something went wrong in the interface. Reloading the page usually clears it.');
    };
    app.mount('#app');

    store.load();
})(window.IdeaStorm);
