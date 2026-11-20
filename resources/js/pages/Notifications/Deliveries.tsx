/** Show paginated redacted delivery outcomes with an entry to each actual incident. */
import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../components/app/AppLayout';
import DeliveryStatusBadge from '../../components/notifications/DeliveryStatusBadge';
import { useState } from 'react';
import OperationalList, {
    type OperationalPageProps,
} from '../../components/operations/OperationalList';
import { formatInstant } from '../../components/incidents/time';
import { deliveryKindLabel } from '../../components/notifications/labels';
import type { DeliveryView } from '../../components/notifications/types';

/** Delivery projection includes no recipient payload, destination, dedupe key or claim metadata. */
type DeliveryRow = DeliveryView & { incidentId: number; monitor: { id: number; name: string } };

/** Explicit refresh updates this snapshot without a new subscription or replay control. */
function PageContent({ actionsTarget }: { actionsTarget: HTMLElement | null }) {
    const props = usePage<OperationalPageProps<DeliveryRow>>().props;
    const { team, listing } = props;
    return (
        <>
            <OperationalList
                {...props}
                compact
                controlsTarget={actionsTarget}
                refreshTarget={actionsTarget}
                path={`/teams/${team.id}/notifications/deliveries`}
            >
                {listing.data.length === 0 ? (
                    <p className="ui-panel">No deliveries match this selection.</p>
                ) : (
                    <ul
                        aria-label="Notification deliveries, newest first"
                        className="ui-notification-list"
                    >
                        {listing.data.map((item) => (
                            <li key={item.id} className="ui-panel ui-notification-card">
                                <div className="ui-notification-card-heading">
                                    <h2 className="ui-notification-name">
                                        <Link
                                            className="ui-link"
                                            href={`/teams/${team.id}/incidents/${item.incidentId}`}
                                        >
                                            Incident {item.incidentId} · {item.monitor.name}
                                        </Link>
                                    </h2>
                                    <DeliveryStatusBadge status={item.status} />
                                </div>
                                <p className="ui-notification-primary">
                                    {item.channelName ?? `Deleted ${item.channelType} channel`}
                                </p>
                                <dl className="ui-notification-details">
                                    <div>
                                        <dt>Reason</dt>
                                        <dd>
                                            {deliveryKindLabel(item.kind)}
                                            {item.stepIndex === null
                                                ? ''
                                                : ` · step ${item.stepIndex + 1}`}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Attempts</dt>
                                        <dd>{item.attempts}</dd>
                                    </div>
                                    <div>
                                        <dt>Recorded (local)</dt>
                                        <dd>{formatInstant(item.createdAt, 'Not recorded')}</dd>
                                    </div>
                                    <div>
                                        <dt>Accepted by provider (local)</dt>
                                        <dd>{formatInstant(item.sentAt, 'Not recorded')}</dd>
                                    </div>
                                    <div>
                                        <dt>Response</dt>
                                        <dd>{item.response ?? '—'}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                )}
            </OperationalList>
        </>
    );
}

/** Mount the delivery list in the Notifications navigation context. */
export default function Page() {
    const { team } = usePage<OperationalPageProps<DeliveryRow>>().props;
    const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
    return (
        <AppLayout
            team={team}
            section="notifications"
            notificationSection="deliveries"
            title="Notification deliveries"
            actions={<div ref={setActionsTarget} className="contents" />}
            ancestors={[{ label: 'Notifications', href: `/teams/${team.id}/notifications` }]}
        >
            <PageContent actionsTarget={actionsTarget} />
        </AppLayout>
    );
}
