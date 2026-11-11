/** Show team maintenance windows and enter the existing monitor-scoped management workflow. */
import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../components/app/AppLayout';
import DataTable from '../../components/ui/DataTable';
import OperationalList, {
    type OperationalPageProps,
} from '../../components/operations/OperationalList';
import { formatInstant } from '../../components/incidents/time';
import { phaseLabel } from '../../components/incidents/labels';
import type { MaintenanceWindowView } from '../../components/incidents/types';

/** Safe window row, extended only with its actual monitor identity. */
type WindowRow = MaintenanceWindowView & { monitor: { id: number; name: string } };

/** Keep list state inside the removable team boundary; mutation policies remain on monitor actions. */
function PageContent() {
    const props = usePage<OperationalPageProps<WindowRow>>().props;
    const { team, listing } = props;
    return (
        <>
            <p className="ui-description mb-4">
                Maintenance prevents new incidents while a window is in progress. Times use your
                browser&apos;s timezone. Open a monitor to schedule or manage its windows; viewers
                can inspect them.
            </p>
            <div className="ui-actions mb-4">
                <Link className="ui-link" href={`/teams/${team.id}/monitors`}>
                    Choose a monitor to schedule maintenance
                </Link>
            </div>
            <OperationalList {...props} path={`/teams/${team.id}/maintenance`}>
                {listing.data.length === 0 ? (
                    <p className="ui-panel">
                        No maintenance windows on this page. Adjust filters or choose a monitor to
                        schedule one.
                    </p>
                ) : (
                    <DataTable caption="Team maintenance, newest start first">
                        <thead>
                            <tr>
                                <th scope="col">Monitor</th>
                                <th scope="col">State</th>
                                <th scope="col">Start (local)</th>
                                <th scope="col">End (local)</th>
                                <th scope="col">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            {listing.data.map((item) => (
                                <tr key={item.id}>
                                    <th scope="row">
                                        <Link
                                            className="ui-link"
                                            href={`/teams/${team.id}/monitors/${item.monitor.id}#maintenance-heading`}
                                        >
                                            {item.monitor.name}
                                        </Link>
                                    </th>
                                    <td>{phaseLabel(item.phase)}</td>
                                    <td>{formatInstant(item.startsAt, 'Unknown')}</td>
                                    <td>{formatInstant(item.endsAt, 'Unknown')}</td>
                                    <td>{item.reason}</td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
            </OperationalList>
        </>
    );
}

/** Mount only authorized team content. */
export default function Page() {
    const { team } = usePage<OperationalPageProps<WindowRow>>().props;
    return (
        <AppLayout
            team={team}
            section="maintenance"
            title="Maintenance"
            description={`Maintenance windows of ${team.name}.`}
        >
            <PageContent />
        </AppLayout>
    );
}
