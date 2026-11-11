/** Manage one authorized team's membership and invitation lifecycles. */
import { useFormProtection, useNavigationGuard } from '../../components/app/NavigationGuard';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { AuthProps } from '../../components/auth/AuthLayout';
import AppLayout from '../../components/app/AppLayout';
import Panel from '../../components/ui/Panel';
import Button from '../../components/ui/Button';
import MutationFeedback from '../../components/MutationFeedback';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import { formatInstant } from '../../components/incidents/time';

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

/** Keep a role draft stable across other accepted changes; never infer current permission from that draft. */
function MemberRow({ member, teamId, roles }: MemberRowProps) {
    const form = useForm({ role: member.role });
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const [baseline, setBaseline] = useState(member.role);
    const blocked = mutation.locked || guard.busy;
    const path = `/teams/${teamId}/members/${member.id}`;
    const review = `/teams/${teamId}`;
    if (baseline !== member.role && !blocked) {
        setBaseline(member.role);
        form.setDefaults({ role: member.role });
        if (form.data.role === baseline) form.setData('role', member.role);
    }
    const offered = roles.includes(form.data.role);

    /** Save only a currently offered destination; the server authorizes again under lock. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (blocked || !offered || !member.manageable) return;
        mutation.run(path, (options) => form.patch(path, options));
    }

    /** A destructive removal requires explicit confirmation and current management permission. */
    function removeMember() {
        if (
            blocked ||
            !member.manageable ||
            !window.confirm(`Remove ${member.name} from this team?`)
        )
            return;
        mutation.run(path, (options) => form.delete(path, options));
    }

    return (
        <li className="ui-panel">
            <p className="font-semibold">{member.name}</p>
            <p className="ui-help">
                {member.email} · {member.role}
            </p>
            {member.manageable && (
                <form onSubmit={submit} className="mt-3">
                    <fieldset disabled={blocked} className="ui-settings-fields">
                        <label className="ui-label">
                            Role for {member.name}
                            <select
                                className="ui-input"
                                value={form.data.role}
                                onChange={(event) => form.setData('role', event.target.value)}
                                aria-invalid={Boolean(form.errors.role)}
                            >
                                {!offered && (
                                    <option value={form.data.role} disabled>
                                        {form.data.role} (choose a permitted role)
                                    </option>
                                )}
                                {roles.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="ui-actions">
                            <Button type="submit" disabled={!offered}>
                                Save role for {member.name}
                            </Button>
                            <Button variant="danger" onClick={removeMember}>
                                Remove {member.name}
                            </Button>
                        </div>
                    </fieldset>
                    <Errors errors={form.errors} />
                </form>
            )}
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={review}
                success="Membership change accepted"
                onReviewed={mutation.acknowledge}
            />
        </li>
    );
}

/** Rotate or revoke only the represented invitation; neither action automatically retries delivery. */
function InvitationRow({ invitation, teamId }: InvitationRowProps) {
    const form = useForm<Record<string, string>>({});
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;
    const path = `/teams/${teamId}/invitations/${invitation.id}`;

    /** Dispatch a lifecycle action once; a fresh link is issued only after an explicit resend. */
    function change(action: 'resend' | 'revoke') {
        if (blocked) return;
        if (
            action === 'revoke' &&
            !window.confirm(`Revoke the invitation for ${invitation.email}?`)
        )
            return;
        const url = action === 'resend' ? `${path}/resend` : path;
        mutation.run(url, (options) =>
            action === 'resend' ? form.post(url, options) : form.delete(url, options),
        );
    }

    return (
        <li className="ui-panel">
            <p>
                {invitation.email} · {invitation.role} · {invitation.state}
            </p>
            <p className="ui-help">
                Expires: {formatInstant(invitation.expiresAt, 'Unknown')}. A resend replaces the
                previous link.
            </p>
            <div className="ui-actions mt-3">
                <Button variant="secondary" disabled={blocked} onClick={() => change('resend')}>
                    Resend to {invitation.email}
                </Button>
                {invitation.state !== 'revoked' && (
                    <Button variant="danger" disabled={blocked} onClick={() => change('revoke')}>
                        Revoke invitation for {invitation.email}
                    </Button>
                )}
            </div>
            <Errors errors={form.errors} />
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={`/teams/${teamId}`}
                success="Invitation change accepted"
                onReviewed={mutation.acknowledge}
            />
        </li>
    );
}

/** Mount the email draft only while invitation management remains available. */
function InviteForm({ teamId, roles }: { teamId: number; roles: string[] }) {
    const form = useForm({
        email: '',
        role: roles.includes('member') ? 'member' : (roles[0] ?? ''),
    });
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;
    const offered = roles.includes(form.data.role);

    /** Clear the intended recipient only after confirmed server acceptance. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (blocked || !offered) return;
        const url = `/teams/${teamId}/invitations`;
        mutation.run(
            url,
            (options) => form.post(url, options),
            undefined,
            () => form.reset('email'),
        );
    }

    return (
        <form onSubmit={submit} noValidate>
            <fieldset disabled={blocked} className="ui-settings-fields">
                <label className="ui-label">
                    Invitation email
                    <input
                        required
                        type="email"
                        maxLength={255}
                        className="ui-input"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        aria-invalid={Boolean(form.errors.email)}
                    />
                </label>
                <label className="ui-label">
                    Invitation role
                    <select
                        className="ui-input"
                        value={form.data.role}
                        onChange={(event) => form.setData('role', event.target.value)}
                    >
                        {!offered && (
                            <option value={form.data.role} disabled>
                                Choose a permitted role
                            </option>
                        )}
                        {roles.map((role) => (
                            <option key={role} value={role}>
                                {role}
                            </option>
                        ))}
                    </select>
                </label>
                <Button
                    type="submit"
                    disabled={!offered}
                    busy={mutation.phase === 'pending'}
                    busyLabel="Sending…"
                >
                    Send invitation
                </Button>
            </fieldset>
            <Errors errors={form.errors} />
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={`/teams/${teamId}`}
                success="Invitation accepted for sending"
                onReviewed={mutation.acknowledge}
            />
        </form>
    );
}

/** Departure is explicit and never inferred from a failed response. */
function LeaveTeam({ teamId, actorId, name }: { teamId: number; actorId: number; name: string }) {
    const form = useForm<Record<string, string>>({});
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();

    /** Leave only on a confirmed choice; membership and primary-owner rules remain server-owned. */
    function leave() {
        if (mutation.locked || guard.busy || !window.confirm(`Leave ${name}?`)) return;
        const path = `/teams/${teamId}/members/${actorId}`;
        mutation.run(path, (options) => form.delete(path, options));
    }

    return (
        <>
            <Button variant="danger" disabled={mutation.locked || guard.busy} onClick={leave}>
                Leave team
            </Button>
            <Errors errors={form.errors} />
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl="/teams"
                success="Departure accepted"
                onReviewed={mutation.acknowledge}
            />
        </>
    );
}

/** Compose current memberships and capabilities without creating a second authorization model. */
function PageContent() {
    const { team, actorId, roles, members, invitations } = usePage<TeamProps>().props;
    return (
        <>
            <nav aria-label="Team settings" className="ui-actions mb-4">
                <a className="ui-link" href="#members-heading">
                    Members
                </a>
                {roles.length > 0 && (
                    <a className="ui-link" href="#invitations-heading">
                        Invitations
                    </a>
                )}
                <Link className="ui-link" href="/teams">
                    Your teams
                </Link>
            </nav>
            <section id="members-heading" aria-label="Members">
                <Panel
                    title="Members"
                    description="Roles control access within this team. Changes are checked against your current permissions."
                >
                    <ul aria-label="Team members" className="grid gap-4">
                        {members.map((member) => (
                            <MemberRow
                                key={`${member.id}:${member.manageable}`}
                                member={member}
                                teamId={team.id}
                                roles={roles}
                            />
                        ))}
                    </ul>
                </Panel>
            </section>
            {roles.length > 0 && (
                <section id="invitations-heading" aria-label="Invitations" className="mt-6">
                    <Panel
                        title="Invite a member"
                        description="The recipient must verify their email and explicitly accept. Sending an invitation does not create membership."
                    >
                        <InviteForm teamId={team.id} roles={roles} />
                    </Panel>
                    <div className="mt-6">
                        <Panel
                            title="Invitations"
                            description="Expiry times use your browser timezone; invitations contain no bearer link on this page."
                        >
                            {invitations.length === 0 ? (
                                <p>No outstanding invitations.</p>
                            ) : (
                                <ul className="grid gap-4">
                                    {invitations.map((invitation) => (
                                        <InvitationRow
                                            key={invitation.id}
                                            invitation={invitation}
                                            teamId={team.id}
                                        />
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    </div>
                </section>
            )}
            {roles.length === 0 && (
                <p className="ui-help mt-4">
                    You can view members. Invitation and role management require an authorized owner
                    or admin.
                </p>
            )}
            <div className="mt-6">
                <Panel
                    title="Team access"
                    description="The primary owner must transfer ownership before leaving. Ownership transfer retains their owner membership."
                >
                    {team.ownerId === actorId ? (
                        <Link className="ui-link" href={`/teams/${team.id}/ownership`}>
                            Transfer primary ownership
                        </Link>
                    ) : (
                        <LeaveTeam teamId={team.id} actorId={actorId} name={team.name} />
                    )}
                </Panel>
            </div>
        </>
    );
}

/** Keep all page-owned drafts inside the removable route-team boundary. */
export default function Page() {
    const { team } = usePage<TeamProps>().props;
    return (
        <AppLayout
            team={team}
            section="team"
            title={team.name}
            description="Members, invitations and team access."
        >
            <PageContent />
        </AppLayout>
    );
}
