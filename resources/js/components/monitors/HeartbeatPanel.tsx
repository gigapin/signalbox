/** Manage one-time heartbeat credentials in ephemeral component state, outside Inertia props and history. */
import { useEffect, useRef, useState } from 'react';
import { csrfHeader } from '../csrf';
import type { HeartbeatView } from './types';

/** Private issuance data returned only by an explicit authorized mutation. */
interface IssuedCredential {
    /** Newly generated bearer token, never persisted by this component. */
    token: string;
    /** Secret-free public receiver URL. */
    endpoint: string;
}

/** Inputs contain only safe server projections and current management permissions. */
interface HeartbeatPanelProps {
    /** Tenant-bound monitor URL used for credential mutations. */
    path: string;
    /** Non-secret heartbeat status and receipt instants. */
    heartbeat: HeartbeatView;
    /** Whether the current actor may update this monitor. */
    canManage: boolean;
}

/**
 * Quote trusted response values safely for the displayed POSIX shell example.
 * @param value Server-issued credential or secret-free endpoint.
 * @returns One POSIX single-quoted argument, preserving embedded quotes.
 */
function shellQuote(value: string): string {
    return `'${value.replaceAll("'", "'\\''")}'`;
}

/** Generate, rotate, revoke, copy and hide secrets without storing them in navigation or browser storage. */
export default function HeartbeatPanel({ path, heartbeat, canManage }: HeartbeatPanelProps) {
    return (
        <HeartbeatControls
            key={`${path}:${heartbeat.configured}:${canManage}`}
            path={path}
            heartbeat={heartbeat}
            canManage={canManage}
        />
    );
}

/** Reset ephemeral secret state by remounting whenever the monitor or server-authorized permissions change. */
function HeartbeatControls({ path, heartbeat, canManage }: HeartbeatPanelProps) {
    const [credential, setCredential] = useState<IssuedCredential | null>(null);
    const [configured, setConfigured] = useState(heartbeat.configured);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const pending = useRef<AbortController | null>(null);

    /** Cancel pending issuance and forget all response data when changing monitors or leaving the page. */
    useEffect(() => {
        return () => pending.current?.abort();
    }, []);

    /**
     * Mutate via same-origin JSON with CSRF, keeping plaintext outside Inertia history.
     * @param method POST generates/rotates; DELETE immediately revokes the credential.
     * @returns Completion after response handling, cancellation or a safely displayed failure.
     */
    async function mutate(method: 'POST' | 'DELETE') {
        if (pending.current || !canManage) return;
        if (
            configured &&
            !window.confirm(
                method === 'POST'
                    ? 'Rotate this credential? The current credential will stop working immediately.'
                    : 'Revoke this credential? The configured job will no longer be able to send heartbeats.',
            )
        )
            return;

        setCredential(null);
        setError('');
        setNotice('');
        setBusy(true);
        const operation = new AbortController();
        pending.current = operation;

        try {
            const response = await fetch(`${path}/heartbeat-credential`, {
                method,
                credentials: 'same-origin',
                cache: 'no-store',
                signal: operation.signal,
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfHeader(
                        'Reload this page before changing the heartbeat credential.',
                    ),
                },
            });
            if (!response.ok || response.redirected)
                throw new Error(
                    'Unable to change the heartbeat credential. Reload the page and check your permissions.',
                );
            if (operation.signal.aborted) return;

            if (method === 'DELETE') {
                setConfigured(false);
                setNotice('Credential revoked.');
            } else {
                const body: unknown = await response.json();
                if (
                    typeof body !== 'object' ||
                    body === null ||
                    !('token' in body) ||
                    typeof body.token !== 'string' ||
                    !/^[a-f0-9]{64}$/.test(body.token) ||
                    !('endpoint' in body) ||
                    typeof body.endpoint !== 'string' ||
                    body.endpoint !== heartbeat.endpoint
                )
                    throw new Error(
                        'The credential response was invalid. Generate another credential.',
                    );
                if (operation.signal.aborted) return;
                setCredential({ token: body.token, endpoint: body.endpoint });
                setConfigured(true);
            }
        } catch {
            if (!operation.signal.aborted)
                setError(
                    'Unable to change the heartbeat credential. Reload the page and check your permissions.',
                );
        } finally {
            if (pending.current === operation) pending.current = null;
            if (!operation.signal.aborted) setBusy(false);
        }
    }

    /** Copy the current curl example only on an explicit user action, with an accessible failure message. */
    async function copy() {
        if (!credential) return;
        try {
            await navigator.clipboard.writeText(
                `curl --fail --request POST --header ${shellQuote(`Authorization: Bearer ${credential.token}`)} ${shellQuote(credential.endpoint)}`,
            );
            setNotice('Heartbeat command copied.');
        } catch {
            setError('Unable to copy. Select and copy the command manually.');
        }
    }

    return (
        <section
            aria-labelledby="heartbeat-heading"
            className="mt-8 rounded border border-slate-700 p-4"
        >
            <h2 id="heartbeat-heading" className="text-lg font-semibold">
                Heartbeat
            </h2>
            <p>Credential: {configured ? 'Configured' : 'Not configured'}</p>
            <p>
                Last heartbeat:{' '}
                {heartbeat.lastReceivedAt ? (
                    <time dateTime={heartbeat.lastReceivedAt}>
                        {new Date(heartbeat.lastReceivedAt).toLocaleString()}
                    </time>
                ) : (
                    'Never'
                )}
            </p>
            <p>
                Deadline:{' '}
                <time dateTime={heartbeat.dueAt}>{new Date(heartbeat.dueAt).toLocaleString()}</time>
            </p>
            <p className="mt-2 text-sm text-slate-400">
                Each heartbeat renews the deadline. Calls while paused or outside plan limits are
                acknowledged without recording. Resume starts a new window.
            </p>
            {canManage && (
                <div className="mt-4 flex flex-wrap gap-3">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void mutate('POST')}
                        className="rounded border px-3 py-2"
                    >
                        {configured ? 'Rotate credential' : 'Generate credential'}
                    </button>
                    {configured && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => void mutate('DELETE')}
                            className="rounded border px-3 py-2"
                        >
                            Revoke credential
                        </button>
                    )}
                </div>
            )}
            {credential && canManage && (
                <div className="mt-4">
                    <p>
                        Save this command now. The credential cannot be shown again after you hide
                        it or leave this page.
                    </p>
                    <pre className="my-3 overflow-auto whitespace-pre-wrap break-all">{`curl --fail --request POST --header ${shellQuote(`Authorization: Bearer ${credential.token}`)} ${shellQuote(credential.endpoint)}`}</pre>
                    <button
                        type="button"
                        onClick={() => void copy()}
                        className="mr-3 rounded border px-3 py-2"
                    >
                        Copy heartbeat command
                    </button>
                    <button
                        type="button"
                        onClick={() => setCredential(null)}
                        className="rounded border px-3 py-2"
                    >
                        Hide credential
                    </button>
                </div>
            )}
            {busy && <p role="status">Updating credential…</p>}
            {notice && <p role="status">{notice}</p>}
            {error && (
                <p role="alert" className="mt-3 text-red-300">
                    {error}
                </p>
            )}
        </section>
    );
}
