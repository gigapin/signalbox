/** List current memberships with shared title search and an explicitly confirmed team switch. */
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import TeamSwitcher from '../../components/app/TeamSwitcher';
import type { TeamNavigationView } from '../../components/app/navigation';
import type { AuthProps } from '../../components/auth/AuthLayout';
import PrivateContextLayout from '../../components/app/PrivateContextLayout';
import SettingsCollection from '../../components/ui/SettingsCollection';
import Badge from '../../components/ui/Badge';

/** Explicit memberships and preference, without tenant resources or creation capabilities. */
interface TeamsProps extends AuthProps {
    /** Fresh accessible team names and roles. */
    teams: TeamNavigationView['teams'];
    /** Current accessible preference, or null. */
    currentTeamId: number | null;
}

/** Remove results before a context switch while retaining its failure/review controls. */
function PageContent({ actionsTarget }: { actionsTarget: HTMLElement | null }) {
    const { teams, currentTeamId } = usePage<TeamsProps>().props;
    const [suspended, setSuspended] = useState(false);
    return (
        <SettingsCollection
            items={teams}
            itemLabel={(team) => team.name}
            actionsTarget={actionsTarget}
            suspended={suspended}
            searchLabel="Find a team"
            listLabel="Your teams"
            cardPrefix="team"
            emptyText="You do not belong to any teams yet. Open your invitation to join one."
            noMatchText="No teams match this search."
            extraControls={
                teams.length > 0 && (
                    <TeamSwitcher
                        key={currentTeamId}
                        navigation={{ teams, selectedTeamId: currentTeamId }}
                        activeTeamId={currentTeamId}
                        onSuspend={() => setSuspended(true)}
                    />
                )
            }
            renderItem={(team, hidden) => (
                <li
                    key={team.id}
                    id={`team-${team.id}`}
                    tabIndex={-1}
                    hidden={hidden}
                    aria-labelledby={`team-${team.id}-heading`}
                    className="ui-panel ui-notification-card"
                >
                    <h2 id={`team-${team.id}-heading`} className="ui-notification-name">
                        <Link href={`/teams/${team.id}/dashboard`} className="ui-link">
                            {team.name}
                        </Link>
                    </h2>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Badge tone="info">{team.role}</Badge>
                        {team.id === currentTeamId && <Badge tone="success">Current team</Badge>}
                    </div>
                </li>
            )}
        />
    );
}

/** Keep heading portals inside the account shell's removable identity boundary. */
export default function Teams() {
    const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
    return (
        <PrivateContextLayout
            title="Your teams"
            actions={<div ref={setActionsTarget} className="contents" />}
        >
            <PageContent actionsTarget={actionsTarget} />
        </PrivateContextLayout>
    );
}
