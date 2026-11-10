/** Present the authenticated identity and currently accessible team without monitoring data. */
import { Link, usePage } from '@inertiajs/react';
import type { AuthProps } from '../../components/auth/AuthLayout';
import PrivateContextLayout from '../../components/app/PrivateContextLayout';

/** Account-only data deliberately scoped through membership resolution. */
interface OverviewProps extends AuthProps {
    /** Whether a valid invitation can be explicitly reviewed from this session. */
    hasPendingInvitation?: boolean;
    /** Team display name, or absence when the persisted selection is inaccessible. */
    currentTeam?: { id?: number; name: string } | null;
}

/** Provide account identity and explicit entries without inferring tenant authorization. */
export default function Overview() {
    const { auth, currentTeam, hasPendingInvitation } = usePage<OverviewProps>().props;

    return (
        <PrivateContextLayout title="Your account">
            <p className="text-xl">Welcome, {auth?.user?.name}.</p>
            <p className="mt-2 text-slate-300">{auth?.user?.email}</p>
            <p className="mt-6">
                {currentTeam
                    ? `Current team: ${currentTeam.name}`
                    : 'No accessible team is currently selected.'}
            </p>
            <div className="ui-actions">
                {currentTeam?.id && (
                    <Link href={`/teams/${currentTeam.id}/dashboard`}>Open dashboard</Link>
                )}
                {hasPendingInvitation && <Link href="/invitations/accept">Review invitation</Link>}
            </div>
        </PrivateContextLayout>
    );
}
