/** Exercise actual Inertia head replacement and public HTML handoff without any network request. */
import { cleanup, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, test } from 'vitest';
import type {
    PublicStatusPage,
    StatusPageMetadata,
} from '../../resources/js/components/status-pages/types';
import Public from '../../resources/js/pages/StatusPages/Public';
import Subscription from '../../resources/js/pages/StatusPages/Subscription';
import Unlock from '../../resources/js/pages/StatusPages/Unlock';
import { renderInertia } from './renderInertia';

/** Synthetic public projection with no tenant or private monitor information. */
const page: PublicStatusPage = {
    title: 'Acme <API> & status',
    description: 'Current service availability.',
    theme: { mode: 'light', accent: 'emerald' },
    status: 'unknown',
    components: [],
    incidents: [],
};

/** Same five-field metadata contract as the live PHP document builder. */
const metadata: StatusPageMetadata = {
    title: 'Acme <API> & status | Signalbox',
    description: 'Availability "quoted" & <safe>.',
    robots: 'index, follow',
    canonicalUrl: 'https://app.example.com/status/acme-status',
    siteName: 'Signalbox',
};

/** Replace the setup's unmanaged empty title with the managed document title that Blade actually serves. */
beforeEach(() => {
    document.head.querySelectorAll('title').forEach((element) => element.remove());
});

/** Wait for Inertia's deferred head cleanup before removing managed tags from this test document. */
afterEach(async () => {
    cleanup();
    await waitFor(() => expect(document.head.querySelector('[inertia="robots"]')).toBeNull());
    document.head.querySelectorAll('[inertia]').forEach((element) => element.remove());
    document.getElementById('status-page-snapshot')?.remove();
});

test('replaces managed server metadata without duplicates and preserves literal text', async () => {
    document.head.insertAdjacentHTML(
        'beforeend',
        '<title inertia>Initial title</title><meta inertia="description" name="description" content="Initial description"><link inertia="canonical" rel="canonical" href="https://old.example.com">',
    );
    renderInertia('Signalbox', Public, { page, metadata });

    await waitFor(() => expect(document.title).toBe(metadata.title));
    expect(document.head.querySelectorAll('title')).toHaveLength(1);
    expect(document.head.querySelectorAll('meta[name="description"]')).toHaveLength(1);
    expect(document.head.querySelector('meta[name="description"]')).toHaveAttribute(
        'content',
        metadata.description,
    );
    expect(document.head.querySelectorAll('link[rel="canonical"]')).toHaveLength(1);
    expect(document.head.querySelector('link[rel="canonical"]')).toHaveAttribute(
        'href',
        metadata.canonicalUrl,
    );
    expect(document.head.querySelector('meta[property="og:url"]')).toHaveAttribute(
        'content',
        metadata.canonicalUrl,
    );
    expect(document.head.querySelector('meta[property="og:title"]')).toHaveAttribute(
        'content',
        metadata.title,
    );
    expect(document.head.querySelector('meta[property="og:description"]')).toHaveAttribute(
        'content',
        metadata.description,
    );
    expect(document.head.querySelector('meta[name="twitter:card"]')).toHaveAttribute(
        'content',
        'summary',
    );
    expect(document.head.querySelector('meta[name="twitter:title"]')).toHaveAttribute(
        'content',
        metadata.title,
    );
    expect(document.head.querySelector('api')).toBeNull();
});

test.each(['locked', 'unlocked', 'email'] as const)(
    'removes public canonical and social tags on a subsequent %s document',
    async (destination) => {
        const first = renderInertia('Signalbox', Public, { page, metadata });
        await waitFor(() =>
            expect(document.head.querySelector('meta[property="og:url"]')).not.toBeNull(),
        );
        first.unmount();
        const restricted: StatusPageMetadata = {
            title: 'Protected status page | Signalbox',
            description: 'Enter the page password to read this status page.',
            robots: 'noindex, nofollow, nosnippet',
            canonicalUrl: null,
            siteName: 'Signalbox',
        };

        if (destination === 'locked') {
            renderInertia('Signalbox', Unlock, { slug: 'acme-status', metadata: restricted });
        } else if (destination === 'unlocked') {
            renderInertia('Signalbox', Public, { page, metadata: restricted });
        } else {
            renderInertia('Signalbox', Subscription, {
                action: 'verify',
                actionUrl: '/signed?signature=secret',
                completed: false,
                metadata: restricted,
            });
        }

        await waitFor(() => expect(document.title).toBe(restricted.title));
        expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute(
            'content',
            restricted.robots,
        );
        expect(
            document.head.querySelectorAll(
                'link[rel="canonical"], meta[property^="og:"], meta[name^="twitter:"]',
            ),
        ).toHaveLength(0);
        expect(document.head.innerHTML).not.toContain('Acme');
        expect(document.head.innerHTML).not.toContain('signature=secret');
    },
);

test('removes the readable server summary only once the interactive public page has mounted', () => {
    document.body.insertAdjacentHTML(
        'beforeend',
        '<main id="status-page-snapshot"><h1>Acme static summary</h1></main>',
    );
    renderInertia('Signalbox', Public, { page, metadata });

    expect(document.getElementById('status-page-snapshot')).toBeNull();
    expect(screen.getAllByRole('main')).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(page.title);
});

test('fails closed when a client document has incomplete metadata props', async () => {
    renderInertia('Signalbox', Public, { page });
    await waitFor(() =>
        expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute(
            'content',
            'noindex, nofollow, nosnippet',
        ),
    );
    expect(document.title).toBe('Status page');
    expect(document.head.innerHTML).not.toContain('Acme');
    expect(document.head.querySelector('link[rel="canonical"]')).toBeNull();
});
