/** Manage one authorized team's membership and invitation lifecycles. */
import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthLayout, { type AuthProps } from '../../components/auth/AuthLayout';

/** Member projection containing no account credentials. */
interface Member {
    /** Team-local target user identifier. */
    id: number;
    /** Human-readable identity. */
    name: string;
    /** Contact address visible only to fellow team members. */
    email: string;
    /** Current persisted team role. */
    role: string;
    /** Server-projected permission for role changes/removal. */
    manageable: boolean;
}

/** Invitation-management projection without its bearer capability or hash. */
interface Invitation {
    /** Team-local invitation ID. */
    id: number;
    /** Intended recipient. */
    email: string;
    /** Offered role. */
    role: string;
    /** ISO-8601 exclusive expiry instant. */
    expiresAt: string;
    /** Pending, expired or revoked lifecycle status. */
    state: string;
}

/** Authorized membership-management page response. */
interface TeamProps extends AuthProps {
    /** Minimal team identity and designated primary owner. */
    team: { id: number; name: string; ownerId: number };
    /** Authenticated account identity, for self-departure controls only. */
    actorId: number;
    /** Role targets permitted to the current manager; empty for read-only members. */
    roles: string[];
    /** Current team members. */
    members: Member[];
    /** Invitations the actor may manage. */
    invitations: Invitation[];
}

/** Validation errors shared by the management forms. */
interface ErrorsProps {
    /** Field-keyed server messages. */
    errors: Record<string, string>;
}

/** Inputs for one member's independent edit/removal form. */
interface MemberRowProps {
    /** Existing target membership. */
    member: Member;
    /** Authorized owning team. */
    teamId: number;
    /** Allowed destination roles. */
    roles: string[];
}

/** Inputs for one invitation's delivery/revocation controls. */
interface InvitationRowProps {
    /** Lifecycle the actor is permitted to manage. */
    invitation: Invitation;
    /** Authorized owning team. */
    teamId: number;
}

/** Present server validation errors without trusting client permission projections. */
function Errors({ errors }: ErrorsProps) {
    return (
        <>
            {Object.entries(errors).map(([field, message]) => (
                <p key={field} role="alert" className="text-red-300">
                    {message}
                </p>
            ))}
        </>
    );
}

/** Keep one member's edit and removal state independent of adjacent rows. */
function MemberRow({ member, teamId, roles }: MemberRowProps) {
    const form = useForm({ role: member.role });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(`/teams/${teamId}/members/${member.id}`);
    }

    function removeMember() {
        if (!window.confirm(`Remove ${member.name} from this team?`)) {
            return;
        }

        form.delete(`/teams/${teamId}/members/${member.id}`);
    }

    return (
        <li className="rounded border border-slate-700 p-4">
            <p>
                {member.name} ({member.email}) · {member.role}
            </p>
            {member.manageable && (
                <form onSubmit={submit} className="mt-3 flex flex-wrap gap-3">
                    <label>
                        Role for {member.name}
                        <select
                            aria-label={`Role for ${member.name}`}
                            className="ml-2 bg-slate-800"
                            value={form.data.role}
                            onChange={(event) => form.setData('role', event.target.value)}
                        >
                            {roles.map((role) => (
                                <option key={role} value={role}>
                                    {role}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button disabled={form.processing}>Save role for {member.name}</button>
                    <button type="button" disabled={form.processing} onClick={removeMember}>
                        Remove {member.name}
                    </button>
                </form>
            )}
            <Errors errors={form.errors} />
        </li>
    );
}

/** Rotate or revoke only the invitation represented by this row. */
function InvitationRow({ invitation, teamId }: InvitationRowProps) {
    const form = useForm<Record<string, string>>({});

    function revokeInvitation() {
        if (!window.confirm(`Revoke the invitation for ${invitation.email}?`)) {
            return;
        }

        form.delete(`/teams/${teamId}/invitations/${invitation.id}`);
    }

    return (
        <li className="rounded border border-slate-700 p-4">
            <p>
                {invitation.email} · {invitation.role} · {invitation.state}
            </p>
            <p>
                Expires: <time dateTime={invitation.expiresAt}>{invitation.expiresAt}</time>
            </p>
            <div className="mt-3 flex gap-4">
                <button
                    disabled={form.processing}
                    onClick={() =>
                        form.post(`/teams/${teamId}/invitations/${invitation.id}/resend`)
                    }
                >
                    Resend to {invitation.email}
                </button>
                {invitation.state !== 'revoked' && (
                    <button disabled={form.processing} onClick={revokeInvitation}>
                        Revoke invitation for {invitation.email}
                    </button>
                )}
            </div>
            <Errors errors={form.errors} />
        </li>
    );
}

/** Compose readable member lists and narrowly authorized management forms. */
export default function Team() {
    const { team, actorId, roles, members, invitations } = usePage<TeamProps>().props;

    const invite = useForm({ email: '', role: 'member' });

    const leave = useForm<Record<string, string>>({});

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        invite.post(`/teams/${team.id}/invitations`, { onSuccess: () => invite.reset('email') });
    }

    function leaveTeam() {
        if (!window.confirm(`Leave ${team.name}?`)) {
            return;
        }

        leave.delete(`/teams/${team.id}/members/${actorId}`);
    }

    return (
        <AuthLayout title={team.name}>
            <h2 className="text-xl">Members</h2>
            <ul className="mt-4 space-y-3">
                {members.map((member) => (
                    <MemberRow
                        key={`${member.id}-${member.role}`}
                        member={member}
                        teamId={team.id}
                        roles={roles}
                    />
                ))}
            </ul>
            {roles.length > 0 && (
                <>
                    <h2 className="mt-8 text-xl">Invite a member</h2>
                    <form onSubmit={submit} className="mt-4 space-y-3">
                        <label className="block">
                            Invitation email
                            <input
                                required
                                type="email"
                                maxLength={255}
                                className="ml-2 bg-slate-800"
                                value={invite.data.email}
                                onChange={(event) => invite.setData('email', event.target.value)}
                                aria-invalid={Boolean(invite.errors.email)}
                            />
                        </label>
                        <label className="block">
                            Invitation role
                            <select
                                className="ml-2 bg-slate-800"
                                value={invite.data.role}
                                onChange={(event) => invite.setData('role', event.target.value)}
                            >
                                {roles.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button disabled={invite.processing}>
                            {invite.processing ? 'Sending…' : 'Send invitation'}
                        </button>
                        <Errors errors={invite.errors} />
                    </form>
                    <h2 className="mt-8 text-xl">Invitations</h2>
                    {invitations.length === 0 ? (
                        <p>No outstanding invitations.</p>
                    ) : (
                        <ul className="mt-4 space-y-3">
                            {invitations.map((invitation) => (
                                <InvitationRow
                                    key={invitation.id}
                                    invitation={invitation}
                                    teamId={team.id}
                                />
                            ))}
                        </ul>
                    )}
                </>
            )}
            <nav
                className="mt-8 flex flex-wrap gap-4 text-emerald-300"
                aria-label="Team navigation"
            >
                <Link href="/teams">Your teams</Link>
                <Link href={`/teams/${team.id}/monitors`}>Monitors</Link>
                {team.ownerId === actorId ? (
                    <Link href={`/teams/${team.id}/ownership`}>Transfer primary ownership</Link>
                ) : (
                    <button disabled={leave.processing} onClick={leaveTeam}>
                        Leave team
                    </button>
                )}
            </nav>
            <Errors errors={leave.errors} />
        </AuthLayout>
    );
}
