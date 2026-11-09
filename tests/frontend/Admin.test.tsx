/** Exercise the platform admin pages through the real Inertia provider with only the router intercepted. */
import { router } from '@inertiajs/react';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, test, vi } from 'vitest';
import { overridePayload } from '../../resources/js/components/admin/overrideForm';
import type { AdminTeamDetail, AdminTeamSummary } from '../../resources/js/components/admin/types';
import Index from '../../resources/js/pages/Admin/Teams/Index';
import Show from '../../resources/js/pages/Admin/Teams/Show';
import { renderInertia } from './renderInertia';

/** Operator identity shared by the pages. */
const auth = { user: { name: 'Operator', email: 'operator@example.test', isPlatformAdmin: true } };

/** Synthetic Free team past its trial, without an override. */
const summary: AdminTeamSummary = {
    id: 9,
    name: 'QA team',
    slug: 'qa-team',
    owner: { name: 'Owner', email: 'owner@example.test' },
    storedPlan: 'free',
    entitlement: { plan: 'free', source: 'free', expiresAt: null },
    monitors: 5,
    override: null,
};

/** Detail of the same team. */
const detail: AdminTeamDetail = {
    ...summary,
    members: 2,
    trialStartedAt: '2026-09-01T00:00:00Z',
    trialEndsAt: '2026-09-15T00:00:00Z',
    createdAt: '2026-09-01T00:00:00Z',
    usage: { used: 5, limit: 5, minimumIntervalSeconds: 300 },
    events: [],
};

/**
 * @param data Rows of the page.
 * @param q Applied search text.
 * @returns Directory props.
 */
function directory(data: AdminTeamSummary[], q = '') {
    return {
        auth,
        filters: { q },
        teams: {
            data,
            currentPage: 1,
            lastPage: 1,
            perPage: 25,
            total: data.length,
            from: data.length === 0 ? null : 1,
            to: data.length === 0 ? null : data.length,
        },
    };
}

/**
 * @param team Team detail to show.
 * @returns Detail page props.
 */
function detailProps(team: AdminTeamDetail = detail) {
    return {
        auth,
        team,
        options: { kinds: ['unlimited', 'comped'], plans: ['pro', 'business'] },
        reasonMaxLength: 500,
    };
}

/** Ordinary cases model a still-recent password confirmation without any live request. */
beforeEach(() => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(JSON.stringify({ confirmed: true }), {
            headers: { 'Content-Type': 'application/json' },
        }),
    );
    document.cookie = 'XSRF-TOKEN=fixture-csrf; path=/';
});

test('lists teams with their effective plan and shows the admin link', () => {
    renderInertia(
        'Signalbox',
        Index,
        directory([
            {
                ...summary,
                entitlement: { plan: 'internal', source: 'unlimited_override', expiresAt: null },
            },
        ]),
    );

    const row = within(screen.getByRole('table'))
        .getByRole('link', { name: 'QA team' })
        .closest('tr');

    expect(row).toHaveTextContent('Internal (unlimited testing)');
    expect(row).toHaveTextContent('Unlimited testing override');
    expect(screen.getByRole('link', { name: 'Admin' })).toHaveAttribute('href', '/admin/teams');
});

test('distinguishes an empty platform from a search without matches', () => {
    const { unmount } = renderInertia('Signalbox', Index, directory([]));
    expect(screen.getByText('No teams yet.')).toBeInTheDocument();
    unmount();

    renderInertia('Signalbox', Index, directory([], 'nothing'));
    expect(screen.getByText(/No team matches this search/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Show all teams' })).toHaveAttribute(
        'href',
        '/admin/teams',
    );
});

test('submits the trimmed search text explicitly', async () => {
    const user = userEvent.setup();
    const get = vi.spyOn(router, 'get').mockImplementation(() => undefined);

    renderInertia('Signalbox', Index, directory([summary]));
    await user.type(screen.getByLabelText('Search'), '  owner@example.test ');
    await user.click(screen.getByRole('button', { name: 'Search' }));

    expect(get).toHaveBeenCalledWith(
        '/admin/teams',
        { q: 'owner@example.test' },
        expect.anything(),
    );
});

test('derives only the fields relevant to each override choice', () => {
    expect(overridePayload({ choice: 'unlimited', valid_through: '', reason: 'QA' })).toEqual({
        kind: 'unlimited',
        reason: 'QA',
    });
    expect(
        overridePayload({
            choice: 'comped-business',
            valid_through: '2026-12-31',
            reason: 'Pilot',
        }),
    ).toEqual({
        kind: 'comped',
        plan: 'business',
        valid_through: '2026-12-31',
        reason: 'Pilot',
    });
    expect(
        overridePayload({ choice: 'none', valid_through: '2026-12-31', reason: 'Done' }),
    ).toEqual({ reason: 'Done' });
});

test('applies an override only after confirmation', async () => {
    const user = userEvent.setup();
    const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
    const confirm = vi
        .spyOn(window, 'confirm')
        .mockReturnValueOnce(false)
        .mockReturnValueOnce(true);

    renderInertia('Signalbox', Show, detailProps());
    await user.click(screen.getByLabelText('Unlimited testing'));
    await user.type(screen.getByLabelText(/^Reason/), 'QA team');
    await user.click(screen.getByRole('button', { name: 'Save override' }));

    expect(put).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Save override' }));

    expect(confirm).toHaveBeenCalledWith('Apply this plan override to QA team?');
    expect(put).toHaveBeenCalledWith(
        '/admin/teams/9/plan-override',
        { kind: 'unlimited', reason: 'QA team' },
        expect.anything(),
    );
});

test('removes an existing override with a delete request and shows its history', async () => {
    const user = userEvent.setup();
    const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const withOverride: AdminTeamDetail = {
        ...detail,
        override: {
            kind: 'comped',
            plan: 'pro',
            expiresAt: '2026-12-01T00:00:00Z',
            reason: 'Pilot',
            updatedAt: null,
        },
        entitlement: { plan: 'pro', source: 'comped_override', expiresAt: '2026-12-01T00:00:00Z' },
        events: [
            {
                id: 1,
                action: 'team.plan_override.granted',
                actor: null,
                changes: {},
                reason: 'Pilot',
                createdAt: '2026-10-23T10:00:00Z',
            },
        ],
    };

    renderInertia('Signalbox', Show, detailProps(withOverride));

    expect(screen.getByLabelText('Granted Pro plan')).toBeChecked();
    // The inclusive form date is the day before the exclusive stored end.
    expect(screen.getByLabelText(/^Valid through/)).toHaveValue('2026-11-30');
    expect(screen.getByText(/by command line/)).toBeInTheDocument();

    await user.click(screen.getByLabelText(/^No override/));
    await user.type(screen.getByLabelText(/^Reason/), 'Pilot ended');
    await user.click(screen.getByRole('button', { name: 'Save override' }));

    expect(remove).toHaveBeenCalledWith('/admin/teams/9/plan-override', expect.anything());
});

/** A synthetic pre-existing testing override to remove after reconfirming an expired password. */
const unlimitedDetail: AdminTeamDetail = {
    ...detail,
    override: { kind: 'unlimited', plan: null, expiresAt: null, reason: 'QA', updatedAt: null },
    entitlement: { plan: 'internal', source: 'unlimited_override', expiresAt: null },
};

/** Build an explicit JSON response without leaking any credential into the fixture navigation props. */
function confirmationResponse(confirmed: boolean, status = 200): Response {
    return new Response(JSON.stringify({ confirmed }), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

test.each(['remove', 'grant'] as const)(
    'confirms an expired password inline and submits the %s once',
    async (operation) => {
        const user = userEvent.setup();
        const read = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(confirmationResponse(false))
            .mockResolvedValueOnce(confirmationResponse(true));
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
        const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        renderInertia('Signalbox', Show, detailProps(unlimitedDetail));
        await user.click(
            screen.getByLabelText(operation === 'remove' ? /^No override/ : 'Granted Pro plan'),
        );
        await user.type(screen.getByLabelText(/^Reason/), 'Requested change');
        await user.click(screen.getByRole('button', { name: 'Save override' }));

        const password = await screen.findByLabelText('Current password');
        expect(password).toHaveFocus();
        expect(remove).not.toHaveBeenCalled();
        expect(put).not.toHaveBeenCalled();
        expect(screen.getByLabelText(/^Reason/)).toHaveValue('Requested change');
        await user.type(password, 'fixture-password{Enter}');

        await waitFor(() => expect(operation === 'remove' ? remove : put).toHaveBeenCalledTimes(1));
        expect(screen.queryByLabelText('Current password')).not.toBeInTheDocument();
        expect(window.location.pathname).toBe('/');
        expect(read).toHaveBeenNthCalledWith(
            1,
            '/user/confirmed-password-status',
            expect.objectContaining({
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }),
        );
        expect(read).toHaveBeenNthCalledWith(
            2,
            '/user/confirm-password',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ password: 'fixture-password' }),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': 'fixture-csrf',
                },
            }),
        );
        if (operation === 'remove')
            expect(remove).toHaveBeenCalledWith(
                '/admin/teams/9/plan-override',
                expect.objectContaining({ data: { reason: 'Requested change' } }),
            );
        else
            expect(put).toHaveBeenCalledWith(
                '/admin/teams/9/plan-override',
                { kind: 'comped', plan: 'pro', reason: 'Requested change' },
                expect.anything(),
            );
    },
);

test('keeps the removal draft after a wrong password and submits only after correction', async () => {
    const user = userEvent.setup();
    vi.spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce(confirmationResponse(false))
        .mockResolvedValueOnce(confirmationResponse(false, 422))
        .mockResolvedValueOnce(confirmationResponse(true));
    const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderInertia('Signalbox', Show, detailProps(unlimitedDetail));
    await user.click(screen.getByLabelText(/^No override/));
    await user.type(screen.getByLabelText(/^Reason/), 'Keep this reason');
    await user.click(screen.getByRole('button', { name: 'Save override' }));
    await user.type(await screen.findByLabelText('Current password'), 'wrong{Enter}');
    expect(await screen.findByRole('alert')).toHaveTextContent('The password is incorrect');
    expect(screen.getByLabelText('Current password')).toHaveValue('');
    expect(screen.getByLabelText(/^Reason/)).toHaveValue('Keep this reason');
    expect(remove).not.toHaveBeenCalled();
    await user.type(screen.getByLabelText('Current password'), 'correct{Enter}');
    await waitFor(() => expect(remove).toHaveBeenCalledTimes(1));
});

test('cancels password confirmation without changing or losing the selected override draft', async () => {
    const user = userEvent.setup();
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(confirmationResponse(false));
    const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderInertia('Signalbox', Show, detailProps(unlimitedDetail));
    await user.click(screen.getByLabelText(/^No override/));
    await user.type(screen.getByLabelText(/^Reason/), 'Unchanged draft');
    await user.click(screen.getByRole('button', { name: 'Save override' }));
    await user.type(await screen.findByLabelText('Current password'), 'discard-me');
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByLabelText('Current password')).not.toBeInTheDocument();
    expect(screen.getByLabelText(/^No override/)).toBeChecked();
    expect(screen.getByLabelText(/^Reason/)).toHaveValue('Unchanged draft');
    await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Save override' })).toHaveFocus(),
    );
    expect(remove).not.toHaveBeenCalled();
});

test.each(['network', 'invalid', 'expired'] as const)(
    'does not mutate on a %s confirmation-status response',
    async (failure) => {
        const user = userEvent.setup();
        const read = vi.spyOn(globalThis, 'fetch');
        if (failure === 'network')
            read.mockRejectedValueOnce(new Error('private transport detail'));
        else
            read.mockResolvedValueOnce(
                new Response(failure === 'invalid' ? '{}' : '', {
                    status: failure === 'expired' ? 401 : 200,
                }),
            );
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        renderInertia('Signalbox', Show, detailProps(unlimitedDetail));
        await user.click(screen.getByLabelText(/^No override/));
        await user.type(screen.getByLabelText(/^Reason/), 'Preserved');
        await user.click(screen.getByRole('button', { name: 'Save override' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Unable to check password confirmation',
        );
        expect(remove).not.toHaveBeenCalled();
        expect(screen.getByLabelText(/^Reason/)).toHaveValue('Preserved');
    },
);

test('aborts an unfinished status check on unmount without submitting later', async () => {
    const user = userEvent.setup();
    let resolve: ((response: Response) => void) | undefined;
    const read = vi.spyOn(globalThis, 'fetch').mockImplementation(
        () =>
            new Promise<Response>((done) => {
                resolve = done;
            }),
    );
    const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const view = renderInertia('Signalbox', Show, detailProps(unlimitedDetail));
    await user.click(screen.getByLabelText(/^No override/));
    await user.type(screen.getByLabelText(/^Reason/), 'Preserved');
    await user.click(screen.getByRole('button', { name: 'Save override' }));
    const options = read.mock.calls[0]?.[1];
    view.unmount();
    expect(options?.signal?.aborted).toBe(true);
    await act(async () => {
        resolve?.(confirmationResponse(true));
        await Promise.resolve();
    });
    expect(remove).not.toHaveBeenCalled();
});

test.each(['network', 'expired', 'malformed'] as const)(
    'keeps the draft and avoids mutation after a %s password confirmation failure',
    async (failure) => {
        const user = userEvent.setup();
        const read = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(confirmationResponse(false));
        if (failure === 'network') read.mockRejectedValueOnce(new Error('private response detail'));
        else
            read.mockResolvedValueOnce(
                new Response('{}', { status: failure === 'expired' ? 419 : 200 }),
            );
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        renderInertia('Signalbox', Show, detailProps(unlimitedDetail));
        await user.click(screen.getByLabelText(/^No override/));
        await user.type(screen.getByLabelText(/^Reason/), 'Preserved draft');
        await user.click(screen.getByRole('button', { name: 'Save override' }));
        await user.type(
            await screen.findByLabelText('Current password'),
            'private-password{Enter}',
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Unable to confirm your password',
        );
        expect(screen.getByLabelText('Current password')).toHaveValue('');
        expect(screen.getByLabelText(/^Reason/)).toHaveValue('Preserved draft');
        expect(remove).not.toHaveBeenCalled();
        expect(screen.queryByText('private response detail')).not.toBeInTheDocument();
    },
);

test('cancels a pending password request without replaying its operation after a late acknowledgement', async () => {
    const user = userEvent.setup();
    let resolve: ((response: Response) => void) | undefined;
    const read = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce(confirmationResponse(false))
        .mockImplementationOnce(
            () =>
                new Promise<Response>((done) => {
                    resolve = done;
                }),
        );
    const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderInertia('Signalbox', Show, detailProps(unlimitedDetail));
    await user.click(screen.getByLabelText(/^No override/));
    await user.type(screen.getByLabelText(/^Reason/), 'No replay');
    await user.click(screen.getByRole('button', { name: 'Save override' }));
    await user.type(await screen.findByLabelText('Current password'), 'private-password{Enter}');
    expect(screen.getByRole('button', { name: 'Confirming…' })).toBeDisabled();
    const options = read.mock.calls[1]?.[1];
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(options?.signal?.aborted).toBe(true);
    await act(async () => {
        resolve?.(confirmationResponse(true));
        await Promise.resolve();
    });
    expect(remove).not.toHaveBeenCalled();
});
