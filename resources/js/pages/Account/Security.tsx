/** Manage authenticator security while keeping credentials in removable, ephemeral component state. */
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useContext, useEffect, useRef, useState, type FormEvent } from 'react';
import {
    useNavigationGuard,
    useLeaveProtection,
    useFormProtection,
} from '../../components/app/NavigationGuard';
import { TeamAccessContext } from '../../components/app/TeamAccessContext';
import type { AuthProps } from '../../components/auth/AuthLayout';
import PrivateContextLayout from '../../components/app/PrivateContextLayout';
import Panel from '../../components/ui/Panel';
import Button from '../../components/ui/Button';
import MutationFeedback from '../../components/MutationFeedback';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';

/** Non-secret enrollment flags only; setup keys and recovery codes are never page props. */
interface SecurityProps extends AuthProps {
    /** Whether enrollment was confirmed with an authenticator code. */
    twoFactorEnabled: boolean;
    /** Whether an unconfirmed secret exists on the server. */
    twoFactorPending: boolean;
}

/** Validated setup responses kept only while this authorized page stays mounted. */
interface Setup {
    /** SVG used as an isolated image source, never injected as HTML. */
    svg: string;
    /** Manual enrollment key returned by a recent-password-protected endpoint. */
    secretKey: string;
}

/** Available account transitions; these are never replayed automatically. */
type SecurityChange = 'enable' | 'confirm' | 'regenerate' | 'disable';

/** Map explicit user choices to existing Fortify endpoints without adding a transport. */
const ENDPOINTS: Record<SecurityChange, string> = {
    enable: '/user/two-factor-authentication',
    confirm: '/user/confirmed-two-factor-authentication',
    regenerate: '/user/two-factor-recovery-codes',
    disable: '/user/two-factor-authentication',
};

/** Keep all hooks and credentials below the account layout's removable identity boundary. */
function SecurityContent() {
    const { twoFactorEnabled, twoFactorPending } = usePage<SecurityProps>().props;
    const guard = useNavigationGuard();
    const deny = useContext(TeamAccessContext);
    const mutation = useOwnedMutation();
    const [setup, setSetup] = useState<Setup | null>(null);
    const [codes, setCodes] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const request = useRef<AbortController | null>(null);
    const reconfirming = useRef(false);
    const form = useForm({ code: '' });
    useFormProtection(form);
    useLeaveProtection(false, loading);
    const blocked = mutation.locked || guard.busy || loading;

    /** Cancel owned reads on identity changes, access loss or departure; late JSON cannot reveal credentials. */
    useEffect(() => () => request.current?.abort(), []);

    /** Erase all visible credentials before a state-changing operation or access recovery. */
    function clearSecrets() {
        setSetup(null);
        setCodes([]);
        setError('');
        form.reset('code');
    }

    /** Hiding credentials also cancels a pending reveal, so a late response cannot show them again. */
    function hideSecrets() {
        request.current?.abort();
        request.current = null;
        setLoading(false);
        setSetup(null);
        setCodes([]);
    }

    /**
     * @param path Existing same-origin credential resource.
     * @param operation Owned read shared by paired setup requests.
     * @returns Validated later by the caller; no body enters navigation/history state.
     */
    async function read(path: string, operation: AbortController): Promise<unknown> {
        const response = await fetch(path, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: operation.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (operation.signal.aborted) throw new Error('Disposed credential read.');
        if ([401, 403, 404].includes(response.status)) {
            clearSecrets();
            deny();
            throw new Error('Account access lost.');
        }
        if (response.status === 423 || response.redirected) {
            clearSecrets();
            if (!reconfirming.current) {
                reconfirming.current = true;
                guard.runAuthorizedNavigation(() => router.visit('/account/security'));
            }
            throw new Error('Recent password confirmation required.');
        }
        if (!response.ok) throw new Error('Credential read refused.');
        return response.json() as Promise<unknown>;
    }

    /**
     * @param kind Explicitly requested sensitive resource; setup performs two paired AJAX GETs.
     * @returns Completion after validation, safe failure or disposal. Reads are single-flight and cancellable.
     */
    async function load(kind: 'setup' | 'codes'): Promise<void> {
        if (request.current) return;
        const operation = new AbortController();
        request.current = operation;
        setSetup(null);
        setCodes([]);
        setError('');
        setLoading(true);
        try {
            if (kind === 'setup') {
                const [qr, key] = await Promise.all([
                    read('/user/two-factor-qr-code', operation),
                    read('/user/two-factor-secret-key', operation),
                ]);
                if (
                    typeof qr !== 'object' ||
                    qr === null ||
                    !('svg' in qr) ||
                    typeof qr.svg !== 'string' ||
                    typeof key !== 'object' ||
                    key === null ||
                    !('secretKey' in key) ||
                    typeof key.secretKey !== 'string'
                ) {
                    throw new Error('Invalid setup response.');
                }
                if (!operation.signal.aborted) setSetup({ svg: qr.svg, secretKey: key.secretKey });
            } else {
                const body = await read('/user/two-factor-recovery-codes', operation);
                if (
                    !Array.isArray(body) ||
                    body.length > 100 ||
                    !body.every(
                        (value: unknown) =>
                            typeof value === 'string' && value.length > 0 && value.length <= 256,
                    )
                ) {
                    throw new Error('Invalid recovery response.');
                }
                if (!operation.signal.aborted) setCodes(body);
            }
        } catch {
            if (!operation.signal.aborted)
                setError(
                    kind === 'setup'
                        ? 'Unable to load authenticator setup. Please try again or confirm your password again.'
                        : 'Unable to load recovery codes. Please try again or confirm your password again.',
                );
        } finally {
            if (request.current === operation) request.current = null;
            if (!operation.signal.aborted) setLoading(false);
            // A failed paired setup read must also dispose its still-pending sibling.
            operation.abort();
        }
    }

    /** Submit an explicitly selected transition once; uncertain outcomes require saved-state review. */
    function change(action: SecurityChange) {
        if (blocked) return;
        if (
            action === 'regenerate' &&
            !window.confirm('Regenerate recovery codes? All previous codes stop working.')
        )
            return;
        if (
            action === 'disable' &&
            !window.confirm(
                twoFactorEnabled
                    ? 'Disable two-factor authentication for your account?'
                    : 'Cancel this authenticator setup?',
            )
        )
            return;
        if (action !== 'confirm') clearSecrets();
        const path = ENDPOINTS[action];
        mutation.run(
            path,
            (options) => {
                if (action === 'disable') form.delete(path, options);
                else
                    form.post(path, {
                        ...options,
                        ...(action === 'confirm'
                            ? { errorBag: 'confirmTwoFactorAuthentication' }
                            : {}),
                    });
            },
            undefined,
            () => {
                clearSecrets();
                // Credential reads follow only confirmed acceptance, never transport recovery or a retry timer.
                if (action === 'enable') void load('setup');
                if (action === 'regenerate') void load('codes');
            },
        );
    }

    /** Keep the authenticator code on a refused confirmation; clear it only after acceptance. */
    function confirmSetup(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        change('confirm');
    }

    return (
        <>
            <Panel
                title="Authenticator"
                description="Setup and recovery details are shown only on request. They are not stored in page history."
            >
                <p role="status">
                    {twoFactorEnabled
                        ? 'Two-factor authentication is enabled.'
                        : twoFactorPending
                          ? 'Setup is pending. Confirm a code to enable two-factor authentication.'
                          : 'Two-factor authentication is not enabled.'}
                </p>
                {error && (
                    <p role="alert" className="ui-field-error">
                        {error}
                    </p>
                )}
                {loading && <p role="status">Loading security details…</p>}
                <div className="grid gap-4 mt-4">
                    {!twoFactorEnabled && !twoFactorPending && (
                        <Button disabled={blocked} onClick={() => change('enable')}>
                            Enable two-factor authentication
                        </Button>
                    )}
                    {twoFactorPending && (
                        <>
                            <Button
                                variant="secondary"
                                disabled={blocked}
                                onClick={() => void load('setup')}
                            >
                                Show authenticator setup
                            </Button>
                            {setup && (
                                <div className="grid gap-3">
                                    <img
                                        src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(setup.svg)}`}
                                        alt="Scan this QR code with your authenticator app"
                                        width="192"
                                        height="192"
                                        className="max-w-full bg-white p-2"
                                    />
                                    <p>Or enter this setup key manually:</p>
                                    <code className="block break-all ui-panel">
                                        {setup.secretKey}
                                    </code>
                                    <Button variant="secondary" onClick={hideSecrets}>
                                        Hide authenticator setup
                                    </Button>
                                </div>
                            )}
                            <form onSubmit={confirmSetup} noValidate>
                                <fieldset disabled={blocked} className="grid min-w-0 gap-3">
                                    <label htmlFor="security-code" className="ui-label">
                                        Authentication code
                                    </label>
                                    <input
                                        id="security-code"
                                        className="ui-input"
                                        value={form.data.code}
                                        onChange={(event) =>
                                            form.setData('code', event.target.value)
                                        }
                                        autoComplete="one-time-code"
                                        inputMode="numeric"
                                        maxLength={6}
                                        required
                                        aria-invalid={Boolean(form.errors.code)}
                                        aria-describedby={
                                            form.errors.code ? 'security-code-error' : undefined
                                        }
                                    />
                                    {form.errors.code && (
                                        <p
                                            id="security-code-error"
                                            role="alert"
                                            className="ui-field-error"
                                        >
                                            {form.errors.code}
                                        </p>
                                    )}
                                    <Button type="submit">Confirm authenticator</Button>
                                </fieldset>
                            </form>
                        </>
                    )}
                    {twoFactorEnabled && (
                        <>
                            <div className="ui-actions">
                                <Button
                                    variant="secondary"
                                    disabled={blocked}
                                    onClick={() => void load('codes')}
                                >
                                    Show recovery codes
                                </Button>
                                <Button
                                    variant="secondary"
                                    disabled={blocked}
                                    onClick={() => change('regenerate')}
                                >
                                    Regenerate recovery codes
                                </Button>
                                <Button variant="secondary" onClick={hideSecrets}>
                                    Hide recovery codes
                                </Button>
                            </div>
                            {codes.length > 0 && (
                                <section aria-label="Recovery codes" className="ui-panel">
                                    <p>
                                        Save these codes somewhere safe. Each code can be used once.
                                        Regenerating invalidates the previous set.
                                    </p>
                                    <ul className="mt-3 font-mono break-all">
                                        {codes.map((code) => (
                                            <li key={code}>{code}</li>
                                        ))}
                                    </ul>
                                </section>
                            )}
                        </>
                    )}
                    {(twoFactorEnabled || twoFactorPending) && (
                        <Button
                            variant="danger"
                            disabled={blocked}
                            onClick={() => change('disable')}
                        >
                            {twoFactorEnabled
                                ? 'Disable two-factor authentication'
                                : 'Cancel authenticator setup'}
                        </Button>
                    )}
                </div>
            </Panel>
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl="/account/security"
                success="Security change accepted"
                onReviewed={mutation.acknowledge}
            />
            <Link href="/account" className="ui-link mt-6 inline-block">
                Back to account
            </Link>
        </>
    );
}

/** Only non-secret flags are read above the removable account shell. */
export default function Security() {
    const { twoFactorEnabled, twoFactorPending } = usePage<SecurityProps>().props;
    return (
        <PrivateContextLayout
            title="Account security"
            description="Protect your account with an authenticator app and single-use recovery codes."
        >
            <SecurityContent key={`${twoFactorEnabled}:${twoFactorPending}`} />
        </PrivateContextLayout>
    );
}
