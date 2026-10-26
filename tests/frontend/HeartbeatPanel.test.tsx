/** Verify ephemeral heartbeat credentials, permissions, CSRF mutations and accessible failure handling. */
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import HeartbeatPanel from '../../resources/js/components/monitors/HeartbeatPanel';
import type { HeartbeatView } from '../../resources/js/components/monitors/types';

/** Non-secret server metadata for one synthetic monitor. */
const heartbeat: HeartbeatView = {
    configured: false,
    endpoint: 'http://localhost/heartbeats',
    startedAt: '2026-10-26T12:00:00Z',
    lastReceivedAt: null,
    dueAt: '2026-10-26T12:05:00Z',
};

/** Synthetic credential generated only by a response double. */
const token = 'a'.repeat(64);

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=synthetic%3Dcsrf; path=/';
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});

afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; max-age=0; path=/';
    vi.restoreAllMocks();
});

describe('heartbeat credential management', () => {
    it('generates a one-time command with CSRF and forgets it after hiding or remounting', async () => {
        const user = userEvent.setup();
        const fetch = vi.mocked(globalThis.fetch).mockResolvedValue(
            new Response(JSON.stringify({ token, endpoint: heartbeat.endpoint }), {
                status: 200,
            }),
        );
        const { unmount } = render(
            <HeartbeatPanel path="/teams/1/monitors/2" heartbeat={heartbeat} canManage />,
        );
        await user.click(screen.getByRole('button', { name: 'Generate credential' }));
        await screen.findByText(/curl --fail/);
        expect(fetch).toHaveBeenCalledWith(
            '/teams/1/monitors/2/heartbeat-credential',
            expect.objectContaining({
                method: 'POST',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': 'synthetic=csrf' },
            }),
        );
        expect(screen.getByText(/curl --fail/).textContent).toContain(
            `Authorization: Bearer ${token}`,
        );
        await user.click(screen.getByRole('button', { name: 'Hide credential' }));
        expect(screen.queryByText(/curl --fail/)).not.toBeInTheDocument();
        expect(localStorage.length).toBe(0);
        expect(sessionStorage.length).toBe(0);
        expect(window.location.href).not.toContain(token);
        unmount();
        render(
            <HeartbeatPanel
                path="/teams/1/monitors/2"
                heartbeat={{ ...heartbeat, configured: true }}
                canManage
            />,
        );
        expect(screen.queryByText(/curl --fail/)).not.toBeInTheDocument();
    });

    it('shows metadata but no credential controls to viewers', () => {
        render(
            <HeartbeatPanel
                path="/teams/1/monitors/2"
                heartbeat={{ ...heartbeat, configured: true }}
                canManage={false}
            />,
        );
        expect(screen.getByText('Credential: Configured')).toBeInTheDocument();
        expect(screen.getByText('Last heartbeat: Never')).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('confirms rotation and revocation and supports cancelling destructive changes', async () => {
        const user = userEvent.setup();
        const fetch = vi
            .mocked(globalThis.fetch)
            .mockResolvedValue(new Response(null, { status: 204 }));
        vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        render(
            <HeartbeatPanel
                path="/teams/1/monitors/2"
                heartbeat={{ ...heartbeat, configured: true }}
                canManage
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Rotate credential' }));
        expect(fetch).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Revoke credential' }));
        await screen.findByText('Credential revoked.');
        expect(fetch).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ method: 'DELETE' }),
        );
        expect(screen.getByRole('button', { name: 'Generate credential' })).toBeInTheDocument();
    });

    it('copies the command and clears secrets when permissions or monitor identity change', async () => {
        const user = userEvent.setup();
        const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue();
        vi.mocked(globalThis.fetch).mockResolvedValue(
            new Response(JSON.stringify({ token, endpoint: heartbeat.endpoint }), { status: 200 }),
        );
        const { rerender } = render(
            <HeartbeatPanel path="/teams/1/monitors/2" heartbeat={heartbeat} canManage />,
        );
        await user.click(screen.getByRole('button', { name: 'Generate credential' }));
        await user.click(await screen.findByRole('button', { name: 'Copy heartbeat command' }));
        expect(writeText).toHaveBeenCalledWith(expect.stringContaining(token));
        rerender(
            <HeartbeatPanel path="/teams/1/monitors/2" heartbeat={heartbeat} canManage={false} />,
        );
        rerender(<HeartbeatPanel path="/teams/1/monitors/2" heartbeat={heartbeat} canManage />);
        expect(screen.queryByText(/curl --fail/)).not.toBeInTheDocument();
        rerender(<HeartbeatPanel path="/teams/1/monitors/3" heartbeat={heartbeat} canManage />);
        expect(screen.queryByText(/curl --fail/)).not.toBeInTheDocument();
    });

    it('prevents duplicate submissions and aborts pending issuance on unmount', async () => {
        const user = userEvent.setup();
        vi.mocked(globalThis.fetch).mockReturnValue(new Promise(() => {}));
        const { unmount } = render(
            <HeartbeatPanel path="/teams/1/monitors/2" heartbeat={heartbeat} canManage />,
        );
        await user.click(screen.getByRole('button', { name: 'Generate credential' }));
        expect(screen.getByRole('button', { name: 'Generate credential' })).toBeDisabled();
        const signal = vi.mocked(globalThis.fetch).mock.calls[0]?.[1]?.signal;
        unmount();
        expect(signal?.aborted).toBe(true);
    });

    it('reports failures without rendering server-provided secrets or leaving loading state active', async () => {
        const user = userEvent.setup();
        vi.mocked(globalThis.fetch).mockResolvedValue(new Response(token, { status: 403 }));
        render(<HeartbeatPanel path="/teams/1/monitors/2" heartbeat={heartbeat} canManage />);
        await user.click(screen.getByRole('button', { name: 'Generate credential' }));
        expect(await screen.findByRole('alert')).not.toHaveTextContent(token);
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Generate credential' })).toBeEnabled(),
        );
    });

    it('rejects malformed issuance responses and reports clipboard failures', async () => {
        const user = userEvent.setup();
        const fetch = vi
            .mocked(globalThis.fetch)
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ token: 'invalid', endpoint: heartbeat.endpoint }), {
                    status: 200,
                }),
            )
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ token, endpoint: heartbeat.endpoint }), {
                    status: 200,
                }),
            );
        render(<HeartbeatPanel path="/teams/1/monitors/2" heartbeat={heartbeat} canManage />);
        await user.click(screen.getByRole('button', { name: 'Generate credential' }));
        await screen.findByRole('alert');
        expect(screen.queryByText(/curl --fail/)).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Generate credential' }));
        await screen.findByRole('button', { name: 'Copy heartbeat command' });
        vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(
            new Error('Synthetic clipboard refusal.'),
        );
        await user.click(screen.getByRole('button', { name: 'Copy heartbeat command' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Select and copy the command manually.',
        );
        expect(fetch).toHaveBeenCalledTimes(2);
    });
});
