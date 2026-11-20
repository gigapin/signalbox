/** Manage one authorized team's membership and invitation lifecycles. */
import Badge from '../../components/ui/Badge';
import SelectField from '../../components/ui/SelectField';
import InputField from '../../components/ui/InputField';
import { useFormProtection, useNavigationGuard } from '../../components/app/NavigationGuard';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { AuthProps } from '../../components/auth/AuthLayout';
import PrivateContextLayout from '../../components/app/PrivateContextLayout';
import Modal from '../../components/ui/Modal';
import Pagination from '../../components/monitors/Pagination';
import { useReadNavigation } from '../../hooks/useReadNavigation';
import { createPortal } from 'react-dom';
import { type TeamPlanSummary } from '../../components/billing/TeamPlanPanel';
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
export interface TeamProps extends AuthProps {
    /** Minimal team identity and designated primary owner. */
    team: { id: number; name: string; ownerId: number };
    /** Authenticated account identity, for self-departure controls only. */
    actorId: number;
    /** Role targets permitted to the current manager; empty for read-only members. */
    roles: string[];
    /** Current team members. */
    members: Member[] | { data: Member[]; current_page: number; last_page: number };
    /** Invitations the actor may manage. */
    invitations: Invitation[] | { data: Invitation[]; current_page: number; last_page: number };
    /** Accepted search term. */
    query: string;
    /** Current member-safe entitlement snapshot; absent in older history entries. */
    planSummary?: TeamPlanSummary;
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
    /** Accepted listing query retained when saving another row. */
    listQuery?: string;
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
                <p key={field} role="alert" className="text-ui-danger">
                    {message}
                </p>
            ))}
        </>
    );
}

/** Keep a role draft stable across other accepted changes; never infer current permission from that draft. */
function MemberRow({ member, teamId, roles, listQuery = '' }: MemberRowProps) {
    const form = useForm({ role: member.role });
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const [baseline, setBaseline] = useState(member.role);
    const blocked = mutation.locked || guard.busy;
    const path = `/teams/${teamId}/members/${member.id}${listQuery}`;
    const review = `/teams/${teamId}/members`;
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
        <li className="ui-panel ui-notification-card">
            <p className="ui-notification-name">{member.name}</p>
            <p className="ui-help">
                {member.email} · <Badge tone="info">{member.role}</Badge>
            </p>
            {member.manageable && (
                <form onSubmit={submit} className="mt-3">
                    <fieldset disabled={blocked} className="ui-settings-fields">
                        <SelectField
                            label={<>Role for {member.name}</>}
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
                        </SelectField>
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
        <li className="ui-panel ui-notification-card">
            <p>
                {invitation.email} · <Badge tone="info">{invitation.role}</Badge> ·{' '}
                <Badge
                    tone={
                        invitation.state === 'pending'
                            ? 'warning'
                            : invitation.state === 'expired'
                              ? 'danger'
                              : 'neutral'
                    }
                >
                    {invitation.state}
                </Badge>
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
                reviewUrl={`/teams/${teamId}/invitations`}
                success="Invitation change accepted"
                onReviewed={mutation.acknowledge}
            />
        </li>
    );
}

/** Mount the email draft only while invitation management remains available. */
export function InviteForm({
    teamId,
    roles,
    returnContext = 'members',
    onSaved,
}: {
    teamId: number;
    roles: string[];
    returnContext?: 'teams' | 'members' | 'invitations';
    onSaved?: () => void;
}) {
    const form = useForm({
        email: '',
        return_context: returnContext,
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
            () => {
                form.reset();
                onSaved?.();
            },
        );
    }

    return (
        <form onSubmit={submit} noValidate>
            <p className="ui-description">
                The recipient must verify their email and explicitly accept. Sending an invitation
                does not create membership.
            </p>
            <fieldset disabled={blocked} className="ui-settings-fields">
                <InputField
                    label="Invitation email"
                    required
                    type="email"
                    maxLength={255}
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                    aria-invalid={Boolean(form.errors.email)}
                />
                <SelectField
                    label="Invitation role"
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
                </SelectField>
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
                reviewUrl={
                    returnContext === 'teams' ? '/teams' : `/teams/${teamId}/${returnContext}`
                }
                success="Invitation accepted for sending"
                onReviewed={mutation.acknowledge}
            />
        </form>
    );
}

/** Departure is explicit and never inferred from a failed response. */
export function LeaveTeam({
    teamId,
    actorId,
    name,
}: {
    teamId: number;
    actorId: number;
    name: string;
}) {
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

/** Render a dedicated server-paginated membership or invitation directory. */
export function Directory({ invitationsPage = false }: { invitationsPage?: boolean }) {
    const props = usePage<TeamProps>().props;
    const { team, roles, members, invitations, query } = props;
    const [target, setTarget] = useState<HTMLDivElement | null>(null);
    const [inviting, setInviting] = useState(false);
    const guard = useNavigationGuard();
    const memberPage = Array.isArray(members)
        ? { data: members, current_page: 1, last_page: 1 }
        : members;
    const invitationPage = Array.isArray(invitations)
        ? { data: invitations, current_page: 1, last_page: 1 }
        : invitations;
    const listing = invitationsPage ? invitationPage : memberPage;
    const read = useReadNavigation();
    const base = `/teams/${team.id}/${invitationsPage ? 'invitations' : 'members'}`;
    return (
        <PrivateContextLayout
            title={invitationsPage ? 'Invitations' : 'Members'}
            actions={<div ref={setTarget} className="contents" />}
        >
            {target &&
                createPortal(
                    <>
                        <Link href="/teams" className="ui-button" data-variant="secondary">
                            Back to teams
                        </Link>
                        {roles.length > 0 && (
                            <Button disabled={guard.busy} onClick={() => setInviting(true)}>
                                {invitationsPage ? 'Invite a member' : 'Add member'}
                            </Button>
                        )}
                    </>,
                    target,
                )}
            <nav aria-label="Breadcrumb" className="mb-4">
                <Link href="/teams" className="ui-link">
                    Teams
                </Link>{' '}
                / {team.name} / {invitationsPage ? 'Invitations' : 'Members'}
            </nav>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    read.navigate(base, {
                        q: (event.currentTarget.elements.namedItem('q') as HTMLInputElement).value,
                    });
                }}
                className="ui-actions"
            >
                <InputField
                    label="Search"
                    labelHidden
                    name="q"
                    defaultValue={query}
                    maxLength={120}
                />
                <Button type="submit">Search</Button>
            </form>
            <ul
                aria-label={invitationsPage ? 'Team invitations' : 'Team members'}
                className="ui-notification-list"
            >
                {invitationsPage
                    ? invitationPage.data.map((invitation) => (
                          <InvitationRow
                              key={invitation.id}
                              invitation={invitation}
                              teamId={team.id}
                          />
                      ))
                    : memberPage.data.map((member) => (
                          <MemberRow
                              key={`${member.id}:${member.manageable}`}
                              member={member}
                              listQuery={
                                  query || memberPage.current_page > 1
                                      ? `?${new URLSearchParams({ page: String(memberPage.current_page), q: query ?? '' })}`
                                      : ''
                              }
                              teamId={team.id}
                              roles={roles}
                          />
                      ))}
            </ul>
            {listing.data.length === 0 && (
                <p>
                    No results.{' '}
                    <Link className="ui-link" href={base}>
                        Back to the first page
                    </Link>
                </p>
            )}
            <Pagination
                current={listing.current_page}
                last={listing.last_page}
                href={(page) => `${base}?page=${page}&q=${encodeURIComponent(query)}`}
            />
            {roles.length > 0 && (
                <Modal
                    open={inviting}
                    title="Invite a member"
                    closeDisabled={guard.busy}
                    onRequestClose={() => {
                        if (!guard.busy) setInviting(false);
                    }}
                >
                    <InviteForm
                        key={`${team.id}:${roles.join(',')}`}
                        teamId={team.id}
                        roles={roles}
                        returnContext={invitationsPage ? 'invitations' : 'members'}
                        onSaved={() => setInviting(false)}
                    />
                </Modal>
            )}
        </PrivateContextLayout>
    );
}

/** Preserve old component imports while routing new reads to the dedicated directory. */
export default function Page() {
    return <Directory />;
}
