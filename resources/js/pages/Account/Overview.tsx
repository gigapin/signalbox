/** Present the authenticated identity and currently accessible team without monitoring data. */
import { Link, useForm, usePage } from '@inertiajs/react';
import AuthLayout, { type AuthProps } from '../../components/auth/AuthLayout';

/** Account-only data deliberately scoped through membership resolution. */
interface OverviewProps extends AuthProps {
    /** Whether a valid invitation can be explicitly reviewed from this session. */
    hasPendingInvitation?: boolean;
    /** Team display name, or absence when the persisted selection is inaccessible. */
    currentTeam?: { name: string } | null;
}

/** Provide an honest account destination while later tenant features are implemented. */
export default function Overview() {
    const { auth, currentTeam, hasPendingInvitation } = usePage<OverviewProps>().props;

    const logout = useForm({});

    return (
        <AuthLayout title="Your account">
            <p className="text-xl">Welcome, {auth?.user?.name}.</p>
            <p className="mt-2 text-slate-300">{auth?.user?.email}</p>
            <p className="mt-6">
                {currentTeam
                    ? `Current team: ${currentTeam.name}`
                    : 'No accessible team is currently selected.'}
            </p>
            <nav aria-label="Account navigation" className="mt-8 flex gap-6 text-emerald-300">
                <Link href="/teams">Your teams</Link>
                {hasPendingInvitation && <Link href="/invitations/accept">Review invitation</Link>}
                <Link href="/account/security">Account security</Link>
                <button disabled={logout.processing} onClick={() => logout.post('/logout')}>
                    Sign out
                </button>
            </nav>
        </AuthLayout>
    );
}
