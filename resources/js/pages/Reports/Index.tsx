/** Offer date-range PDF exports within the authorized team's workspace. */
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../components/app/AppLayout';
import ReportDownload, { type ReportCalendar } from '../../components/dashboard/ReportDownload';
import type { AuthProps } from '../../components/auth/AuthLayout';

/** Minimal member-safe calendar projection. */
interface ReportsProps extends AuthProps {
    /** Authorized route identity. */
    team: { id: number; name: string };
    /** Team IANA timezone. */
    reportTimezone: string;
    /** Complete selectable days and initial dates. */
    reportCalendar: ReportCalendar;
}

/** Own the download state inside the removable tenant boundary. */
function Content({ actionsTarget }: { actionsTarget: HTMLElement | null }) {
    const { team, reportTimezone, reportCalendar, errors } = usePage<ReportsProps>().props;
    return (
        <div className="ui-panel">
            <ReportDownload
                key={`${team.id}:${reportTimezone}:${reportCalendar.min}`}
                teamId={team.id}
                timezone={reportTimezone}
                calendar={reportCalendar}
                actionsTarget={actionsTarget}
                errors={errors}
            />
        </div>
    );
}

/** Place the authorized download action beside the page heading. */
export default function Reports() {
    const { team } = usePage<ReportsProps>().props;
    const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
    return (
        <AppLayout
            title="Reports"
            team={team}
            section="reports"
            actions={<div ref={setActionsTarget} className="contents" />}
        >
            <Content actionsTarget={actionsTarget} />
        </AppLayout>
    );
}
