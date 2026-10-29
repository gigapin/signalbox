/** Exercise the platform admin pages through the real Inertia provider with only the router intercepted. */
import { router } from '@inertiajs/react';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
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
