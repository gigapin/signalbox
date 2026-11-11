/** Audit foreground GET ownership through the actual hook and removable private page boundary. */
import { router } from '@inertiajs/react';
import { act, fireEvent, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import AppLayout from '../../resources/js/components/app/AppLayout';
import { useReadNavigation } from '../../resources/js/hooks/useReadNavigation';
import { renderInertia } from './renderInertia';

/** Installed GET lifecycle; tests replace the transport, not useReadNavigation or the access boundary. */
type Visit = NonNullable<Parameters<typeof router.get>[2]>;
/** Accepted read controls include spaces and a slash to exercise real query normalization. */
const selection = { q: 'API / EU', state: 'active' };
/** Equivalent Axios URL after the installed adapter places GET data in the query. */
const ownedUrl = '/teams/7/monitors?q=API%20%2F%20EU&state=active';

/** Mount retained private results and issue one of two explicit reads. */
function Content() {
    const read = useReadNavigation();
    return (
        <>
            <p>Prior private results</p>
            <p>{read.loading ? 'Read pending' : 'Read idle'}</p>
            {read.failed && <p role="alert">Read failed; retry manually</p>}
            <button onClick={() => read.navigate('/teams/7/monitors', selection)}>
                Read filters
            </button>
            <button onClick={() => read.navigate('/teams/7/monitors', { q: 'other' })}>
                Read another selection
            </button>
        </>
    );
}

/** Real private shell removes its children when the owned read reports authoritative access loss. */
function Page() {
    return (
        <AppLayout
            team={{ id: 7, name: 'Synthetic team' }}
            section="monitors"
            title="Read ownership"
        >
            <Content />
        </AppLayout>
    );
}

/** Start one controlled visit and expose only installed lifecycle callbacks. */
async function fixture() {
    const user = userEvent.setup();
    const visits: Visit[] = [];
    const read = vi.spyOn(router, 'get').mockImplementation((_url, _data, options) => {
        if (options) visits.push(options);
        options?.onStart?.({} as never);
    });
    const view = renderInertia('Signalbox', Page);
    await user.click(screen.getByRole('button', { name: 'Read filters' }));
    return { user, visits, read, view };
}

/** Dispatch a real cancelable Inertia response event without exposing its deliberately private body. */
function invalid(url: string | undefined, status: number): Event {
    const event = new CustomEvent('inertia:invalid', {
        cancelable: true,
        detail: { response: { status, config: { url }, data: 'Private response diagnostics' } },
    });
    act(() => {
        document.dispatchEvent(event);
    });
    return event;
}

/** Dispatch an exception; only recognized response-less browser transport errors belong to this UI path. */
function exception(error: unknown): Event {
    const event = new CustomEvent('inertia:exception', {
        cancelable: true,
        detail: { exception: error },
    });
    act(() => {
        document.dispatchEvent(event);
    });
    return event;
}

/** Construct the installed browser adapter's response-less failure shape with no displayed diagnostic data. */
function network(url: string | undefined) {
    return Object.assign(new Error('Private URL and credential diagnostics'), {
        isAxiosError: true,
        code: 'ERR_NETWORK',
        request: new XMLHttpRequest(),
        config: { url },
    });
}

test.each([401, 403, 404])(
    'an unrelated %s response cannot remove current private results',
    async (status) => {
        await fixture();
        expect(invalid('/different-request', status).defaultPrevented).toBe(false);
        expect(screen.getByText('Prior private results')).toBeInTheDocument();
        expect(screen.getByText('Read pending')).toBeInTheDocument();
    },
);

test.each([
    undefined,
    '/teams/7/monitors?q=other&state=active',
    'https://foreign.example/teams/7/monitors?q=API%20%2F%20EU&state=active',
])(
    'missing, different-filter or foreign-origin response attribution does not deny the active read (%s)',
    async (url) => {
        await fixture();
        expect(invalid(url, 403).defaultPrevented).toBe(false);
        expect(screen.getByText('Prior private results')).toBeInTheDocument();
    },
);

test('a programming exception is not swallowed as an ordinary read failure', async () => {
    await fixture();
    expect(
        exception(
            Object.assign(new TypeError('Programming failure'), { config: { url: ownedUrl } }),
        ).defaultPrevented,
    ).toBe(false);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByText('Read pending')).toBeInTheDocument();
});

test.each([undefined, '/unrelated'])(
    'response-less failures without matching URL ownership stay unconsumed (%s)',
    async (url) => {
        await fixture();
        expect(exception(network(url)).defaultPrevented).toBe(false);
        expect(screen.getByText('Read pending')).toBeInTheDocument();
    },
);

test('an owned network failure preserves results and permits one explicit retry without replay', async () => {
    const { user, visits, read } = await fixture();
    expect(exception(network(ownedUrl)).defaultPrevented).toBe(true);
    act(() => visits[0]?.onFinish?.({} as never));
    expect(screen.getByRole('alert')).toHaveTextContent('Read failed');
    expect(screen.getByText('Prior private results')).toBeInTheDocument();
    expect(screen.queryByText('Private URL and credential diagnostics')).not.toBeInTheDocument();
    expect(read).toHaveBeenCalledOnce();
    await user.click(screen.getByRole('button', { name: 'Read filters' }));
    expect(read).toHaveBeenCalledTimes(2);
});

test.each([401, 403, 404])(
    'an owned %s response removes private content even with alternate equivalent query encoding',
    async (status) => {
        await fixture();
        expect(
            invalid(`${location.origin}/teams/7/monitors?state=active&q=API+%2F+EU`, status)
                .defaultPrevented,
        ).toBe(true);
        expect(screen.queryByText('Prior private results')).not.toBeInTheDocument();
        expect(screen.getByText('Team data hidden')).toBeInTheDocument();
        expect(screen.queryByText('Private response diagnostics')).not.toBeInTheDocument();
    },
);

test('old callbacks cannot finish or refuse a newer selection', async () => {
    const { user, visits } = await fixture();
    act(() => {
        visits[0]?.onError?.({ q: 'Refused' });
        visits[0]?.onFinish?.({} as never);
    });
    await user.click(screen.getByRole('button', { name: 'Read another selection' }));
    act(() => {
        visits[0]?.onError?.({ q: 'Late refusal' });
        visits[0]?.onFinish?.({} as never);
    });
    expect(screen.getByText('Read pending')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

test('duplicate submission and disposal affect only the owned transport', async () => {
    const { read, visits, view } = await fixture();
    fireEvent.click(screen.getByRole('button', { name: 'Read filters' }));
    expect(read).toHaveBeenCalledOnce();
    const cancel = vi.fn();
    act(() => visits[0]?.onCancelToken?.({ cancel }));
    view.unmount();
    expect(cancel).toHaveBeenCalledOnce();
    expect(invalid(ownedUrl, 403).defaultPrevented).toBe(false);
});
