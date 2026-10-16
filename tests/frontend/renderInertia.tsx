/** Mount actual Inertia context so page tests exercise shared props without mocking usePage. */
import { App } from '@inertiajs/react';
import { render, type RenderResult } from '@testing-library/react';
import type { ComponentProps, ComponentType } from 'react';
import Home from '../../resources/js/pages/Home';

/**
 * Render a page with a synthetic, public-only server response and the actual Inertia React provider.
 * @param appName Public product identity supplied by the simulated Laravel response.
 * @param component Page component to mount; defaults to the existing public home page.
 * @returns DOM queries and cleanup handles from React Testing Library.
 */
export function renderInertia(
    appName = 'Signalbox',
    component: ComponentType = Home,
): RenderResult {
    /** Inertia page shape derived from the installed adapter rather than a duplicate protocol interface. */
    const page: ComponentProps<typeof App>['initialPage'] = {
        component: 'Home',
        props: { appName, errors: {} },
        url: '/',
        version: null,
        clearHistory: false,
        encryptHistory: false,
        flash: {},
        rememberedState: {},
    };

    /** Resolve the imported page locally; no module fetch or server response is needed in this fixture. */
    const resolveComponent = (): ComponentType => component;

    return render(
        <App initialPage={page} initialComponent={component} resolveComponent={resolveComponent} />,
    );
}
