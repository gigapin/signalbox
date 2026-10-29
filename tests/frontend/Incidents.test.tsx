/** Exercise incident pages and the maintenance panel through actual Inertia components with only the router intercepted. */
import { router } from '@inertiajs/react';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, test, vi } from 'vitest';
import type {
    IncidentDetail,
    IncidentSummary,
    MaintenanceView,
} from '../../resources/js/components/incidents/types';
import type { MonitorDetail } from '../../resources/js/components/monitors/types';
import Index from '../../resources/js/pages/Incidents/Index';
import Show from '../../resources/js/pages/Incidents/Show';
import MonitorShow from '../../resources/js/pages/Monitors/Show';
import { renderInertia } from './renderInertia';

/** Route team of every synthetic page. */
const team = { id: 7, name: 'Synthetic team' };

/** Enum values as projected by IncidentPresenter::options(). */
const options = { states: ['open', 'resolved'], severities: ['minor', 'major', 'critical'] };

/** Capture the options of the next spied visit so tests can run its lifecycle callbacks. */
type VisitOptions = { onStart?: (visit: never) => void };

/**
 * @param elements Elements found by a query, in document order.
 * @param index Zero-based position.
 * @returns The element at that position.
 * @throws Error When the query found fewer elements.
 */
function nth(elements: HTMLElement[], index: number): HTMLElement {
    const element = elements[index];

    if (element === undefined) {
        throw new Error(`Expected at least ${index + 1} elements.`);
    }

    return element;
}

afterEach(() => {
    vi.restoreAllMocks();
});

/**
 * @param id Row identity.
 * @param status Incident status.
 * @returns Synthetic open or resolved incident row.
 */
function row(id: number, status = 'investigating'): IncidentSummary {
    const resolved = status === 'resolved';

    return {
        id,
        monitor: { id: 11, name: 'Checkout API' },
        status,
        severity: 'critical',
        cause: 'connection_failed',
        startedAt: '2026-10-28T10:00:00+00:00',
        confirmedAt: '2026-10-28T10:03:00+00:00',
        resolvedAt: resolved ? '2026-10-28T10:13:00+00:00' : null,
        durationSeconds: resolved ? 780 : null,
        acknowledged: false,
        reopenedCount: 0,
    };
}

/**
 * @param status Incident status.
 * @returns Synthetic detail with one private system entry and one published entry.
 */
function detail(status = 'investigating'): IncidentDetail {
    return {
        ...row(42, status),
        acknowledgedAt: null,
        responsible: null,
        rootCauseNote: null,
        lastReopenedAt: null,
        timeline: [
            {
                id: 100,
                status: 'investigating',
                message: 'Failure confirmed automatically.',
                author: null,
                isPublic: false,
                publishedAt: null,
                createdAt: '2026-10-28T10:03:00+00:00',
            },
            {
                id: 101,
                status: 'investigating',
                message: 'We are looking into it.',
                author: 'Ada Example',
                isPublic: true,
                publishedAt: '2026-10-28T10:05:00+00:00',
                createdAt: '2026-10-28T10:04:00+00:00',
            },
        ],
    };
}

/**
 * @param incident Displayed incident.
 * @param canUpdate Whether the actor manages incidents.
 * @param extra Overrides of the page props.
 * @returns Complete detail page props.
 */
function showProps(
    incident: IncidentDetail,
    canUpdate: boolean,
    extra: Record<string, unknown> = {},
) {
    return {
        team,
        incident,
        postableStatuses:
            incident.status === 'resolved'
                ? ['resolved']
                : ['investigating', 'identified', 'monitoring', 'resolved'],
        options,
        textMaxLength: 5000,
        deliveries: [],
        can: { update: canUpdate },
        ...extra,
    };
}

/**
 * @param rows Rows of the page.
 * @param extra Overrides of the list props.
 * @returns Complete list page props.
 */
function indexProps(rows: IncidentSummary[], extra: Record<string, unknown> = {}) {
    return {
        team,
        incidents: {
            data: rows,
            currentPage: 1,
            lastPage: 1,
            perPage: 20,
            total: rows.length,
            from: rows.length > 0 ? 1 : null,
            to: rows.length > 0 ? rows.length : null,
        },
        filters: { state: null, severity: null },
        filtered: false,
        rejectedFilters: [],
        options,
        ...extra,
    };
}

/** Synthetic monitor detail without secrets. */
const monitor: MonitorDetail = {
    id: 11,
    name: 'Checkout API',
    type: 'http',
    lastStatus: 'down',
    state: 'active',
    intervalSeconds: 300,
    regions: ['eu'],
    lastCheckedAt: null,
    timeoutMs: 5000,
    confirmationThreshold: 3,
    nextCheckAt: null,
    createdAt: '2026-10-23T10:00:00+00:00',
    withinQuota: true,
    configuration: {
        type: 'http',
        url: 'https://example.invalid/health',
        method: 'GET',
        header_names: [],
        body: { present: false, bytes: 0 },
        expected_status: 200,
        follow_redirects: false,
    },
};

/** One upcoming, one ongoing and one finished window. */
const maintenance: MaintenanceView = {
    current: [
        {
            id: 1,
            startsAt: '2026-10-28T11:00:00+00:00',
            endsAt: '2026-10-28T13:00:00+00:00',
            reason: 'Database failover',
            phase: 'ongoing',
        },
        {
            id: 2,
            startsAt: '2026-10-29T08:00:00+00:00',
            endsAt: '2026-10-29T09:00:00+00:00',
            reason: 'Network change',
            phase: 'upcoming',
        },
    ],
    finished: [
        {
            id: 3,
            startsAt: '2026-10-27T08:00:00+00:00',
            endsAt: '2026-10-27T09:00:00+00:00',
            reason: 'Kernel upgrade',
            phase: 'finished',
        },
    ],
    reasonMaxLength: 1000,
};

/**
 * @param canUpdate Whether the actor manages the monitor.
 * @param view Maintenance section.
 * @param recentIncidents Latest incidents.
 * @returns Complete monitor detail page props.
 */
function monitorProps(canUpdate: boolean, view = maintenance, recentIncidents = [row(42)]) {
    return {
        team,
        monitor,
        recentIncidents,
        maintenance: view,
        escalation: { policyId: null, effective: null, options: [] },
        can: { update: canUpdate, delete: canUpdate, pause: canUpdate, resume: canUpdate },
    };
}

test('distinguishes an empty team from no matches and announces rejected filters', () => {
    const view = renderInertia('Signalbox', Index, indexProps([]));
    expect(screen.getByText(/No incidents yet/)).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    view.unmount();

    renderInertia(
        'Signalbox',
        Index,
        indexProps([], {
            filters: { state: 'open', severity: null },
            filtered: true,
            rejectedFilters: ['severity'],
        }),
    );
    expect(screen.getByText('No incidents match these filters on this page.')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('invalid and have been ignored: severity');
    expect(screen.getByRole('button', { name: 'Clear filters' })).toBeInTheDocument();
});

test('lists incidents as text badges with links and submits filters with a loading state', async () => {
    const user = userEvent.setup();
    const get = vi.spyOn(router, 'get').mockImplementation((_url, _data, visit) => {
        (visit as VisitOptions | undefined)?.onStart?.({} as never);
    });

    renderInertia('Signalbox', Index, indexProps([row(42), row(41, 'resolved')]));
    const table = screen.getByRole('table', { name: 'Incidents of Synthetic team' });
    expect(within(table).getByRole('link', { name: 'Incident #42' })).toHaveAttribute(
        'href',
        '/teams/7/incidents/42',
    );
    expect(within(table).getAllByRole('link', { name: 'Checkout API' })[0]).toHaveAttribute(
        'href',
        '/teams/7/monitors/11',
    );
    expect(within(table).getByText('Investigating')).toBeInTheDocument();
    expect(within(table).getByText('13 min')).toBeInTheDocument();
    expect(within(table).getAllByText('Connection failed')).toHaveLength(2);

    await user.selectOptions(screen.getByLabelText('State'), 'open');
    await user.selectOptions(screen.getByLabelText('Severity'), 'critical');
    expect(get).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));

    expect(get).toHaveBeenCalledWith(
        '/teams/7/incidents',
        { state: 'open', severity: 'critical' },
        expect.any(Object),
    );
    expect(screen.getByRole('button', { name: 'Loading…' })).toBeDisabled();
    expect(screen.getByRole('region', { name: 'Incident results' })).toHaveAttribute(
        'aria-busy',
        'true',
    );
});

test('shows a read-only timeline to viewers without management controls', () => {
    renderInertia('Signalbox', Show, showProps(detail(), false));

    const timeline = screen.getByRole('list', { name: 'Timeline entries, oldest first' });
    const entries = within(timeline).getAllByRole('listitem');
    expect(entries).toHaveLength(2);
    expect(entries[0]).toHaveTextContent('System or deleted account');
    expect(entries[0]).toHaveTextContent('Private');
    expect(entries[1]).toHaveTextContent('Ada Example');
    expect(entries[1]).toHaveTextContent('Published');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Message')).not.toBeInTheDocument();
    expect(screen.getByText('Your role has read-only access to incidents.')).toBeInTheDocument();
});

test('posts a private update with the chosen status and takes responsibility', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);

    renderInertia('Signalbox', Show, showProps(detail(), true));
    await user.selectOptions(screen.getByLabelText('Status after this update'), 'identified');
    await user.type(screen.getByLabelText('Message'), 'Provider outage identified.');
    expect(screen.getByText(/Updates are private until you publish them/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Add update' }));

    expect(post).toHaveBeenCalledWith(
        '/teams/7/incidents/42/updates',
        { status: 'identified', message: 'Provider outage identified.' },
        expect.any(Object),
    );

    await user.click(screen.getByRole('button', { name: 'Take responsibility' }));
    expect(post).toHaveBeenLastCalledWith(
        '/teams/7/incidents/42/acknowledge',
        {},
        expect.any(Object),
    );
});

test('publishes a private entry only after confirmation and never offers it twice', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    const confirm = vi
        .spyOn(window, 'confirm')
        .mockReturnValueOnce(false)
        .mockReturnValueOnce(true);

    renderInertia('Signalbox', Show, showProps(detail(), true));
    const publish = screen.getByRole('button', { name: 'Publish update' });

    await user.click(publish);
    expect(post).not.toHaveBeenCalled();
    await user.click(publish);

    expect(confirm).toHaveBeenCalledTimes(2);
    expect(confirm).toHaveBeenNthCalledWith(1, expect.stringContaining('cannot be withdrawn'));
    expect(post).toHaveBeenCalledWith(
        '/teams/7/incidents/42/updates/100/publish',
        {},
        expect.any(Object),
    );
});

test('offers only notes on a resolved incident and announces lifecycle refusals', () => {
    renderInertia(
        'Signalbox',
        Show,
        showProps(detail('resolved'), true, {
            errors: { incident: 'Only a newly confirmed failure can reopen a resolved incident.' },
        }),
    );

    const status = screen.getByLabelText('Status after this update');
    expect(
        within(status)
            .getAllByRole('option')
            .map((option) => option.textContent),
    ).toEqual(['Resolved']);
    expect(screen.getByText(/accepts notes only/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Take responsibility' })).not.toBeInTheDocument();
    expect(screen.getAllByRole('alert')[0]).toHaveTextContent('Only a newly confirmed failure');
});

test('saves severity and clears the declared cause with an empty note', async () => {
    const user = userEvent.setup();
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);

    renderInertia(
        'Signalbox',
        Show,
        showProps({ ...detail(), rootCauseNote: 'Expired credentials.' }, true),
    );
    await user.selectOptions(screen.getByLabelText('Severity'), 'minor');
    await user.click(screen.getByRole('button', { name: 'Save severity' }));
    expect(patch).toHaveBeenCalledWith(
        '/teams/7/incidents/42/severity',
        { severity: 'minor' },
        expect.any(Object),
    );

    await user.clear(screen.getByLabelText('Declared cause'));
    await user.click(screen.getByRole('button', { name: 'Save cause' }));
    expect(patch).toHaveBeenLastCalledWith(
        '/teams/7/incidents/42/root-cause',
        { root_cause_note: '' },
        expect.any(Object),
    );
});

test('lists recent incidents and maintenance phases on the monitor page', () => {
    renderInertia('Signalbox', MonitorShow, monitorProps(false));

    const incidents = screen.getByRole('table', { name: 'Recent incidents of Checkout API' });
    expect(within(incidents).getByRole('link', { name: 'Incident #42' })).toBeInTheDocument();
    expect(within(incidents).queryByRole('columnheader', { name: 'Monitor' })).toBeNull();

    const current = screen.getByRole('list', { name: 'Current and upcoming maintenance' });
    expect(within(current).getAllByRole('listitem')[0]).toHaveTextContent('In progress');
    expect(within(current).getAllByRole('listitem')[1]).toHaveTextContent('Upcoming');
    const finished = screen.getByRole('list', { name: 'Finished maintenance' });
    expect(finished).toHaveTextContent('Kernel upgrade');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Reason')).not.toBeInTheDocument();
});

test('shows empty incident and maintenance states', () => {
    renderInertia(
        'Signalbox',
        MonitorShow,
        monitorProps(false, { current: [], finished: [], reasonMaxLength: 1000 }, []),
    );

    expect(screen.getByText('No incidents recorded for this monitor.')).toBeInTheDocument();
    expect(screen.getByText('No maintenance in progress or scheduled.')).toBeInTheDocument();
});

test('schedules maintenance by sending browser-local input as UTC instants', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);

    renderInertia('Signalbox', MonitorShow, monitorProps(true));
    const form = screen.getByRole('heading', { name: 'Schedule maintenance' }).parentElement!;
    await user.type(within(form).getByLabelText('End (your local time)'), '2026-10-29T10:30');
    await user.type(within(form).getByLabelText('Reason'), 'Database upgrade');
    await user.click(within(form).getByRole('button', { name: 'Schedule maintenance' }));

    expect(post).toHaveBeenCalledWith(
        '/teams/7/monitors/11/maintenance',
        {
            starts_at: null,
            ends_at: new Date('2026-10-29T10:30').toISOString(),
            reason: 'Database upgrade',
        },
        expect.any(Object),
    );
});

test('edits or deletes upcoming windows and ends ongoing ones after confirmation', async () => {
    const user = userEvent.setup();
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);

    renderInertia('Signalbox', MonitorShow, monitorProps(true));
    const items = within(
        screen.getByRole('list', { name: 'Current and upcoming maintenance' }),
    ).getAllByRole('listitem');
    const ongoing = nth(items, 0);
    const upcoming = nth(items, 1);

    expect(within(ongoing).queryByRole('button', { name: 'Edit window' })).toBeNull();
    await user.click(within(ongoing).getByRole('button', { name: 'End now' }));
    expect(confirm).toHaveBeenNthCalledWith(
        1,
        expect.stringContaining('Incidents can open again immediately'),
    );
    expect(remove).toHaveBeenCalledWith('/teams/7/monitors/11/maintenance/1', expect.any(Object));

    const edit = within(upcoming).getByRole('button', { name: 'Edit window' });
    await user.click(edit);
    expect(edit).toHaveAttribute('aria-expanded', 'true');
    const reason = within(upcoming).getByLabelText('Reason');
    expect(reason).toHaveValue('Network change');
    await user.clear(reason);
    await user.type(reason, 'Moved network change');
    await user.click(within(upcoming).getByRole('button', { name: 'Save window' }));

    expect(patch).toHaveBeenCalledWith(
        '/teams/7/monitors/11/maintenance/2',
        {
            starts_at: new Date('2026-10-29T08:00:00+00:00').toISOString(),
            ends_at: new Date('2026-10-29T09:00:00+00:00').toISOString(),
            reason: 'Moved network change',
        },
        expect.any(Object),
    );

    await user.click(within(upcoming).getByRole('button', { name: 'Delete window' }));
    expect(remove).toHaveBeenLastCalledWith(
        '/teams/7/monitors/11/maintenance/2',
        expect.any(Object),
    );
});

test('announces maintenance refusals and field errors from the server', () => {
    renderInertia('Signalbox', MonitorShow, {
        ...monitorProps(true),
        errors: { maintenance: 'A maintenance window cannot start in the past.' },
    });

    expect(screen.getByRole('alert')).toHaveTextContent(
        'A maintenance window cannot start in the past.',
    );
});
