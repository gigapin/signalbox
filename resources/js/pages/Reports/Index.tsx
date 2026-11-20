/** Offer monthly PDF exports within the authorized team's standard workspace. */
import { usePage } from '@inertiajs/react';
import AppLayout from '../../components/app/AppLayout';
import MonthlyReportDownload from '../../components/dashboard/MonthlyReportDownload';
import type { AuthProps } from '../../components/auth/AuthLayout';

/** Member-safe report calendar, with no report contents or billing credentials. */
interface ReportsProps extends AuthProps {
    /** Explicit authorized route identity. */
    team: { id: number; name: string };
    /** IANA timezone used by both month choices and the existing PDF endpoint. */
    reportTimezone: string;
}

/** Keep report controls inside the removable tenant boundary. */
export default function Reports() {
    const { team, reportTimezone } = usePage<ReportsProps>().props;
    return (
        <AppLayout title="Monthly reports" team={team} section="reports">
            <div className="ui-panel">
                <MonthlyReportDownload
                    key={`${team.id}:${reportTimezone}`}
                    teamId={team.id}
                    timezone={reportTimezone}
                />
            </div>
        </AppLayout>
    );
}
