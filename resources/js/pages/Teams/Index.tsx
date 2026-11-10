/** List only the authenticated account's teams and explicitly change its preference. */
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import TeamSwitcher from '../../components/app/TeamSwitcher';
import type { TeamNavigationView } from '../../components/app/navigation';
import AuthLayout, { type AuthProps } from '../../components/auth/AuthLayout';

/** Explicit list response without tenant domain resources. */
interface TeamsProps extends AuthProps {
    /** All currently accessible teams. */
    teams: TeamNavigationView['teams'];
    /** Valid selected preference, or no implicit selection. */
    currentTeamId: number | null;
}

/** Show an honest empty state for invited identities awaiting acceptance. */
export default function Teams() {
    const { teams, currentTeamId } = usePage<TeamsProps>().props;

    const [suspended, setSuspended] = useState(false);

    return (
        <AuthLayout title="Your teams">
            {teams.length > 0 && (
                <TeamSwitcher
                    key={currentTeamId}
                    navigation={{ teams, selectedTeamId: currentTeamId }}
                    activeTeamId={currentTeamId}
                    onSuspend={() => setSuspended(true)}
                />
            )}
            {!suspended &&
                (teams.length === 0 ? (
                    <p>You do not belong to any teams yet. Open your invitation to join one.</p>
                ) : (
                    <ul className="space-y-4">
                        {teams.map((team) => (
                            <li key={team.id}>
                                <Link href={`/teams/${team.id}/dashboard`} className="ui-link">
                                    {team.name}
                                </Link>
                                <p>
                                    {team.role}
                                    {team.id === currentTeamId ? ' · Current team' : ''}
                                </p>
                            </li>
                        ))}
                    </ul>
                ))}
            <Link className="mt-6 block text-emerald-300" href="/account">
                Your account
            </Link>
        </AuthLayout>
    );
}
