/** Manage optional authenticator security without putting secrets into Inertia props or history. */
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthLayout, { type AuthProps } from '../../components/auth/AuthLayout';

/** Non-secret enrollment status from the authenticated account page. */
interface SecurityProps extends AuthProps {
    /** Whether an authenticator has completed setup confirmation. */
    twoFactorEnabled: boolean;
    /** Whether a generated secret still awaits confirmation. */
    twoFactorPending: boolean;
}

/** Ephemeral setup values retained only in this mounted component. */
interface Setup {
    /** Server-generated QR SVG displayed through an isolated image resource. */
    svg: string;
    /** Manual enrollment key shown only after a recent password check. */
    secretKey: string;
}

/** Retrieve a sensitive JSON response without caching or persisting it in navigation state. */
async function readSecurity(path: string): Promise<unknown> {
    /** Same-origin response; no external QR or authenticator service is contacted. */
    const response = await fetch(path, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
    });
    if (response.status === 423 || response.status === 401 || response.redirected) {
        router.visit('/account/security');
        throw new Error('Please confirm your password again.');
    }
    if (!response.ok) throw new Error('Unable to load security details. Please try again.');
    return response.json() as Promise<unknown>;
}

/** Enable, confirm and disable optional 2FA, with explicit recovery-code access. */
export default function Security() {
    /** Enrollment flags contain no recoverable credential material. */
    const { twoFactorEnabled, twoFactorPending } = usePage<SecurityProps>().props;
    /** Pending authenticator setup, never remembered by Inertia. */
    const [setup, setSetup] = useState<Setup | null>(null);
    /** Recovery codes fetched only on an explicit user request. */
    const [codes, setCodes] = useState<string[]>([]);
    /** Local loading state for the sensitive GET endpoints. */
    const [loading, setLoading] = useState(false);
    /** Safe local request error; raw responses are never displayed. */
    const [error, setError] = useState('');
    /** CSRF-protected transition requests and field validation. */
    const form = useForm({ code: '' });

    /** Fetch and validate the two independent setup response shapes. */
    async function loadSetup() {
        setLoading(true);
        setError('');
        try {
            /** Explicit QR and manual-key responses from the authenticated server. */
            const [qr, key] = await Promise.all([
                readSecurity('/user/two-factor-qr-code'),
                readSecurity('/user/two-factor-secret-key'),
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
            )
                throw new Error('Invalid setup response.');
            setSetup({ svg: qr.svg, secretKey: key.secretKey });
        } catch {
            setError(
                'Unable to load authenticator setup. Please try again or confirm your password again.',
            );
        } finally {
            setLoading(false);
        }
    }

    /** Fetch replacement or existing recovery codes into ephemeral component state. */
    async function loadCodes() {
        setLoading(true);
        setError('');
        setCodes([]);
        try {
            /** Unknown server payload checked before showing any credential value. */
            const body = await readSecurity('/user/two-factor-recovery-codes');
            if (!Array.isArray(body)) throw new Error('Invalid recovery response.');
            /** Only validated string values may enter the rendered recovery list. */
            const values = (body as unknown[]).filter(
                (value): value is string => typeof value === 'string',
            );
            if (values.length !== body.length) throw new Error('Invalid recovery response.');
            setCodes(values);
        } catch {
            setError(
                'Unable to load recovery codes. Please try again or confirm your password again.',
            );
        } finally {
            setLoading(false);
        }
    }

    /** Clear all secret display state after enrollment is confirmed or disabled. */
    function clearSecrets() {
        setSetup(null);
        setCodes([]);
        setError('');
        form.reset('code');
    }

    /** Confirm the first authenticator code before considering 2FA enabled. */
    function confirmSetup(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/user/confirmed-two-factor-authentication', {
            errorBag: 'confirmTwoFactorAuthentication',
            onSuccess: clearSecrets,
            onFinish: () => form.reset('code'),
        });
    }

    return (
        <AuthLayout
            title="Account security"
            description="Protect your account with an authenticator app and single-use recovery codes."
        >
            <p role="status">
                {twoFactorEnabled
                    ? 'Two-factor authentication is enabled.'
                    : twoFactorPending
                      ? 'Setup is pending. Confirm a code to enable two-factor authentication.'
                      : 'Two-factor authentication is not enabled.'}
            </p>
            {error && (
                <p role="alert" className="mt-4 text-red-300">
                    {error}
                </p>
            )}
            {loading && (
                <p role="status" className="mt-4">
                    Loading security details…
                </p>
            )}
            <div className="mt-6 space-y-5">
                {!twoFactorEnabled && !twoFactorPending && (
                    <button
                        disabled={form.processing || loading}
                        onClick={() =>
                            form.post('/user/two-factor-authentication', {
                                onSuccess: () => {
                                    void loadSetup();
                                },
                            })
                        }
                        className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                    >
                        Enable two-factor authentication
                    </button>
                )}
                {twoFactorPending && (
                    <>
                        <button
                            disabled={form.processing || loading}
                            onClick={() => {
                                void loadSetup();
                            }}
                            className="text-emerald-300"
                        >
                            Show authenticator setup
                        </button>
                        {setup && (
                            <div className="space-y-3">
                                <img
                                    src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(setup.svg)}`}
                                    alt="Scan this QR code with your authenticator app"
                                    width="192"
                                    height="192"
                                    className="bg-white p-2"
                                />
                                <p>Or enter this setup key manually:</p>
                                <code className="block break-all rounded bg-slate-900 p-3">
                                    {setup.secretKey}
                                </code>
                            </div>
                        )}
                        <form onSubmit={confirmSetup} className="space-y-3">
                            <label htmlFor="code" className="block">
                                Authentication code
                            </label>
                            <input
                                id="code"
                                name="code"
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value)}
                                autoComplete="one-time-code"
                                inputMode="numeric"
                                required
                                aria-invalid={Boolean(form.errors.code)}
                                aria-describedby={form.errors.code ? 'code-error' : undefined}
                                className="w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400"
                            />
                            {form.errors.code && (
                                <p id="code-error" role="alert" className="text-red-300">
                                    {form.errors.code}
                                </p>
                            )}
                            <button
                                disabled={form.processing || loading}
                                className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                            >
                                Confirm authenticator
                            </button>
                        </form>
                    </>
                )}
                {twoFactorEnabled && (
                    <>
                        <div className="flex flex-wrap gap-5 text-emerald-300">
                            <button
                                disabled={form.processing || loading}
                                onClick={() => {
                                    void loadCodes();
                                }}
                            >
                                Show recovery codes
                            </button>
                            <button
                                disabled={form.processing || loading}
                                onClick={() => {
                                    setCodes([]);
                                    form.post('/user/two-factor-recovery-codes', {
                                        onSuccess: () => {
                                            void loadCodes();
                                        },
                                    });
                                }}
                            >
                                Regenerate recovery codes
                            </button>
                            <button onClick={() => setCodes([])}>Hide recovery codes</button>
                        </div>
                        {codes.length > 0 && (
                            <section
                                aria-label="Recovery codes"
                                className="rounded border border-slate-700 p-4"
                            >
                                <p>
                                    Save these codes somewhere safe. Each code can be used once.
                                    Regenerating invalidates the previous set.
                                </p>
                                <ul className="mt-3 font-mono">
                                    {codes.map((code) => (
                                        <li key={code}>{code}</li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </>
                )}
                {(twoFactorEnabled || twoFactorPending) && (
                    <button
                        disabled={form.processing || loading}
                        onClick={() =>
                            form.delete('/user/two-factor-authentication', {
                                onSuccess: clearSecrets,
                            })
                        }
                        className="text-red-300"
                    >
                        {twoFactorEnabled
                            ? 'Disable two-factor authentication'
                            : 'Cancel authenticator setup'}
                    </button>
                )}
            </div>
            <Link href="/account" className="mt-8 inline-block text-emerald-300">
                Back to account
            </Link>
        </AuthLayout>
    );
}
