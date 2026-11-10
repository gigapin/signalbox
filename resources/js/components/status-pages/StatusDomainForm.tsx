/** Manage a page's custom hostname, public DNS proof and expiring ownership verification. */
import { useFormProtection } from '../app/NavigationGuard';
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
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
    const pending = claim.processing || verify.processing || remove.processing;
    const error =
        claim.errors.domain ?? (verify.errors as Record<string, string | undefined>).domain;

    /** @param event Explicit manager submission, preserving page scroll and CSRF handling. */
    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        claim.put(`${path}/domain`, { preserveScroll: true });
    }

    return (
        <section aria-label="Custom domain" className="mt-4 rounded border border-slate-700 p-4">
            <h3 className="font-medium">Custom domain</h3>
            <form onSubmit={save} className="mt-2 grid gap-2">
                <label htmlFor={`domain-${pageId}`}>Hostname</label>
                <input
                    id={`domain-${pageId}`}
                    value={claim.data.domain}
                    onChange={(event) => claim.setData('domain', event.target.value)}
                    placeholder="status.your-company.com"
                    autoComplete="off"
                    maxLength={1024}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `domain-error-${pageId}` : undefined}
                    className="rounded border border-slate-600 bg-transparent p-2"
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
                        {domain.verifiedUntil
                            ? `Ownership verified until ${domain.verifiedUntil}.`
                            : 'Ownership not verified. This hostname does not serve the page.'}
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
                            onClick={() =>
                                verify.post(`${path}/domain/verify`, { preserveScroll: true })
                            }
                            className="underline"
                        >
                            {verify.processing ? 'Checking DNS…' : 'Verify DNS ownership'}
                        </button>
                        <button
                            type="button"
                            disabled={pending}
                            onClick={() =>
                                remove.delete(`${path}/domain`, { preserveScroll: true })
                            }
                            className="text-red-200 underline"
                        >
                            Remove hostname
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}
