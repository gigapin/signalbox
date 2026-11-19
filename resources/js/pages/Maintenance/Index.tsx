/** Show team maintenance windows and enter the existing monitor-scoped management workflow. */
import { useState } from 'react';
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

/** Maintenance filters include every safe monitor identity of the authorized team. */
interface MaintenanceProps extends OperationalPageProps<WindowRow> {
    /** Safe names and IDs, including monitors without any maintenance windows. */
    monitorOptions: { id: number; name: string }[];
}

/** Authorized heading destination for the list-owned refresh action. */
interface PageContentProps {
    /** Null until the heading container mounts. */
    refreshTarget: HTMLDivElement | null;
}

/** Keep list state inside the removable team boundary; mutation policies remain on monitor actions. */
function PageContent({ refreshTarget }: PageContentProps) {
    const props = usePage<MaintenanceProps>().props;
    const { team, listing } = props;
    return (
        <>
            <OperationalList
                {...props}
                path={`/teams/${team.id}/maintenance`}
                refreshTarget={refreshTarget}
                compact
            >
                {listing.data.length === 0 ? (
                    <p className="ui-panel">
                        No maintenance windows on this page. Adjust filters or choose a monitor to
                        schedule one.
                    </p>
                ) : (
                    <DataTable caption="Maintenance" compact>
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
    const { team } = usePage<MaintenanceProps>().props;
    const [refreshTarget, setRefreshTarget] = useState<HTMLDivElement | null>(null);
    return (
        <AppLayout
            team={team}
            section="maintenance"
            title="Maintenance"
            actions={<div ref={setRefreshTarget} className="contents" />}
        >
            <PageContent refreshTarget={refreshTarget} />
        </AppLayout>
    );
}
