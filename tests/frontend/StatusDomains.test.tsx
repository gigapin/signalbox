/** Verify the separate hostname editor, public TXT proof, ownership checks and removal through Inertia. */
import { router } from '@inertiajs/react';
import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, test, vi } from 'vitest';
import StatusDomainForm from '../../resources/js/components/status-pages/StatusDomainForm';
import { renderInertia } from './renderInertia';

/** Synthetic pending claim, with no certificate or ownership claim presented as already complete. */
const pending = {
    hostname: 'status.example.com',
    proofName: '_signalbox.status.example.com',
    proofValue: 'signalbox-verification=synthetic-generation',
    verifiedUntil: null,
    checkedAt: null,
};

/** Render the editor on a page with no existing claim. */
function EmptyDomain() {
    return <StatusDomainForm path="/teams/7/status-pages/9" domain={null} pageId={9} />;
}

/** Render a pending claim whose proof must be published before activation. */
function PendingDomain() {
    return <StatusDomainForm path="/teams/7/status-pages/9" domain={pending} pageId={9} />;
}

afterEach(() => vi.restoreAllMocks());

test('saves a hostname separately from the page settings and displays loading state', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        options?.onStart?.({} as Parameters<NonNullable<typeof options.onStart>>[0]);
    });
    renderInertia('Signalbox', EmptyDomain);
    await user.type(screen.getByLabelText('Hostname'), 'status.example.com');
    await user.click(screen.getByRole('button', { name: 'Save hostname' }));
    expect(visit).toHaveBeenCalledWith(
        '/teams/7/status-pages/9/domain',
        expect.objectContaining({
            method: 'put',
            data: { domain: 'status.example.com' },
            preserveScroll: true,
        }),
    );
    expect(screen.getByRole('button', { name: 'Saving domain…' })).toBeDisabled();
    expect(screen.queryByRole('button', { name: 'Verify DNS ownership' })).not.toBeInTheDocument();
});

test('shows the exact TXT proof without claiming that a pending domain serves the page', () => {
    renderInertia('Signalbox', PendingDomain);
    expect(screen.getByText(/Ownership not verified/)).toBeInTheDocument();
    expect(screen.getByText(pending.proofName)).toBeInTheDocument();
    expect(screen.getByText(pending.proofValue)).toBeInTheDocument();
    expect(screen.getByLabelText('Hostname')).toHaveValue(pending.hostname);
});

test('verifies DNS and associates a refusal with the hostname field', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', PendingDomain);
    await user.click(screen.getByRole('button', { name: 'Verify DNS ownership' }));
    expect(visit).toHaveBeenCalledWith(
        '/teams/7/status-pages/9/domain/verify',
        expect.objectContaining({ method: 'post' }),
    );
    act(() =>
        visit.mock.calls[0]?.[1]?.onError?.({ domain: 'The DNS proof could not be confirmed.' }),
    );
    expect(screen.getByText('The DNS proof could not be confirmed.')).toHaveAttribute(
        'role',
        'alert',
    );
    expect(screen.getByLabelText('Hostname')).toHaveAccessibleDescription(
        'The DNS proof could not be confirmed.',
    );
});

test('removes the hostname through its own CSRF-aware endpoint', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', PendingDomain);
    await user.click(screen.getByRole('button', { name: 'Remove hostname' }));
    expect(visit).toHaveBeenCalledWith(
        '/teams/7/status-pages/9/domain',
        expect.objectContaining({ method: 'delete' }),
    );
});
