/** Offer team selection within the dashboard when no valid team preference exists. */
import { Link, usePage } from '@inertiajs/react';
import PrivateContextLayout from '../../components/app/PrivateContextLayout';
import TeamSwitcher from '../../components/app/TeamSwitcher';
import type { AuthProps } from '../../components/auth/AuthLayout';

/** Render only membership choices; metrics require an explicitly authorized team route. */
export default function DashboardIndex() {
    const { teamNavigation, auth } = usePage<AuthProps>().props;
    return (
        <PrivateContextLayout
            title="Dashboard"
            description="Choose a team to view its services and incidents."
        >
            {teamNavigation && teamNavigation.teams.length > 0 ? (
                <section className="ui-panel" aria-label="Choose a team">
                    <TeamSwitcher
                        key={auth?.user?.email}
                        navigation={teamNavigation}
                        activeTeamId={null}
                    />
                    <ul className="mt-6 grid gap-3">
                        {teamNavigation.teams.map((team) => (
                            <li key={team.id}>
                                {team.name} · {team.role}
                            </li>
                        ))}
                    </ul>
                </section>
            ) : (
                <section className="ui-panel">
                    <p>You do not belong to any teams yet.</p>
                    <Link className="ui-link" href="/account">
                        Your account and invitations
                    </Link>
                </section>
            )}
        </PrivateContextLayout>
    );
}
