/** List only the authenticated account's teams and explicitly change its preference. */
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import TeamSwitcher from '../../components/app/TeamSwitcher';
import type { TeamNavigationView } from '../../components/app/navigation';
import type { AuthProps } from '../../components/auth/AuthLayout';
import PrivateContextLayout from '../../components/app/PrivateContextLayout';

/** Explicit list response without tenant domain resources. */
interface TeamsProps extends AuthProps {
    /** All currently accessible teams. */
    teams: TeamNavigationView['teams'];
    /** Valid selected preference, or no implicit selection. */
    currentTeamId: number | null;
}

/** Show an honest empty state for invited identities awaiting acceptance. */
function PageContent() {
    const { teams, currentTeamId } = usePage<TeamsProps>().props;

    const [suspended, setSuspended] = useState(false);

    return (
        <>
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
                    <ul className="grid gap-4">
                        {teams.map((team) => (
                            <li key={team.id} className="ui-panel">
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
        </>
    );
}

/** Reset selector state on identity replacement; no team is inferred from an old preference. */
export default function Teams() {
    return (
        <PrivateContextLayout
            title="Your teams"
            description="Choose a team you currently belong to. Each team has its own role and permissions."
        >
            <PageContent />
        </PrivateContextLayout>
    );
}
