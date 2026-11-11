/** Show paginated redacted delivery outcomes with an entry to each actual incident. */
import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../components/app/AppLayout';
import DataTable from '../../components/ui/DataTable';
import OperationalList, {
    type OperationalPageProps,
} from '../../components/operations/OperationalList';
import { formatInstant } from '../../components/incidents/time';
import { deliveryKindLabel, deliveryStatusLabel } from '../../components/notifications/labels';
import type { DeliveryView } from '../../components/notifications/types';

/** Delivery projection includes no recipient payload, destination, dedupe key or claim metadata. */
type DeliveryRow = DeliveryView & { incidentId: number; monitor: { id: number; name: string } };

/** Explicit refresh updates this snapshot without a new subscription or replay control. */
function PageContent() {
    const props = usePage<OperationalPageProps<DeliveryRow>>().props;
    const { team, listing } = props;
    return (
        <>
            <div className="ui-actions mb-4">
                <Link className="ui-link" href={`/teams/${team.id}/notifications`}>
                    Channels and escalation policies
                </Link>
            </div>
            <p className="ui-description mb-4">
                Sent records provider acceptance, not recipient reading. Pending, failed or
                suppressed rows do not confirm delivery. Times use your local time; deleted channels
                keep their history.
            </p>
            <OperationalList {...props} path={`/teams/${team.id}/notifications/deliveries`}>
                {listing.data.length === 0 ? (
                    <p className="ui-panel">
                        No deliveries on this page. Adjust filters, or review channels and
                        escalation policies. Creating a channel does not send an incident
                        notification.
                    </p>
                ) : (
                    <DataTable caption="Notification deliveries, newest first">
                        <thead>
                            <tr>
                                <th scope="col">Incident / monitor</th>
                                <th scope="col">Channel</th>
                                <th scope="col">Reason</th>
                                <th scope="col">Status</th>
                                <th scope="col">Attempts</th>
                                <th scope="col">Recorded (local)</th>
                                <th scope="col">Sent (local)</th>
                                <th scope="col">Response</th>
                            </tr>
                        </thead>
                        <tbody>
                            {listing.data.map((item) => (
                                <tr key={item.id}>
                                    <th scope="row">
                                        <Link
                                            className="ui-link"
                                            href={`/teams/${team.id}/incidents/${item.incidentId}`}
                                        >
                                            Incident {item.incidentId} · {item.monitor.name}
                                        </Link>
                                    </th>
                                    <td>
                                        {item.channelName ?? `Deleted ${item.channelType} channel`}
                                    </td>
                                    <td>
                                        {deliveryKindLabel(item.kind)}
                                        {item.stepIndex === null
                                            ? ''
                                            : ` · step ${item.stepIndex + 1}`}
                                    </td>
                                    <td>{deliveryStatusLabel(item.status)}</td>
                                    <td>{item.attempts}</td>
                                    <td>{formatInstant(item.createdAt, 'Not recorded')}</td>
                                    <td>{formatInstant(item.sentAt, 'Not recorded')}</td>
                                    <td>{item.response ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
            </OperationalList>
        </>
    );
}

/** Mount the delivery list in the Notifications navigation context. */
export default function Page() {
    const { team } = usePage<OperationalPageProps<DeliveryRow>>().props;
    return (
        <AppLayout
            team={team}
            section="notifications"
            title="Notification deliveries"
            description="Recorded attempts and redacted outcomes."
            ancestors={[{ label: 'Notifications', href: `/teams/${team.id}/notifications` }]}
        >
            <PageContent />
        </AppLayout>
    );
}
