/** Mount actual Inertia context so page tests exercise shared props without mocking usePage. */
import { App } from '@inertiajs/react';
import { render, type RenderResult } from '@testing-library/react';
import type { ComponentProps, ComponentType } from 'react';
import { prepareHistoryNavigation } from '../../resources/js/components/app/historyNavigation';
import { NavigationGuardProvider } from '../../resources/js/components/app/NavigationGuard';
import Home from '../../resources/js/pages/Home';

/** Inertia initializes its singleton resolver once; resolve the page of the currently mounted fixture. */
let currentTestPage: ComponentType = Home;

/** Optional real page identity for lifecycle tests; existing fixtures keep the original defaults. */
interface PageContext {
    /** Server-selected component name used by partial reads and same-component navigation. */
    component?: string;
    /** Exact current URL, including validated filter and return context. */
    url?: string;
    /** Current build version, null for unversioned fixture assets. */
    version?: string | null;
}

/**
 * Render a page with a synthetic, public-only server response and the actual Inertia React provider.
 * @param appName Public product identity supplied by the simulated Laravel response.
 * @param component Page component to mount; defaults to the existing public home page.
 * @param props Additional explicit page props, including safe auth status or validation fixtures.
 * @param context Optional current page identity for navigation and recovery tests.
 * @returns DOM queries and cleanup handles from React Testing Library.
 */
export function renderInertia(
    appName = 'Signalbox',
    component: ComponentType = Home,
    props: Record<string, unknown> = {},
    context: PageContext = {},
): RenderResult {
    prepareHistoryNavigation();
    currentTestPage = component;

    /** Inertia page shape derived from the installed adapter rather than a duplicate protocol interface. */
    const page: ComponentProps<typeof App>['initialPage'] = {
        component: context.component ?? 'Home',
        props: { appName, errors: {}, ...props },
        url: context.url ?? '/',
        version: context.version ?? null,
        clearHistory: false,
        encryptHistory: false,
        flash: {},
        rememberedState: {},
    };

    /** Resolve the imported page locally; no module fetch or server response is needed in this fixture. */
    const resolveComponent = (): ComponentType => currentTestPage;

    return render(
        <NavigationGuardProvider>
            <App
                initialPage={page}
                initialComponent={component}
                resolveComponent={resolveComponent}
            />
        </NavigationGuardProvider>,
    );
}
