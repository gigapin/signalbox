/** Exercise monitor list, form and detail pages through actual Inertia components with only the router intercepted. */
import { router } from '@inertiajs/react';
import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import type {
    MonitorDefaults,
    MonitorDetail,
    MonitorOptions,
    MonitorSummary,
} from '../../resources/js/components/monitors/types';
import Create from '../../resources/js/pages/Monitors/Create';
import Edit from '../../resources/js/pages/Monitors/Edit';
import Index from '../../resources/js/pages/Monitors/Index';
import Show from '../../resources/js/pages/Monitors/Show';
import { renderInertia } from './renderInertia';

/** Route team of every synthetic page. */
const team = { id: 7, name: 'Synthetic team' };

/** Enum values as projected by MonitorPresenter::options(). */
const options: MonitorOptions = {
    types: ['http', 'tls', 'dns', 'heartbeat', 'keyword'],
    statuses: ['unknown', 'up', 'down'],
    states: ['active', 'paused', 'suspended'],
    regions: ['eu', 'us'],
    methods: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    keywordModes: ['present', 'absent'],
    dnsRecordTypes: ['A', 'AAAA', 'CNAME', 'MX', 'TXT'],
};

/** Server defaults as projected by MonitorPresenter::defaults(). */
const defaults: MonitorDefaults = {
    name: '',
    type: 'http',
    interval_seconds: 300,
    timeout_ms: 5000,
    confirmation_threshold: 3,
    regions: ['eu'],
    method: 'GET',
    expected_status: 200,
    follow_redirects: false,
    keyword_mode: 'present',
    tls_port: 443,
    dns_record_type: 'A',
    heartbeat_grace_seconds: 0,
};

/** Quota with room left on a Free plan. */
const usage = { used: 2, limit: 5, minimumIntervalSeconds: 300, full: false };

/** Monitor detail sections without incidents or maintenance, as for a new monitor. */
const quietHistory = {
    recentIncidents: [],
    maintenance: { current: [], finished: [], reasonMaxLength: 1000 },
};

/** No active filters. */
const noFilters = { q: '', type: null, status: null, state: null };

/**
 * @param id Row identity.
 * @param name Row label.
 * @returns Synthetic list row without configuration values.
 */
function row(id: number, name: string): MonitorSummary {
    return {
        id,
        name,
        type: 'http',
        lastStatus: 'up',
        state: 'active',
        intervalSeconds: 300,
        regions: ['eu'],
        lastCheckedAt: null,
    };
}

/** Synthetic HTTP detail with a redacted URL, header names and body size only. */
const httpDetail: MonitorDetail = {
    ...row(11, 'Checkout API'),
    timeoutMs: 5000,
    confirmationThreshold: 3,
    nextCheckAt: null,
    createdAt: '2026-10-23T10:00:00+00:00',
    withinQuota: true,
    configuration: {
        type: 'http',
        url: 'https://example.invalid/health?key=[REDACTED]',
        method: 'GET',
        header_names: ['authorization'],
        body: { present: false, bytes: 0 },
        expected_status: 200,
        follow_redirects: false,
    },
};

/**
 * @param monitors Rows of the page.
 * @param extra Overrides of the list props.
 * @returns Complete list page props.
 */
function indexProps(monitors: MonitorSummary[], extra: Record<string, unknown> = {}) {
    return {
        team,
        monitors: {
            data: monitors,
            currentPage: 1,
            lastPage: 1,
            perPage: 20,
            total: monitors.length,
            from: monitors.length > 0 ? 1 : null,
            to: monitors.length > 0 ? monitors.length : null,
        },
        filters: noFilters,
        filtered: false,
        rejectedFilters: [],
        options,
        usage,
        can: { create: true },
        ...extra,
    };
}

/** Capture the options of the next spied visit so tests can run its lifecycle callbacks. */
type VisitOptions = {
    onStart?: (visit: never) => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: (visit: never) => void;
};

/** An empty team gets a truthful empty state and a creation link. */
function showsEmptyTeam(): void {
    renderInertia('Signalbox', Index, indexProps([]));

    expect(screen.getByText(/No monitors yet/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'New monitor' })).toHaveAttribute(
        'href',
        '/teams/7/monitors/create',
    );
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Pagination' })).not.toBeInTheDocument();
}

/** Filtered empty results differ from an empty team, and rejected filters are announced. */
function showsNoMatchesAndRejectedFilters(): void {
    renderInertia(
        'Signalbox',
        Index,
        indexProps([], {
            filters: { ...noFilters, q: 'missing' },
            filtered: true,
            rejectedFilters: ['type'],
        }),
    );

    expect(screen.getByText('No monitors match these filters on this page.')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('invalid and have been ignored: type');
    expect(screen.getByRole('button', { name: 'Clear filters' })).toBeInTheDocument();
    expect(screen.queryByText(/No monitors yet/)).not.toBeInTheDocument();
}

/** Filters are submitted explicitly as a GET query without empty keys, with a loading state. */
async function submitsFilters(): Promise<void> {
    const user = userEvent.setup();
    const get = vi.spyOn(router, 'get').mockImplementation((_url, _data, visit) => {
        (visit as VisitOptions | undefined)?.onStart?.({} as never);
    });

    renderInertia('Signalbox', Index, indexProps([row(1, 'Alpha')]));
    await user.type(screen.getByLabelText('Search'), ' alpha ');
    await user.selectOptions(screen.getByLabelText('State'), 'paused');
    expect(get).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));

    expect(get).toHaveBeenCalledWith(
        '/teams/7/monitors',
        { q: 'alpha', state: 'paused' },
        expect.any(Object),
    );
    expect(screen.getByRole('button', { name: 'Searching…' })).toBeDisabled();
    expect(screen.getByRole('region', { name: 'Monitor results' })).toHaveAttribute(
        'aria-busy',
        'true',
    );
}

/** Page links keep the active filters, mark the current page and are reachable by keyboard. */
async function paginatesWithFilters(): Promise<void> {
    const user = userEvent.setup();
    const props = indexProps([row(1, 'Alpha')], {
        filters: { ...noFilters, type: 'http' },
        filtered: true,
    });
    props.monitors = {
        ...props.monitors,
        currentPage: 2,
        lastPage: 3,
        total: 41,
        from: 21,
        to: 21,
    };

    renderInertia('Signalbox', Index, props);
    const pagination = screen.getByRole('navigation', { name: 'Pagination' });

    expect(within(pagination).getByRole('link', { name: 'Page 2' })).toHaveAttribute(
        'aria-current',
        'page',
    );
    expect(within(pagination).getByRole('link', { name: 'Page 1' })).toHaveAttribute(
        'href',
        '/teams/7/monitors?type=http',
    );
    expect(within(pagination).getByRole('link', { name: 'Next' })).toHaveAttribute(
        'href',
        '/teams/7/monitors?type=http&page=3',
    );

    within(pagination).getByRole('link', { name: 'Previous' }).focus();
    await user.tab();
    expect(within(pagination).getByRole('link', { name: 'Page 1' })).toHaveFocus();
    expect(screen.getByRole('rowheader', { name: 'Alpha' })).toBeInTheDocument();
}

/** Viewers and full quotas get an explanation instead of a creation link. */
function hidesCreationWhenNotAllowed(): void {
    const view = renderInertia(
        'Signalbox',
        Index,
        indexProps([row(1, 'Alpha')], { can: { create: false } }),
    );
    expect(screen.getByText('Your role has read-only access to monitors.')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'New monitor' })).not.toBeInTheDocument();
    view.unmount();

    renderInertia(
        'Signalbox',
        Index,
        indexProps([row(1, 'Alpha')], { usage: { ...usage, used: 5, full: true } }),
    );
    expect(screen.getByText(/Your plan allows 5 monitors/)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'New monitor' })).not.toBeInTheDocument();
}

/** Switching type shows only that type's section and submits only its fields. */
async function createsDnsMonitorWithRelevantFields(): Promise<void> {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);

    renderInertia('Signalbox', Create, { team, defaults, options, usage });
    expect(screen.getByRole('group', { name: 'HTTP request' })).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText('Type'), 'dns');
    expect(screen.queryByRole('group', { name: 'HTTP request' })).not.toBeInTheDocument();
    await user.type(screen.getByLabelText('Name'), 'Mail');
    await user.type(screen.getByLabelText('Query name'), 'mail.example.test');
    await user.type(screen.getByLabelText('Expected value 1'), '192.0.2.1');
    await user.click(screen.getByRole('button', { name: 'Add value' }));
    await user.type(screen.getByLabelText('Expected value 2'), '192.0.2.2');
    await user.click(screen.getByLabelText('United States (simulated)'));
    await user.click(screen.getByRole('button', { name: 'Create monitor' }));

    expect(post).toHaveBeenCalledWith(
        '/teams/7/monitors',
        {
            name: 'Mail',
            type: 'dns',
            interval_seconds: '300',
            timeout_ms: '5000',
            confirmation_threshold: '3',
            regions: ['eu', 'us'],
            dns_name: 'mail.example.test',
            dns_record_type: 'A',
            dns_expected_values: ['192.0.2.1', '192.0.2.2'],
            dns_resolver: null,
        },
        expect.any(Object),
    );
}

/** Edits keep stored secrets by omitting them, and send a URL only after an explicit replacement. */
async function editsWithoutResendingSecrets(): Promise<void> {
    const user = userEvent.setup();
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);

    renderInertia('Signalbox', Edit, { team, monitor: httpDetail, defaults, options, usage });
    expect(screen.getByText(/Stored URL:/)).toHaveTextContent('key=[REDACTED]');
    expect(screen.getByText(/Stored headers:/)).toHaveTextContent('authorization (values hidden)');
    expect(screen.queryByLabelText('URL')).not.toBeInTheDocument();

    await user.clear(screen.getByLabelText('Name'));
    await user.type(screen.getByLabelText('Name'), 'Checkout v2');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    const first = patch.mock.calls[0]?.[1] as Record<string, unknown>;
    expect(patch.mock.calls[0]?.[0]).toBe('/teams/7/monitors/11');
    expect(first.name).toBe('Checkout v2');
    expect(first).not.toHaveProperty('url');
    expect(first).not.toHaveProperty('headers');
    expect(first).not.toHaveProperty('body');

    await user.click(screen.getByLabelText('Replace URL'));
    await user.type(screen.getByLabelText('URL'), 'https://example.invalid/v2');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    const second = patch.mock.calls[1]?.[1] as Record<string, unknown>;
    expect(second.url).toBe('https://example.invalid/v2');
    expect(second).not.toHaveProperty('headers');
}

/** Changing an edited monitor's type requires the full new configuration, including secrets. */
async function requiresSecretsOnTypeChange(): Promise<void> {
    const user = userEvent.setup();
    const tlsDetail: MonitorDetail = {
        ...httpDetail,
        type: 'tls',
        configuration: {
            type: 'tls',
            tls_host: 'shop.example.test',
            tls_port: 443,
            tls_expiry_warning_days: null,
        },
    };

    renderInertia('Signalbox', Edit, { team, monitor: tlsDetail, defaults, options, usage });
    expect(screen.getByLabelText('Host')).toHaveValue('shop.example.test');

    await user.selectOptions(screen.getByLabelText('Type'), 'keyword');
    expect(screen.getByText(/discards the previous type/)).toBeInTheDocument();
    expect(screen.getByLabelText('URL')).toBeInTheDocument();
    expect(screen.queryByLabelText('Replace URL')).not.toBeInTheDocument();
}

/** Server errors appear inline and in a top alert, and focus moves to the first problem. */
async function showsValidationErrors(): Promise<void> {
    const user = userEvent.setup();
    let visit: VisitOptions | undefined;
    const frames: FrameRequestCallback[] = [];
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
        frames.push(callback);

        return frames.length;
    });
    vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        visit = options;
    });

    renderInertia('Signalbox', Create, { team, defaults, options, usage });
    await user.click(screen.getByRole('button', { name: 'Create monitor' }));
    act(() => {
        visit?.onError?.({
            monitor: 'Your plan allows 5 monitors. Delete a monitor or upgrade to add another.',
            interval_seconds: 'Your plan requires an interval of at least 300 seconds.',
        });
        visit?.onFinish?.({} as never);
    });
    // Run queued frames after React has rendered the errors, as a browser would.
    act(() => frames.forEach((frame) => frame(0)));

    const alerts = screen.getAllByRole('alert');
    expect(alerts[0]).toHaveTextContent('Your plan allows 5 monitors');
    expect(alerts[0]).toHaveFocus();
    expect(screen.getByLabelText('Interval (seconds)')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByLabelText('Interval (seconds)')).toHaveAccessibleDescription(
        /at least 300 seconds\. Your plan requires an interval of at least 300 seconds\./,
    );
}

/** A quota refusal after submission keeps everything typed, so the user can retry after freeing a slot. */
async function keepsInputAfterQuotaRefusal(): Promise<void> {
    const user = userEvent.setup();
    let visit: VisitOptions | undefined;
    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        visit = options;
    });

    renderInertia('Signalbox', Create, { team, defaults, options, usage });
    await user.type(screen.getByLabelText('Name'), 'Checkout');
    await user.type(screen.getByLabelText('URL'), 'https://shop.example.test/health');
    await user.click(screen.getByRole('button', { name: 'Create monitor' }));
    act(() => {
        visit?.onError?.({
            monitor: 'Your plan allows 5 monitors. Delete a monitor or upgrade to add another.',
        });
        visit?.onFinish?.({} as never);
    });

    expect(screen.getAllByRole('alert')[0]).toHaveTextContent('Your plan allows 5 monitors');
    expect(screen.getByLabelText('Name')).toHaveValue('Checkout');
    expect(screen.getByLabelText('URL')).toHaveValue('https://shop.example.test/health');
    expect(screen.getByRole('button', { name: 'Create monitor' })).toBeEnabled();

    await user.click(screen.getByRole('button', { name: 'Create monitor' }));
    expect(post).toHaveBeenCalledTimes(2);
}

/** Details show only safe values; viewers get no controls and deletion requires confirmation. */
async function showsDetailAndConfirmsDeletion(): Promise<void> {
    const user = userEvent.setup();
    const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    const confirm = vi
        .spyOn(window, 'confirm')
        .mockReturnValueOnce(false)
        .mockReturnValueOnce(true);
    const all = { update: true, delete: true, pause: true, resume: true };

    const view = renderInertia('Signalbox', Show, {
        ...quietHistory,
        team,
        monitor: httpDetail,
        can: { update: false, delete: false, pause: false, resume: false },
    });
    expect(screen.getByText('https://example.invalid/health?key=[REDACTED]')).toBeInTheDocument();
    expect(screen.getByText('authorization (values hidden)')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Edit monitor' })).not.toBeInTheDocument();
    view.unmount();

    renderInertia('Signalbox', Show, { ...quietHistory, team, monitor: httpDetail, can: all });
    expect(screen.getByRole('button', { name: 'Pause monitor' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Resume monitor' })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Delete monitor' }));
    expect(remove).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Delete monitor' }));
    expect(confirm).toHaveBeenCalledTimes(2);
    expect(remove).toHaveBeenCalledWith('/teams/7/monitors/11', expect.any(Object));
}

/** Suspended monitors explain why they do not run and offer no pause or resume. */
function explainsSuspension(): void {
    renderInertia('Signalbox', Show, {
        ...quietHistory,
        team,
        monitor: { ...httpDetail, state: 'suspended', withinQuota: false },
        can: { update: true, delete: true, pause: true, resume: true },
    });

    expect(
        screen.getByText(/suspended because the team's plan no longer covers it/),
    ).toBeInTheDocument();
    expect(screen.getByText('Suspended by plan')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Resume monitor' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Pause monitor' })).not.toBeInTheDocument();
}

test('shows an empty state for a team without monitors', showsEmptyTeam);
test('distinguishes no matches and announces rejected filters', showsNoMatchesAndRejectedFilters);
test('submits trimmed filters explicitly with a loading state', submitsFilters);
test('paginates with filter-preserving keyboard-reachable links', paginatesWithFilters);
test(
    'explains why creation is unavailable to viewers or at full quota',
    hidesCreationWhenNotAllowed,
);
test('creates a DNS monitor submitting only relevant fields', createsDnsMonitorWithRelevantFields);
test('edits without resending stored secrets unless replaced', editsWithoutResendingSecrets);
test('requires the complete new configuration when the type changes', requiresSecretsOnTypeChange);
test('shows validation errors inline and focuses the first problem', showsValidationErrors);
test('keeps typed input after a quota refusal and allows a retry', keepsInputAfterQuotaRefusal);
test('shows safe details and confirms deletion', showsDetailAndConfirmsDeletion);
test('explains plan suspension without pause or resume controls', explainsSuspension);
