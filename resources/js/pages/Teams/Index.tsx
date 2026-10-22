/** List only the authenticated account's teams and explicitly change its preference. */
import { Link, useForm, usePage } from '@inertiajs/react';
import AuthLayout, { type AuthProps } from '../../components/auth/AuthLayout';

/** Membership-derived team identity. */
interface TeamChoice {
    /** Public identifier used only with server-side membership checks. */
    id: number;
    /** Team display name. */
    name: string;
    /** Persisted membership role. */
    role: string;
}

/** Explicit list response without tenant domain resources. */
interface TeamsProps extends AuthProps {
    /** All currently accessible teams. */
    teams: TeamChoice[];
    /** Valid selected preference, or no implicit selection. */
    currentTeamId: number | null;
}

/** Inputs for one independently submitted team selection. */
interface ChoiceProps {
    /** Accessible team identity. */
    team: TeamChoice;
    /** Whether it is the current preference. */
    selected: boolean;
}

/** Keep each team's selection request independently disabled while submitting. */
function Choice({ team, selected }: ChoiceProps) {
    const form = useForm<Record<string, string>>({});

    return (
        <li className="rounded border border-slate-700 p-4">
            <Link className="text-emerald-300" href={`/teams/${team.id}`}>
                {team.name}
            </Link>
            <p>
                {team.role}
                {selected ? ' · Current team' : ''}
            </p>
            {!selected && (
                <button
                    disabled={form.processing}
                    onClick={() => form.post(`/teams/${team.id}/select`)}
                >
                    {form.processing ? 'Switching…' : `Switch to ${team.name}`}
                </button>
            )}
        </li>
    );
}

/** Show an honest empty state for invited identities awaiting acceptance. */
export default function Teams() {
    const { teams, currentTeamId } = usePage<TeamsProps>().props;

    return (
        <AuthLayout title="Your teams">
            {teams.length === 0 ? (
                <p>You do not belong to any teams yet. Open your invitation to join one.</p>
            ) : (
                <ul className="space-y-4">
                    {teams.map((team) => (
                        <Choice key={team.id} team={team} selected={team.id === currentTeamId} />
                    ))}
                </ul>
            )}
            <Link className="mt-6 block text-emerald-300" href="/account">
                Your account
            </Link>
        </AuthLayout>
    );
}
