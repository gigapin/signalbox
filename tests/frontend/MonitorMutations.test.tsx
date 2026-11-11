/** Exercise monitor mutation ownership, confirmation, safe failure semantics and preserved editor drafts. */
import { router } from '@inertiajs/react';
import { act, fireEvent, screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Show from '../../resources/js/pages/Monitors/Show';
import Create from '../../resources/js/pages/Monitors/Create';
import Edit from '../../resources/js/pages/Monitors/Edit';
import { detailProps, detailMonitor } from './monitorDetailFixture';
import { renderInertia } from './renderInertia';

/** Installed router callbacks captured behind the real useForm adapter. */
type Options = NonNullable<Parameters<typeof router.post>[2]>;

/** Synthetic form defaults and enumerations, with valid cadence and no stored credentials. */
const editorProps = {
    ...detailProps,
    defaults: {
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
    },
    options: {
        types: ['http', 'tls', 'dns', 'heartbeat', 'keyword'],
        statuses: ['up', 'down', 'unknown'],
        states: ['active', 'paused', 'suspended'],
        regions: ['eu', 'us'],
        methods: ['GET'],
        keywordModes: ['present', 'absent'],
        dnsRecordTypes: ['A'],
    },
    usage: { used: 1, limit: 5, minimumIntervalSeconds: 300, full: false },
    maximumIntervalSeconds: 86400,
};

/** Live hints granting transitions; the real server remains authoritative. */
const managingProps = {
    ...detailProps,
    can: { update: true, delete: true, pause: true, resume: true },
};

/**
 * @param path Active application mutation endpoint.
 * @param status Non-Inertia HTTP response to classify without rendering its content.
 * @returns A cancelable Inertia event carrying deliberately sensitive response data.
 */
function invalid(path: string, status: number) {
    const event = new CustomEvent('inertia:invalid', {
        cancelable: true,
        detail: { response: { status, config: { url: path }, data: 'private provider response' } },
    });
    act(() => {
        document.dispatchEvent(event);
    });
    return event;
}

test('confirms pause inline with Cancel focused and Escape restoring the trigger without sending', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', Show, managingProps);
    const trigger = screen.getByRole('button', { name: 'Pause monitor' });
    await user.click(trigger);
    expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus();
    expect(post).not.toHaveBeenCalled();
    await user.keyboard('{Escape}');
    expect(trigger).toHaveFocus();
    expect(screen.queryByRole('button', { name: 'Confirm pause' })).not.toBeInTheDocument();
    await user.click(trigger);
    await user.click(screen.getByRole('button', { name: 'Confirm pause' }));
    expect(post).toHaveBeenCalledOnce();
    expect(post.mock.calls[0]?.[1]).toEqual({});
    expect(
        new URL(post.mock.calls[0]![0] as string, location.href).searchParams.get('returnTo'),
    ).toBe(detailProps.monitorListUrl);
    expect(screen.getByRole('button', { name: 'Delete monitor' })).toBeDisabled();
    expect(screen.getByText('Waiting for the server to confirm…')).toBeInTheDocument();
});

test('requires explicit resume confirmation and retains disabled resume outside quota', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', Show, {
        ...managingProps,
        monitor: { ...detailMonitor, state: 'paused' },
    });
    await user.click(screen.getByRole('button', { name: 'Resume monitor' }));
    expect(post).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Confirm resume' }));
    expect(post.mock.calls[0]?.[0]).toContain('/resume?');
    expect(post).toHaveBeenCalledOnce();
});

test.each(['create', 'edit'] as const)(
    'blocks synchronous double submits in %s and preserves all fields on confirmed refusal',
    async (mode) => {
        const user = userEvent.setup();
        let options: Options | undefined;
        const dispatch = vi
            .spyOn(router, mode === 'create' ? 'post' : 'patch')
            .mockImplementation((_url, _data, visit) => {
                options = visit;
            });
        renderInertia('Signalbox', mode === 'create' ? Create : Edit, editorProps);
        const name = screen.getByLabelText('Name');
        await user.clear(name);
        await user.type(name, 'Private draft');
        const form = name.closest('form')!;
        act(() => {
            fireEvent.submit(form);
            fireEvent.submit(form);
        });
        expect(dispatch).toHaveBeenCalledOnce();
        expect(name).toBeDisabled();
        act(() => {
            options?.onError?.({ name: 'Rejected by the server' });
            options?.onFinish?.({} as never);
        });
        expect(name).toHaveValue('Private draft');
        expect(name).toBeEnabled();
        expect(screen.getByText('Change refused')).toBeInTheDocument();
        expect(name).toHaveAttribute('aria-invalid', 'true');
        await user.click(
            screen.getByRole('button', {
                name: mode === 'create' ? 'Create monitor' : 'Save changes',
            }),
        );
        expect(dispatch).toHaveBeenCalledTimes(2);
    },
);

test('a lost actual XHR response retains a create draft and blocks replay until explicit review', async () => {
    const send = vi.spyOn(XMLHttpRequest.prototype, 'send').mockImplementation(function (
        this: XMLHttpRequest,
    ) {
        queueMicrotask(() => this.dispatchEvent(new ProgressEvent('error')));
    });
    const user = userEvent.setup();
    renderInertia('Signalbox', Create, editorProps);
    await user.type(screen.getByLabelText('Name'), 'Keep private draft');
    await user.type(screen.getByLabelText('URL'), 'https://example.test/private');
    await user.click(screen.getByRole('button', { name: 'Create monitor' }));
    expect(await screen.findByText('Outcome not confirmed')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByLabelText('Name')).toBeEnabled());
    expect(screen.getByLabelText('Name')).toHaveValue('Keep private draft');
    expect(screen.getByLabelText('URL')).toHaveValue('https://example.test/private');
    expect(screen.getByRole('button', { name: 'Create monitor' })).toBeDisabled();
    expect(screen.getByRole('link', { name: 'Review saved data (new tab)' })).toHaveAttribute(
        'href',
        detailProps.monitorListUrl,
    );
    fireEvent.online(window);
    expect(screen.getByRole('button', { name: 'Create monitor' })).toBeDisabled();
    expect(send).toHaveBeenCalledOnce();
    await user.click(screen.getByRole('button', { name: 'I checked the saved data' }));
    expect(send).toHaveBeenCalledOnce();
    expect(screen.getByRole('button', { name: 'Create monitor' })).toBeEnabled();
});

test.each([200, 408, 409, 419, 422, 429, 499, 500, 503])(
    'classifies HTTP %s without rendering response content or retrying the mutation',
    async (status) => {
        const user = userEvent.setup();
        let options: Options | undefined;
        let path = '';
        const remove = vi.spyOn(router, 'delete').mockImplementation((url, visit) => {
            options = visit;
            if (typeof url !== 'string') throw new Error('Expected an application-built URL.');
            path = url;
        });
        renderInertia('Signalbox', Show, managingProps);
        await user.click(screen.getByRole('button', { name: 'Delete monitor' }));
        await user.click(screen.getByRole('button', { name: 'Confirm delete' }));
        expect(invalid('/different-endpoint', status).defaultPrevented).toBe(false);
        expect(invalid(path, status).defaultPrevented).toBe(true);
        act(() => options?.onFinish?.({} as never));
        expect(
            screen.getByText(
                [409, 419, 422, 429].includes(status) ? 'Change refused' : 'Outcome not confirmed',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('private provider response')).not.toBeInTheDocument();
        expect(remove).toHaveBeenCalledOnce();
        expect(
            screen.getByRole('button', { name: 'Delete monitor' }).hasAttribute('disabled'),
        ).toBe(![409, 419, 422, 429].includes(status));
    },
);

test.each([401, 403, 404])(
    'removes private data after authoritative mutation denial %s',
    async (status) => {
        const user = userEvent.setup();
        let path = '';
        vi.spyOn(router, 'post').mockImplementation((url) => {
            if (typeof url !== 'string') throw new Error('Expected an application-built URL.');
            path = url;
        });
        renderInertia('Signalbox', Show, managingProps);
        await user.click(screen.getByRole('button', { name: 'Pause monitor' }));
        await user.click(screen.getByRole('button', { name: 'Confirm pause' }));
        invalid(path, status);
        expect(screen.queryByRole('region', { name: 'TLS certificate' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete monitor' })).not.toBeInTheDocument();
    },
);

test('announces confirmed acceptance, ignores old callbacks and disposes only the owned request', async () => {
    const user = userEvent.setup();
    const visits: Options[] = [];
    vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        if (options) visits.push(options);
    });
    const view = renderInertia('Signalbox', Show, managingProps);
    await user.click(screen.getByRole('button', { name: 'Pause monitor' }));
    await user.click(screen.getByRole('button', { name: 'Confirm pause' }));
    await act(async () => {
        await Promise.resolve(visits[0]?.onSuccess?.({} as never));
        visits[0]?.onFinish?.({} as never);
    });
    expect(screen.getByText('Monitor change confirmed')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Pause monitor' }));
    await user.click(screen.getByRole('button', { name: 'Confirm pause' }));
    const cancel = vi.fn();
    act(() => visits[1]?.onCancelToken?.({ cancel }));
    act(() => visits[0]?.onError?.({}));
    expect(screen.getByText('Waiting for the server to confirm…')).toBeInTheDocument();
    view.unmount();
    expect(cancel).toHaveBeenCalledOnce();
    act(() => visits[1]?.onFinish?.({} as never));
});

test('Cancel preserves a dirty editor through the existing departure confirmation', async () => {
    const show = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
    const close = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', {
        configurable: true,
        value(this: HTMLDialogElement) {
            this.open = true;
        },
    });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', {
        configurable: true,
        value(this: HTMLDialogElement) {
            this.open = false;
        },
    });
    const user = userEvent.setup();
    const view = renderInertia('Signalbox', Edit, editorProps);
    try {
        await user.type(screen.getByLabelText('Name'), ' draft');
        await user.click(screen.getByRole('link', { name: 'Cancel' }));
        const dialog = within(screen.getByRole('dialog', { name: 'Discard unsaved changes?' }));
        await user.click(dialog.getByRole('button', { name: 'Stay' }));
        expect(screen.getByLabelText('Name')).toHaveValue(`${detailMonitor.name} draft`);
    } finally {
        view.unmount();
        if (show) Object.defineProperty(HTMLDialogElement.prototype, 'showModal', show);
        else Reflect.deleteProperty(HTMLDialogElement.prototype, 'showModal');
        if (close) Object.defineProperty(HTMLDialogElement.prototype, 'close', close);
        else Reflect.deleteProperty(HTMLDialogElement.prototype, 'close');
    }
});

test('cancellation after dispatch keeps an uncertain outcome and no finish callback replays the request', async () => {
    const user = userEvent.setup();
    let options: Options | undefined;
    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, visit) => {
        options = visit;
    });
    renderInertia('Signalbox', Create, editorProps);
    await user.type(screen.getByLabelText('Name'), 'Retained after cancel');
    await user.click(screen.getByRole('button', { name: 'Create monitor' }));
    act(() => {
        options?.onCancel?.();
        options?.onFinish?.({} as never);
    });
    expect(screen.getByText('Outcome not confirmed')).toBeInTheDocument();
    expect(screen.getByLabelText('Name')).toHaveValue('Retained after cancel');
    expect(screen.getByRole('button', { name: 'Create monitor' })).toBeDisabled();
    expect(post).toHaveBeenCalledOnce();
});
