/** Manage a page's custom hostname, public DNS proof and expiring ownership verification. */
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../MutationFeedback';
import { formatInstant } from '../incidents/time';
import type { ManagedStatusDomain } from './types';

/** Owner/admin inputs, always checked again by the server. */
interface StatusDomainFormProps {
    /** Team-scoped page endpoint, before the domain suffix. */
    path: string;
    /** Current claim and live verification lease, or no claim. */
    domain: ManagedStatusDomain | null;
    /** Stable unique input identity for several pages on one screen. */
    pageId: number;
}

/** Keep the DNS claim editor independent of the page/component replacement form. */
export default function StatusDomainForm({ path, domain, pageId }: StatusDomainFormProps) {
    const claim = useForm({ domain: domain?.hostname ?? '' });
    useFormProtection(claim);
    const verify = useForm({});
    useFormProtection(verify);
    const remove = useForm({});
    useFormProtection(remove);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const pending = mutation.locked || guard.busy;
    const [baseline, setBaseline] = useState(domain?.hostname ?? '');
    const hostname = domain?.hostname ?? '';
    if (baseline !== hostname && !pending) {
        setBaseline(hostname);
        claim.setDefaults({ domain: hostname });
        if (claim.data.domain === baseline) claim.setData('domain', hostname);
    }
    const error =
        claim.errors.domain ?? (verify.errors as Record<string, string | undefined>).domain;

    /** @param event Explicit manager submission, preserving page scroll and CSRF handling. */
    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (pending) return;
        mutation.run(`${path}/domain`, (options) => claim.put(`${path}/domain`, options));
    }

    /** Recheck ownership or remove only the current server claim, without replaying either write. */
    function change(action: 'verify' | 'remove') {
        if (pending) return;
        if (
            action === 'remove' &&
            !window.confirm(
                'Remove this hostname? Its custom address stops serving the status page.',
            )
        )
            return;
        const url = action === 'verify' ? `${path}/domain/verify` : `${path}/domain`;
        mutation.run(url, (options) =>
            action === 'verify' ? verify.post(url, options) : remove.delete(url, options),
        );
    }

    return (
        <section aria-label="Custom domain" className="mt-4 ui-panel">
            <h3 className="font-medium">Custom domain</h3>
            <form onSubmit={save} className="mt-2 grid gap-2">
                <label htmlFor={`domain-${pageId}`}>Hostname</label>
                <input
                    disabled={pending}
                    id={`domain-${pageId}`}
                    value={claim.data.domain}
                    onChange={(event) => claim.setData('domain', event.target.value)}
                    placeholder="status.your-company.com"
                    autoComplete="off"
                    maxLength={1024}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `domain-error-${pageId}` : undefined}
                    className="ui-input"
                />
                {error && (
                    <p id={`domain-error-${pageId}`} role="alert">
                        {error}
                    </p>
                )}
                <button type="submit" disabled={pending} className="w-fit underline">
                    {claim.processing ? 'Saving domain…' : 'Save hostname'}
                </button>
            </form>
            {domain && (
                <div className="mt-3 grid gap-2 text-sm">
                    <p>
                        {domain.verifiedUntil ? (
                            <>
                                Ownership verified until{' '}
                                {formatInstant(domain.verifiedUntil, 'Unknown')}.
                            </>
                        ) : (
                            'Ownership not verified. This hostname does not serve the page.'
                        )}
                    </p>
                    <p>Publish and keep this TXT record:</p>
                    <dl className="break-all">
                        <dt>Record name</dt>
                        <dd>
                            <code>{domain.proofName}</code>
                        </dd>
                        <dt>Record value</dt>
                        <dd>
                            <code>{domain.proofValue}</code>
                        </dd>
                    </dl>
                    <p>
                        Point this hostname to the server address provided by your Signalbox
                        operator. HTTPS must be available before sharing this address.
                    </p>
                    <div className="flex flex-wrap gap-4">
                        <button
                            type="button"
                            disabled={pending}
                            onClick={() => change('verify')}
                            className="ui-link"
                        >
                            {verify.processing ? 'Checking DNS…' : 'Verify DNS ownership'}
                        </button>
                        <button
                            type="button"
                            disabled={pending}
                            onClick={() => change('remove')}
                            className="text-red-200 underline"
                        >
                            Remove hostname
                        </button>
                    </div>
                </div>
            )}
            {(remove.errors as Record<string, string | undefined>).domain && (
                <p role="alert">{(remove.errors as Record<string, string | undefined>).domain}</p>
            )}
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path.split('/status-pages')[0] + '/status-pages'}
                success="Domain change accepted"
                onReviewed={mutation.acknowledge}
            />
        </section>
    );
}
