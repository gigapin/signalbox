/** List current memberships with shared title search and an explicitly confirmed team switch. */
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import TeamSwitcher from '../../components/app/TeamSwitcher';
import type { TeamNavigationView } from '../../components/app/navigation';
import type { AuthProps } from '../../components/auth/AuthLayout';
import PrivateContextLayout from '../../components/app/PrivateContextLayout';
import SettingsCollection from '../../components/ui/SettingsCollection';
import Modal from '../../components/ui/Modal';
import Button from '../../components/ui/Button';
import TeamPlanPanel, { type TeamPlanSummary } from '../../components/billing/TeamPlanPanel';
import { InviteForm, LeaveTeam } from '../../components/teams/Management';
import { useReadNavigation } from '../../hooks/useReadNavigation';
import PageLoader from '../../components/ui/PageLoader';
import { useNavigationGuard } from '../../components/app/NavigationGuard';
import Badge from '../../components/ui/Badge';

/** Explicit memberships and preference, without tenant resources or creation capabilities. */
interface TeamsProps extends AuthProps {
    /** Fresh accessible team names and roles. */
    teams: TeamNavigationView['teams'];
    /** Current accessible preference, or null. */
    currentTeamId: number | null;
    /** Fresh authorized panel, fetched only on explicit request. */
    panel?: {
        kind: string;
        team: { id: number; name: string; ownerId: number };
        actorId: number;
        roles: string[];
        planSummary: TeamPlanSummary | null;
    } | null;
}

/** Remove results before a context switch while retaining its failure/review controls. */
function PageContent({ actionsTarget }: { actionsTarget: HTMLElement | null }) {
    const { teams, currentTeamId, panel } = usePage<TeamsProps>().props;
    const [suspended, setSuspended] = useState(false);
    const guard = useNavigationGuard();
    const read = useReadNavigation();
    const [closed, setClosed] = useState(false);
    const [previous, setPrevious] = useState(panel);
    if (previous !== panel) {
        setPrevious(panel);
        setClosed(false);
    }
    return (
        <>
            <PageLoader active={read.loading} />
            {read.failed && <p role="alert">Team details could not be loaded. Try again.</p>}
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
                            afterSwitch="teams"
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
                            {team.id === currentTeamId && (
                                <Badge tone="success">Current team</Badge>
                            )}
                        </div>
                        <div className="ui-actions mt-4">
                            <Link
                                className="ui-button"
                                data-variant="secondary"
                                href={`/teams/${team.id}/members`}
                            >
                                Members
                            </Link>
                            {(team.role === 'owner' || team.role === 'admin') && (
                                <Link
                                    className="ui-button"
                                    data-variant="secondary"
                                    href={`/teams/${team.id}/invitations`}
                                >
                                    Invitations
                                </Link>
                            )}
                            {(
                                [
                                    'plan',
                                    ...(team.role === 'owner' || team.role === 'admin'
                                        ? ['invite']
                                        : []),
                                    'access',
                                ] as const
                            ).map((kind) => (
                                <Button
                                    key={kind}
                                    variant="secondary"
                                    disabled={guard.busy}
                                    onClick={() => {
                                        if (panel?.team.id === team.id && panel.kind === kind) {
                                            setClosed(false);
                                            return;
                                        }
                                        read.navigate(
                                            '/teams',
                                            { team_id: String(team.id), panel: kind },
                                            { preserveState: true, preserveScroll: true },
                                        );
                                    }}
                                >
                                    {kind === 'plan'
                                        ? 'Plan and usage'
                                        : kind === 'invite'
                                          ? 'Invite a member'
                                          : 'Team access'}
                                </Button>
                            ))}
                        </div>
                    </li>
                )}
            />
            {panel && (
                <Modal
                    open={!closed && !suspended}
                    title={`${panel.team.name} · ${panel.kind === 'plan' ? 'Plan and usage' : panel.kind === 'invite' ? 'Invite a member' : 'Team access'}`}
                    closeDisabled={guard.busy}
                    onRequestClose={() => {
                        if (!guard.busy) setClosed(true);
                    }}
                >
                    {panel.kind === 'plan' && panel.planSummary && (
                        <TeamPlanPanel summary={panel.planSummary} />
                    )}
                    {panel.kind === 'invite' && (
                        <InviteForm
                            key={panel.team.id}
                            teamId={panel.team.id}
                            roles={panel.roles}
                            returnContext="teams"
                            onSaved={() => setClosed(true)}
                        />
                    )}
                    {panel.kind === 'access' && (
                        <p className="ui-description">
                            The primary owner must transfer ownership before leaving. Ownership
                            transfer retains their owner membership.
                        </p>
                    )}
                    {panel.kind === 'access' &&
                        (panel.actorId === panel.team.ownerId ? (
                            <Link className="ui-button" href={`/teams/${panel.team.id}/ownership`}>
                                Transfer primary ownership
                            </Link>
                        ) : (
                            <LeaveTeam
                                teamId={panel.team.id}
                                actorId={panel.actorId}
                                name={panel.team.name}
                            />
                        ))}
                </Modal>
            )}
        </>
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
