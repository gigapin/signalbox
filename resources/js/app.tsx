/** Mount the Inertia 2 application with typed React page modules. */
import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createElement, type ComponentType } from 'react';
import { createRoot } from 'react-dom/client';
import RequestFailureNotice from './components/RequestFailureNotice';
import { prepareHistoryNavigation } from './components/app/historyNavigation';
import { NavigationGuardProvider } from './components/app/NavigationGuard';

/** Describe the default React export required from each page module. */
interface PageModule {
    /** React component rendered for the server-selected page name. */
    default: ComponentType;
}

/** Map build-time page paths to lazy module loaders, excluding arbitrary paths. */
const pages = import.meta.glob<PageModule>('./pages/**/*.tsx');

void createInertiaApp({
    /**
     * Keep Inertia's progress bar but not its runtime `<style>` injection, which carries no CSP nonce; the same
     * rules are compiled into app.css instead.
     */
    progress: { includeCSS: false },
    /**
     * Resolve a server-selected page from the statically discovered page modules.
     * @param name Page name returned by the Laravel Inertia response.
     * @returns Module exporting the requested React component.
     * @throws Error when the requested page was not included in the build.
     */
    resolve: async (name) => {
        /** Load only page modules explicitly included by Vite's glob expansion. */
        const loadPage = pages[`./pages/${name}.tsx`];

        if (!loadPage) {
            throw new Error(`Unknown Inertia page: ${name}`);
        }

        return loadPage();
    },
    /**
     * Attach shared connection feedback and the initial Inertia component to the HTML mount element.
     * @param options Element, root component and initial props supplied by Inertia.
     */
    setup(options) {
        /** React root associated with the Inertia mount element. */
        prepareHistoryNavigation();
        const root = createRoot(options.el);
        root.render(
            createElement(
                NavigationGuardProvider,
                null,
                createElement(RequestFailureNotice),
                createElement(options.App, options.props),
            ),
        );
    },
});
