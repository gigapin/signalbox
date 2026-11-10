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

/** Operational upper cadence bound projected by the server into both form pages. */
const maximumIntervalSeconds = 86400;

test.each([
    { mode: 'create', minimum: 60, invalid: '30' },
    { mode: 'create', minimum: 300, invalid: '60' },
    { mode: 'edit', minimum: 60, invalid: '30' },
    { mode: 'edit', minimum: 300, invalid: '60' },
])(
    'validates $mode cadence while typing and blocks click and Enter below $minimum seconds',
    async ({ mode, minimum, invalid }) => {
        const user = userEvent.setup();
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
        renderInertia('Signalbox', mode === 'create' ? Create : Edit, {
            team,
            defaults,
            options,
            usage: { ...usage, minimumIntervalSeconds: minimum },
            maximumIntervalSeconds,
            ...(mode === 'edit' ? { monitor: httpDetail } : {}),
        });
        const interval = screen.getByLabelText('Interval (seconds)');
        const originalUrl = window.location.href;
        await user.clear(interval);
        await user.type(interval, invalid);

        expect(interval).toHaveFocus();
        expect(interval).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('alert')).toHaveTextContent(`at least ${minimum} seconds`);
        await user.keyboard('{Enter}');
        await user.click(
            screen.getByRole('button', {
                name: mode === 'create' ? 'Create monitor' : 'Save changes',
            }),
        );

        expect(post).not.toHaveBeenCalled();
        expect(patch).not.toHaveBeenCalled();
        expect(window.location.href).toBe(originalUrl);
        expect(interval).toHaveValue(invalid);
        expect(interval).toHaveFocus();

        await user.clear(interval);
        await user.type(interval, String(minimum));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(interval).toHaveAttribute('aria-invalid', 'false');
        await user.keyboard('{Enter}');
        expect(mode === 'create' ? post : patch).toHaveBeenCalledOnce();
    },
);

test.each(['', '-1', 'abc', '60.5', '3e2', '9007199254740993', '86401'])(
    'keeps invalid cadence %j local without submitting it',
    async (value) => {
        const user = userEvent.setup();
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
        renderInertia('Signalbox', Edit, {
            team,
            monitor: httpDetail,
            defaults,
            options,
            usage,
            maximumIntervalSeconds,
        });
        const interval = screen.getByLabelText('Interval (seconds)');
        await user.clear(interval);
        if (value !== '') {
            await user.type(interval, value);
        }

        expect(interval).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('alert')).toBeInTheDocument();
        await user.keyboard('{Enter}');
        expect(patch).not.toHaveBeenCalled();
        expect(interval).toHaveValue(value);
    },
);

test('allows an unchanged legacy cadence below the plan minimum while validating edits to it', async () => {
    const user = userEvent.setup();
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    renderInertia('Signalbox', Edit, {
        team,
        monitor: { ...httpDetail, intervalSeconds: 60 },
        defaults,
        options,
        usage,
        maximumIntervalSeconds,
    });
    await user.type(screen.getByLabelText('Name'), ' revised');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(patch).toHaveBeenCalledOnce();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();

    await user.clear(screen.getByLabelText('Interval (seconds)'));
    await user.type(screen.getByLabelText('Interval (seconds)'), '120');
    expect(screen.getByRole('alert')).toHaveTextContent('at least 300 seconds');
    await user.keyboard('{Enter}');
    expect(patch).toHaveBeenCalledOnce();
});

test('clears a server interval error when editing it and uses the configured maximum', async () => {
    const user = userEvent.setup();
    let visit: VisitOptions | undefined;
    vi.spyOn(router, 'patch').mockImplementation((_url, _data, options) => {
        visit = options;
    });
    renderInertia('Signalbox', Edit, {
        team,
        monitor: httpDetail,
        defaults,
        options,
        usage,
        maximumIntervalSeconds: 7200,
    });
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    act(() =>
        visit?.onError?.({ interval_seconds: 'The plan changed. Reload to see its new minimum.' }),
    );
    await user.clear(screen.getByLabelText('Interval (seconds)'));
    await user.type(screen.getByLabelText('Interval (seconds)'), '7201');
    expect(screen.getByRole('alert')).toHaveTextContent('at most 7200 seconds');
    expect(
        screen.queryByText('The plan changed. Reload to see its new minimum.'),
    ).not.toBeInTheDocument();
    await user.clear(screen.getByLabelText('Interval (seconds)'));
    await user.type(screen.getByLabelText('Interval (seconds)'), '7200');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

/** Monitor detail sections without incidents, maintenance or escalation policies, as for a new monitor. */
const quietHistory = {
    recentIncidents: [],
    maintenance: { current: [], finished: [], reasonMaxLength: 1000 },
    escalation: { policyId: null, effective: null, options: [] },
};

/** No active filters. */
const noFilters = { q: '', type: null, status: null, state: null };

test('lets keyboard users reach the monitor table viewport before its monitor links', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Index, indexProps([row(11, 'Checkout API')]));
    const viewport = screen.getByRole('region', { name: 'Monitors of Synthetic team' });

    for (let step = 0; step < 20 && document.activeElement !== viewport; step++) {
        await user.tab();
    }

    expect(viewport).toHaveFocus();
    expect(within(viewport).getByRole('table')).toHaveAccessibleName('Monitors of Synthetic team');
    await user.tab();
    expect(screen.getByRole('link', { name: 'Checkout API' })).toHaveFocus();
});

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

    expect(screen.getByText('No monitors match these filters.')).toBeInTheDocument();
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
        expect.objectContaining({ replace: false }),
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

    renderInertia('Signalbox', Create, { team, defaults, options, usage, maximumIntervalSeconds });
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

    renderInertia('Signalbox', Edit, {
        team,
        monitor: httpDetail,
        defaults,
        options,
        usage,
        maximumIntervalSeconds,
    });
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

    renderInertia('Signalbox', Edit, {
        team,
        monitor: tlsDetail,
        defaults,
        options,
        usage,
        maximumIntervalSeconds,
    });
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

    renderInertia('Signalbox', Create, { team, defaults, options, usage, maximumIntervalSeconds });
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

test('focuses a server refusal after errors render even when the visit finishes before React commits', async () => {
    const user = userEvent.setup();
    const frames: FrameRequestCallback[] = [];
    let visit: VisitOptions | undefined;
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
        frames.push(callback);

        return frames.length;
    });
    vi.spyOn(router, 'patch').mockImplementation((_url, _data, options) => {
        visit = options;
    });

    renderInertia('Signalbox', Edit, {
        maximumIntervalSeconds,
        team,
        monitor: httpDetail,
        defaults,
        options,
        usage: { ...usage, minimumIntervalSeconds: 60 },
    });
    const interval = screen.getByLabelText('Interval (seconds)');
    await user.clear(interval);
    await user.type(interval, '120');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    act(() => {
        visit?.onError?.({
            interval_seconds: 'Your plan requires an interval of at least 300 seconds.',
        });
        visit?.onFinish?.({} as never);
        // A finish callback can run before the validation state has reached the DOM.
        frames.splice(0).forEach((frame) => frame(0));
    });
    act(() => frames.splice(0).forEach((frame) => frame(0)));

    expect(interval).toHaveFocus();
    expect(interval).toHaveValue('120');
    expect(interval).toHaveAttribute('aria-invalid', 'true');
    expect(interval).toHaveAccessibleDescription(/Your plan requires an interval of at least 300/);

    await user.click(screen.getByLabelText('Name'));
    await user.type(screen.getByLabelText('Name'), ' revised');
    act(() => frames.splice(0).forEach((frame) => frame(0)));
    expect(screen.getByLabelText('Name')).toHaveFocus();
});

test('cancels pending validation focus when leaving the monitor editor', () => {
    vi.spyOn(window, 'requestAnimationFrame').mockReturnValue(73);
    const cancel = vi.spyOn(window, 'cancelAnimationFrame');
    const view = renderInertia('Signalbox', Edit, {
        maximumIntervalSeconds,
        team,
        monitor: httpDetail,
        defaults,
        options,
        usage,
        errors: {},
    });
    let visit: VisitOptions | undefined;
    vi.spyOn(router, 'patch').mockImplementation((_url, _data, options) => {
        visit = options;
    });

    act(() => {
        screen.getByRole('button', { name: 'Save changes' }).click();
        visit?.onError?.({ interval_seconds: 'Your plan requires at least 300 seconds.' });
    });
    view.unmount();

    expect(cancel).toHaveBeenCalledWith(73);
});

/** A quota refusal after submission keeps everything typed, so the user can retry after freeing a slot. */
async function keepsInputAfterQuotaRefusal(): Promise<void> {
    const user = userEvent.setup();
    let visit: VisitOptions | undefined;
    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        visit = options;
    });

    renderInertia('Signalbox', Create, { team, defaults, options, usage, maximumIntervalSeconds });
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

test('renders safe destinations and carries accepted filters and page into detail links', () => {
    const props = indexProps(
        [{ ...row(11, 'Checkout'), destination: 'https://example.test/health?key=[REDACTED]' }],
        {
            filters: { ...noFilters, q: 'api', type: 'http' },
            filtered: true,
        },
    );
    props.monitors.currentPage = 2;
    renderInertia('Signalbox', Index, props);
    const detail = screen.getByRole('link', { name: 'Checkout' });
    expect(
        new URL(detail.getAttribute('href')!, 'https://signalbox.test').searchParams.get(
            'returnTo',
        ),
    ).toBe('/teams/7/monitors?q=api&type=http&page=2');
    expect(screen.getByText('https://example.test/health?key=[REDACTED]')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /https:\/\// })).not.toBeInTheDocument();
});

test('distinguishes missing destinations and heartbeat ingress without creating endpoint links', () => {
    renderInertia(
        'Signalbox',
        Index,
        indexProps([row(1, 'HTTP'), { ...row(2, 'Cron'), type: 'heartbeat' }]),
    );
    expect(screen.getByText('Destination unavailable')).toBeInTheDocument();
    expect(screen.getByText('Receives heartbeat signals')).toBeInTheDocument();
    expect(screen.getAllByText('No check recorded')).toHaveLength(2);
});

test('distinguishes an empty page from an empty filter result while preserving applied filters', () => {
    const props = indexProps([], { filters: { ...noFilters, q: 'api' }, filtered: true });
    props.monitors = { ...props.monitors, currentPage: 9, lastPage: 2, total: 21 };
    renderInertia('Signalbox', Index, props);
    expect(screen.getByText('No monitors on this page.')).toBeInTheDocument();
    expect(screen.queryByText('No monitors match these filters.')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to the first page' })).toHaveAttribute(
        'href',
        '/teams/7/monitors?q=api',
    );
});

test('keeps typed filters and prior rows after a refused search and retries only on explicit submission', async () => {
    const user = userEvent.setup();
    let callbacks: VisitOptions | undefined;
    const get = vi.spyOn(router, 'get').mockImplementation((_path, _data, options) => {
        callbacks = options;
        callbacks?.onStart?.({} as never);
    });
    renderInertia('Signalbox', Index, indexProps([row(1, 'Previous')]));
    await user.type(screen.getByLabelText('Search'), 'new query');
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(screen.getByLabelText('Search')).toBeDisabled();
    act(() => {
        callbacks?.onError?.({ search: 'Refused' });
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByRole('alert')).toHaveTextContent('Previous results are still shown');
    expect(screen.getByLabelText('Search')).toHaveValue('new query');
    expect(screen.getByRole('link', { name: 'Previous' })).toBeInTheDocument();
    expect(get).toHaveBeenCalledOnce();
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(get).toHaveBeenCalledTimes(2);
});

test.each(['exception', 'invalid'])(
    'handles only its active search %s and removes handlers on unmount',
    async (eventName) => {
        const user = userEvent.setup();
        vi.spyOn(router, 'get').mockImplementation((_path, _data, callbacks) =>
            callbacks?.onStart?.({} as never),
        );
        const view = renderInertia('Signalbox', Index, indexProps([row(1, 'Previous')]));
        await user.click(screen.getByRole('button', { name: 'Apply filters' }));
        const failure = new CustomEvent(`inertia:${eventName}`, {
            cancelable: true,
            detail: { exception: new Error('private diagnostic'), response: { status: 500 } },
        });
        act(() => {
            document.dispatchEvent(failure);
        });
        expect(failure.defaultPrevented).toBe(true);
        expect(screen.getByRole('alert')).toHaveTextContent('Search could not be completed');
        expect(screen.queryByText('private diagnostic')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Apply filters' })).toBeEnabled();
        view.unmount();
        const unrelated = new CustomEvent(`inertia:${eventName}`, { cancelable: true });
        document.dispatchEvent(unrelated);
        expect(unrelated.defaultPrevented).toBe(false);
    },
);

test('cancels only its owned pending read when leaving the list', async () => {
    const user = userEvent.setup();
    const cancel = vi.fn();
    vi.spyOn(router, 'get').mockImplementation((_path, _data, callbacks) => {
        callbacks?.onCancelToken?.({ cancel });
        callbacks?.onStart?.({} as never);
    });
    const view = renderInertia('Signalbox', Index, indexProps([row(1, 'Previous')]));
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    view.unmount();
    expect(cancel).toHaveBeenCalledOnce();
});

test('uses the normalized list context in detail navigation breadcrumb and editor links', () => {
    const monitorListUrl = '/teams/7/monitors?q=api&type=http&page=2';
    renderInertia('Signalbox', Show, {
        ...quietHistory,
        team,
        monitor: httpDetail,
        monitorListUrl,
        can: { update: true, delete: false, pause: false, resume: false },
    });
    expect(screen.getByRole('link', { name: 'Back to monitors' })).toHaveAttribute(
        'href',
        monitorListUrl,
    );
    const edit = new URL(
        screen.getByRole('link', { name: 'Edit monitor' }).getAttribute('href')!,
        'https://signalbox.test',
    );
    expect(edit.pathname).toBe('/teams/7/monitors/11/edit');
    expect(edit.searchParams.get('returnTo')).toBe(monitorListUrl);
    expect(
        within(screen.getByRole('navigation', { name: 'Breadcrumb' })).getByRole('link', {
            name: 'Monitors',
        }),
    ).toHaveAttribute('href', monitorListUrl);
});

test('preserves list context on creation cancellation and editor return without putting it in mutation payloads', async () => {
    const monitorListUrl = '/teams/7/monitors?state=paused&page=2';
    const view = renderInertia('Signalbox', Create, {
        team,
        monitorListUrl,
        defaults,
        options,
        usage,
        maximumIntervalSeconds,
    });
    expect(screen.getByRole('link', { name: 'Back to monitors' })).toHaveAttribute(
        'href',
        monitorListUrl,
    );
    view.unmount();
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    renderInertia('Signalbox', Edit, {
        team,
        monitor: httpDetail,
        monitorListUrl,
        defaults,
        options,
        usage,
        maximumIntervalSeconds,
    });
    const target = new URL(
        screen.getByRole('link', { name: 'Back to monitor' }).getAttribute('href')!,
        'https://signalbox.test',
    );
    expect(target.searchParams.get('returnTo')).toBe(monitorListUrl);
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(patch.mock.calls[0]?.[0]).toBe('/teams/7/monitors/11');
    expect(patch.mock.calls[0]?.[1]).not.toHaveProperty('returnTo');
});

test('ignores a late finish from an earlier failed search after an explicit retry starts', async () => {
    const user = userEvent.setup();
    const attempts: VisitOptions[] = [];
    const get = vi.spyOn(router, 'get').mockImplementation((_path, _data, callbacks) => {
        attempts.push(callbacks ?? {});
        callbacks?.onStart?.({} as never);
    });
    renderInertia('Signalbox', Index, indexProps([row(1, 'Previous')]));
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    act(() => attempts[0]?.onError?.({ search: 'Refused' }));
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    act(() => attempts[0]?.onFinish?.({} as never));
    expect(screen.getByRole('button', { name: 'Searching…' })).toBeDisabled();
    expect(get).toHaveBeenCalledTimes(2);
    act(() => attempts[1]?.onFinish?.({} as never));
    expect(screen.getByRole('button', { name: 'Apply filters' })).toBeEnabled();
});

test.each([401, 403, 404])(
    'hides private rows and filters when its search returns authoritative access loss %s',
    async (status) => {
        const user = userEvent.setup();
        vi.spyOn(router, 'get').mockImplementation((_path, _data, callbacks) =>
            callbacks?.onStart?.({} as never),
        );
        renderInertia('Signalbox', Index, indexProps([row(1, 'Private monitor')]));
        await user.click(screen.getByRole('button', { name: 'Apply filters' }));
        act(() => {
            document.dispatchEvent(
                new CustomEvent('inertia:invalid', {
                    cancelable: true,
                    detail: { response: { status } },
                }),
            );
        });
        expect(screen.queryByRole('link', { name: 'Private monitor' })).not.toBeInTheDocument();
        expect(screen.queryByRole('search')).not.toBeInTheDocument();
        expect(screen.getByText(/Live access is no longer available/)).toBeInTheDocument();
    },
);
