/** List the notification deliveries recorded for one incident. */
import DataTable from '../ui/DataTable';
import Badge from '../ui/Badge';
import { formatInstant } from '../incidents/time';
import { deliveryKindLabel, deliveryStatusLabel } from './labels';
import type { DeliveryView } from './types';

/** Log inputs. */
interface DeliveryLogProps {
    /** Deliveries, oldest first. */
    deliveries: DeliveryView[];
}

/** Responses are redacted summaries; a deleted channel keeps its row with only the family shown. */
export default function DeliveryLog({ deliveries }: DeliveryLogProps) {
    return (
        <section aria-labelledby="deliveries-heading" className="mt-8">
            <h2 id="deliveries-heading" className="mb-2 text-lg font-semibold">
                Notification deliveries
            </h2>
            <p className="ui-help">
                Recorded delivery attempts and redacted outcomes. Pending, failed or suppressed rows
                do not confirm delivery; sent records provider acceptance, not recipient reading.
                Times use your local time.
            </p>
            {deliveries.length === 0 ? (
                <p className="text-slate-300">
                    No notification has been recorded for this incident.
                </p>
            ) : (
                <DataTable caption="Notification deliveries, oldest first">
                    <thead>
                        <tr className="border-b border-slate-700">
                            <th scope="col" className="py-2">
                                Channel
                            </th>
                            <th scope="col">Reason</th>
                            <th scope="col">Status</th>
                            <th scope="col">Attempts</th>
                            <th scope="col">Sent</th>
                            <th scope="col">Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        {deliveries.map((delivery) => (
                            <tr key={delivery.id} className="border-b border-slate-800">
                                <th scope="row" className="py-2 font-medium">
                                    {delivery.channelName ??
                                        `Deleted ${delivery.channelType} channel`}
                                </th>
                                <td>
                                    {deliveryKindLabel(delivery.kind)}
                                    {delivery.stepIndex === null
                                        ? ''
                                        : ` · step ${delivery.stepIndex + 1}`}
                                </td>
                                <td>
                                    <Badge
                                        tone={delivery.status === 'sent' ? 'success' : 'neutral'}
                                    >
                                        {deliveryStatusLabel(delivery.status)}
                                    </Badge>
                                </td>
                                <td>{delivery.attempts}</td>
                                <td>{formatInstant(delivery.sentAt, 'Not recorded')}</td>
                                <td>{delivery.response ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </DataTable>
            )}
        </section>
    );
}
