/** Present bounded operator audit history using only known override snapshot fields. */
import type { ReactNode } from 'react';
import Panel from '../ui/Panel';
import { planLabel, utcLabel } from './labels';
import type { AdminAuditEvent } from './types';

/** Format a known expiry without coercing arbitrary nested audit data into visible text. */
function expiryLabel(value: unknown): string {
    if (value === null) return 'No expiry';
    if (typeof value === 'string' && Number.isFinite(Date.parse(value))) return utcLabel(value);
    return 'Not recorded';
}

/** Render only the documented audit allowlist; arbitrary change keys and objects are never stringified. */
function snapshot(value: unknown): ReactNode {
    if (value === null) return 'No override';
    if (
        typeof value !== 'object' ||
        !('kind' in value) ||
        typeof value.kind !== 'string' ||
        !['unlimited', 'comped'].includes(value.kind)
    )
        return 'Not recorded';
    const plan =
        'plan' in value &&
        typeof value.plan === 'string' &&
        ['pro', 'business'].includes(value.plan)
            ? planLabel(value.plan)
            : 'Not applicable';
    const expiry = 'expires_at' in value ? value.expires_at : undefined;
    return (
        <dl className="grid gap-1">
            <div>
                <dt className="ui-help">Kind</dt>
                <dd>{value.kind === 'unlimited' ? 'Unlimited testing' : 'Granted plan'}</dd>
            </div>
            <div>
                <dt className="ui-help">Plan</dt>
                <dd>{plan}</dd>
            </div>
            <div>
                <dt className="ui-help">Expires</dt>
                <dd>{expiryLabel(expiry)}</dd>
            </div>
        </dl>
    );
}

/** Read-only history; the server bounds it to the latest fifty events and owns audit writes. */
export default function AuditHistory({ events }: { events: AdminAuditEvent[] }) {
    return (
        <Panel
            title="History"
            description="Latest 50 recorded operator events, newest first. Times are UTC; historical snapshots do not imply the override is still active."
        >
            {events.length === 0 ? (
                <p>No operator action recorded for this team.</p>
            ) : (
                <ol className="grid gap-4">
                    {events.map((event) => (
                        <li key={event.id} className="ui-panel">
                            <p>
                                <span className="font-semibold">{event.action}</span> by{' '}
                                {event.actor ?? 'command line or deleted account'} on{' '}
                                {utcLabel(event.createdAt)}
                            </p>
                            {event.reason !== null && (
                                <p className="ui-description">Reason: {event.reason}</p>
                            )}
                            <div className="ui-audit-snapshots mt-3">
                                <section aria-label="Before">
                                    <h3 className="font-semibold">Before</h3>
                                    {snapshot(event.changes.before)}
                                </section>
                                <section aria-label="After">
                                    <h3 className="font-semibold">After</h3>
                                    {snapshot(event.changes.after)}
                                </section>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </Panel>
    );
}
