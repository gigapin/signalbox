/** Verify real incident pages through owned requests, safe navigation, publication and draft-preserving recovery. */
import { router } from '@inertiajs/react';
import { act, fireEvent, screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Show from '../../resources/js/pages/Incidents/Show';
import Index from '../../resources/js/pages/Incidents/Index';
import { workflowIncident, workflowProps } from './incidentWorkflowFixture';
import { renderInertia } from './renderInertia';
import { openTeamStream } from '../../resources/js/realtime/teamStream';
import type { TeamStreamCallbacks } from '../../resources/js/realtime/types';

vi.mock('../../resources/js/realtime/teamStream', () => ({ openTeamStream: vi.fn() }));

/** Installed Inertia callbacks captured behind the actual useForm adapter. */
type Visit = NonNullable<Parameters<typeof router.post>[2]>;

/** Paginated incident list with accepted filters and a previous-page context. */
const listProps = {
    ...workflowProps,
    incidents: {
        data: [workflowIncident],
        currentPage: 2,
        lastPage: 2,
        perPage: 20,
        total: 21,
        from: 21,
        to: 21,
    },
    filters: { state: 'open', severity: 'major' },
    filtered: true,
    rejectedFilters: [],
};

test('preserves the filtered list and monitor returns in detail navigation and breadcrumbs', () => {
    renderInertia('Signalbox', Show, workflowProps);
    expect(screen.getByRole('link', { name: 'Back to incidents' })).toHaveAttribute(
        'href',
        workflowProps.incidentListUrl,
    );
    expect(screen.getByRole('link', { name: 'Monitor Synthetic API' })).toHaveAttribute(
        'href',
        workflowProps.monitorUrl,
    );
    expect(
        within(screen.getByRole('navigation', { name: 'Breadcrumb' })).getByRole('link', {
            name: 'Incidents',
        }),
    ).toHaveAttribute('href', workflowProps.incidentListUrl);
});

test('list rows preserve accepted filters and pagination in their detail links', () => {
    renderInertia('Signalbox', Index, listProps);
    const href = screen.getByRole('link', { name: 'Incident #42' }).getAttribute('href')!;
    expect(new URL(href, location.href).searchParams.get('returnTo')).toBe(
        workflowProps.incidentListUrl,
    );
    expect(screen.getByRole('table', { name: 'Incidents of Synthetic team' })).toBeInTheDocument();
});

test('a refused filter read retains the selection and rows and allows an explicit retry', async () => {
    const user = userEvent.setup();
    let callbacks: NonNullable<Parameters<typeof router.get>[2]> | undefined;
    const read = vi.spyOn(router, 'get').mockImplementation((_path, _data, options) => {
        callbacks = options;
        options?.onStart?.({} as never);
    });
    renderInertia('Signalbox', Index, listProps);
    await user.selectOptions(screen.getByLabelText('Severity'), 'critical');
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(screen.getByLabelText('Severity')).toBeDisabled();
    act(() => {
        callbacks?.onError?.({ filter: 'refused' });
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByText('Incident filters could not be loaded')).toBeInTheDocument();
    expect(screen.getByLabelText('Severity')).toHaveValue('critical');
    expect(screen.getByRole('link', { name: 'Incident #42' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(read).toHaveBeenCalledTimes(2);
});

test('publication requires inline confirmation and Escape restores its trigger without sending', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', Show, workflowProps);
    const trigger = screen.getByRole('button', { name: 'Publish update' });
    await user.click(trigger);
    expect(screen.getByRole('button', { name: 'Cancel publication' })).toHaveFocus();
    expect(screen.getByText(/cannot be withdrawn/)).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(trigger).toHaveFocus();
    expect(post).not.toHaveBeenCalled();
    await user.click(trigger);
    await user.click(screen.getByRole('button', { name: 'Confirm publication' }));
    expect(post).toHaveBeenCalledOnce();
    expect(post.mock.calls[0]?.[0]).toBe('/teams/7/incidents/42/updates/100/publish');
});

test('repeated submits send one private note and a refusal preserves its text', async () => {
    const user = userEvent.setup();
    let visit: Visit | undefined;
    const post = vi.spyOn(router, 'post').mockImplementation((_path, _data, options) => {
        visit = options;
    });
    renderInertia('Signalbox', Show, workflowProps);
    await user.type(screen.getByLabelText('Message'), 'Keep this private draft');
    const form = screen.getByRole('form', { name: 'Add a timeline update' });
    act(() => {
        fireEvent.submit(form);
        fireEvent.submit(form);
    });
    expect(post).toHaveBeenCalledOnce();
    expect(screen.getByLabelText('Message')).toBeDisabled();
    act(() => {
        visit?.onError?.({ message: 'Server refused' });
        visit?.onFinish?.({} as never);
    });
    expect(screen.getByLabelText('Message')).toHaveValue('Keep this private draft');
    expect(screen.getByText('Change refused')).toBeInTheDocument();
    expect(screen.getByLabelText('Message')).toBeEnabled();
});

test('a confirmed note clears the submitted message without replaying the request', async () => {
    const user = userEvent.setup();
    let visit: Visit | undefined;
    const post = vi.spyOn(router, 'post').mockImplementation((_path, _data, options) => {
        visit = options;
    });
    renderInertia('Signalbox', Show, workflowProps);
    await user.type(screen.getByLabelText('Message'), 'Accepted private note');
    await user.click(screen.getByRole('button', { name: 'Add update' }));
    await act(async () => {
        await Promise.resolve(visit?.onSuccess?.({} as never));
        visit?.onFinish?.({} as never);
    });
    expect(screen.getByLabelText('Message')).toHaveValue('');
    expect(screen.getByText('Timeline update saved')).toBeInTheDocument();
    expect(post).toHaveBeenCalledOnce();
});

test('a lost actual XHR response preserves an incident draft and blocks accidental replay', async () => {
    const send = vi.spyOn(XMLHttpRequest.prototype, 'send').mockImplementation(function (
        this: XMLHttpRequest,
    ) {
        queueMicrotask(() => this.dispatchEvent(new ProgressEvent('error')));
    });
    const user = userEvent.setup();
    renderInertia('Signalbox', Show, workflowProps);
    await user.type(screen.getByLabelText('Message'), 'Possibly saved note');
    await user.click(screen.getByRole('button', { name: 'Add update' }));
    expect(await screen.findByText('Outcome not confirmed')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByLabelText('Message')).toBeEnabled());
    expect(screen.getByLabelText('Message')).toHaveValue('Possibly saved note');
    expect(screen.getByRole('button', { name: 'Add update' })).toBeDisabled();
    expect(send).toHaveBeenCalledOnce();
});

test.each([0, null])(
    'renders a recorded resolved duration %j without inventing ongoing time',
    (durationSeconds) => {
        renderInertia('Signalbox', Show, {
            ...workflowProps,
            incident: {
                ...workflowIncident,
                status: 'resolved',
                resolvedAt: '2026-11-11T10:10:00Z',
                durationSeconds,
            },
            can: { update: false },
            postableStatuses: ['resolved'],
        });
        expect(
            within(screen.getByRole('region', { name: 'Incident summary' })).getByText(
                durationSeconds === 0 ? '0 s' : 'Unavailable',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Take responsibility' }),
        ).not.toBeInTheDocument();
    },
);

test('live resolution preserves unsent note text and severity while exposing only admitted statuses', async () => {
    vi.useFakeTimers();
    let signals: TeamStreamCallbacks | undefined;
    vi.mocked(openTeamStream).mockImplementation((_config, _team, callbacks) => {
        signals = callbacks;
        return vi.fn();
    });
    const configuration = { key: 'public', host: 'localhost', port: 8080, scheme: 'http' };
    const next = {
        ...workflowProps,
        realtime: configuration,
        incident: {
            ...workflowIncident,
            status: 'resolved',
            severity: 'critical',
            resolvedAt: '2026-11-11T10:10:00Z',
            durationSeconds: 600,
            rootCauseNote: 'Updated server cause',
        },
        postableStatuses: ['resolved'],
    };
    vi.stubGlobal(
        'fetch',
        vi.fn<typeof fetch>().mockResolvedValue(
            new Response(JSON.stringify({ component: 'Incidents/Show', props: next }), {
                headers: { 'X-Inertia': 'true' },
            }),
        ),
    );
    renderInertia('Signalbox', Show, { ...workflowProps, realtime: configuration });
    fireEvent.change(screen.getByLabelText('Message'), { target: { value: 'Unsent note' } });
    fireEvent.change(screen.getByLabelText('Severity'), { target: { value: 'minor' } });
    fireEvent.change(screen.getByLabelText('Declared cause'), {
        target: { value: 'Keep this cause draft' },
    });
    act(() => signals?.subscribed());
    await act(async () => {
        await vi.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByLabelText('Message')).toHaveValue('Unsent note');
    expect(screen.getByLabelText('Severity')).toHaveValue('minor');
    expect(screen.getByLabelText('Declared cause')).toHaveValue('Keep this cause draft');
    expect(screen.getByRole('button', { name: 'Add update' })).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Status after this update'), {
        target: { value: 'resolved' },
    });
    expect(screen.getByRole('button', { name: 'Add update' })).toBeEnabled();
    expect(screen.queryByRole('button', { name: 'Take responsibility' })).not.toBeInTheDocument();
});

test('a preserved navigation to another incident removes the previous message draft', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    renderInertia('Signalbox', Show, workflowProps, {
        component: 'Incidents/Show',
        url: '/teams/7/incidents/42',
    });
    fireEvent.change(screen.getByLabelText('Message'), {
        target: { value: 'Previous incident draft' },
    });
    await act(async () => {
        await Promise.resolve(
            router.replace({
                url: '/teams/7/incidents/43',
                props: {
                    ...workflowProps,
                    appName: 'Signalbox',
                    errors: {},
                    incident: { ...workflowIncident, id: 43 },
                },
                preserveState: true,
            }),
        );
    });
    expect(screen.getByRole('heading', { level: 1, name: 'Incident #43' })).toBeInTheDocument();
    expect(screen.getByLabelText('Message')).toHaveValue('');
});

test('a history-like page replacement restores accepted filters instead of keeping an unsent selection', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    renderInertia('Signalbox', Index, listProps, {
        component: 'Incidents/Index',
        url: workflowProps.incidentListUrl,
    });
    fireEvent.change(screen.getByLabelText('Severity'), { target: { value: 'critical' } });
    await act(async () => {
        await Promise.resolve(
            router.replace({
                url: '/teams/7/incidents?state=open&severity=major',
                props: { ...listProps, appName: 'Signalbox', errors: {} },
                preserveState: true,
            }),
        );
    });
    expect(screen.getByLabelText('Severity')).toHaveValue('major');
});

test('pristine incident editors accept a server snapshot without creating a departure draft', async () => {
    vi.useFakeTimers();
    let signals: TeamStreamCallbacks | undefined;
    vi.mocked(openTeamStream).mockImplementation((_configuration, _team, callbacks) => {
        signals = callbacks;
        return vi.fn();
    });
    const realtime = { key: 'public', host: 'localhost', port: 8080, scheme: 'http' };
    const next = {
        ...workflowProps,
        realtime,
        incident: {
            ...workflowIncident,
            status: 'identified',
            severity: 'critical',
            rootCauseNote: 'Saved server diagnosis',
        },
    };
    vi.stubGlobal(
        'fetch',
        vi.fn<typeof fetch>().mockResolvedValue(
            new Response(JSON.stringify({ component: 'Incidents/Show', props: next }), {
                headers: { 'X-Inertia': 'true' },
            }),
        ),
    );
    renderInertia('Signalbox', Show, { ...workflowProps, realtime });
    act(() => signals?.subscribed());
    await act(async () => {
        await vi.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByLabelText('Status after this update')).toHaveValue('identified');
    expect(screen.getByLabelText('Severity')).toHaveValue('critical');
    expect(screen.getByLabelText('Declared cause')).toHaveValue('Saved server diagnosis');
    const unload = new Event('beforeunload', { cancelable: true });
    fireEvent(window, unload);
    expect(unload.defaultPrevented).toBe(false);
});
