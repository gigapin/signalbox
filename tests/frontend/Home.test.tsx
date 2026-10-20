/** Verify the existing public page using actual Inertia props and accessible DOM queries. */
import { screen } from '@testing-library/react';
import { expect, test } from 'vitest';
import { renderInertia } from './renderInertia';

/** Verify the page exposes its public identity through named landmarks and one clear heading. */
function rendersPublicHome(): void {
    renderInertia('Signalbox Test');
    expect(screen.getByRole('main')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1, name: 'Signalbox Test' })).toBeVisible();
    expect(screen.getByRole('region', { name: 'Signalbox Test' })).toBeInTheDocument();
    expect(screen.getByText(/Monitoring features will follow/)).toBeVisible();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
}

/** Treat server-provided product names as plain text rather than executable markup. */
function escapesServerProps(): void {
    /** Synthetic hostile value tests encoding without using real customer data or executing scripts. */
    const hostileName = '<img src=x onerror=alert(1)>';
    renderInertia(hostileName);
    expect(screen.getByRole('heading', { level: 1, name: hostileName })).toBeVisible();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
}

test('renders the public shell through actual Inertia context', rendersPublicHome);
test('escapes markup in server-provided shared props', escapesServerProps);
