/** Verify keyboard activation of an actual Inertia Link without issuing network requests. */
import { Link, router } from '@inertiajs/react';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';
import { expect, test, vi } from 'vitest';
import { renderInertia } from './renderInertia';

/**
 * Provide a test-only navigation fixture, without adding navigation or account features to the product.
 * @returns A native keyboard-focusable anchor rendered by the installed Inertia adapter.
 */
function NavigationFixture(): ReactElement {
    return <Link href="/test-destination">Open destination</Link>;
}

/** Verify a user can tab to an Inertia link and activate its actual event handling with Enter. */
async function navigatesWithKeyboard(): Promise<void> {
    /** User-event session models focus and key dispatch through the DOM. */
    const user = userEvent.setup();
    /** Spy at the router boundary so the real Link component never contacts an HTTP endpoint. */
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', NavigationFixture);
    await user.tab();
    expect(screen.getByRole('link', { name: 'Open destination' })).toHaveFocus();
    await user.keyboard('{Enter}');
    expect(visit).toHaveBeenCalledOnce();
    expect(visit).toHaveBeenCalledWith('/test-destination', expect.any(Object));
}

test('supports keyboard navigation through the Inertia adapter', navigatesWithKeyboard);
