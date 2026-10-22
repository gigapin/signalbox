/** Review a session-backed invitation without exposing its bearer token. */
import { Link, useForm, usePage } from '@inertiajs/react';
import AuthLayout, { type AuthProps } from '../../components/auth/AuthLayout';

/** Capability-proved preview, containing no token or digest. */
interface InvitationPreview {
    /** Inviting team's display name. */
    teamName: string;
    /** Email address required for acceptance. */
    email: string;
    /** Offered membership role. */
    role: string;
    /** Whether this lifecycle has already been accepted. */
    accepted: boolean;
}

/** Server-provided invitation and current verification state. */
interface InvitationProps extends AuthProps {
    /** Valid preview, or absence for an unusable link. */
    invitation: InvitationPreview | null;
    /** Whether the current identity has completed email verification. */
    verified: boolean;
}

/** Inputs for the next permitted acceptance step. */
interface AcceptanceProps {
    /** Proved invitation being reviewed. */
    invitation: InvitationPreview;
    /** Current authenticated identity, if present. */
    user: { name: string; email: string } | null;
    /** Current email-verification state. */
    verified: boolean;
}

/** Present one explicit step: login, matching identity, verification or acceptance. */
function Acceptance({ invitation, user, verified }: AcceptanceProps) {
    const form = useForm<Record<string, string>>({});

    if (!user) {
        return (
            <nav className="mt-6 flex gap-4">
                <Link href="/login">Sign in</Link>
                <Link href="/invitations/register">Create an account</Link>
            </nav>
        );
    }

    if (user.email !== invitation.email) {
        return <p role="alert">Sign in with {invitation.email} to accept this invitation.</p>;
    }

    if (!verified) {
        return (
            <Link className="mt-6 block text-emerald-300" href="/email/verify">
                Verify your email before accepting
            </Link>
        );
    }

    const submitLabel = invitation.accepted ? 'View joined team' : 'Accept invitation';

    return (
        <>
            <button
                className="mt-6"
                disabled={form.processing}
                onClick={() => form.post('/invitations/accept')}
            >
                {form.processing ? 'Joining…' : submitLabel}
            </button>
            {Object.entries(form.errors).map(([field, message]) => (
                <p key={field} role="alert">
                    {message}
                </p>
            ))}
        </>
    );
}

/** Viewing a link never joins a team; acceptance always requires an explicit POST. */
export default function Invitation() {
    const { invitation, verified, auth } = usePage<InvitationProps>().props;
    const switchAccount = useForm<Record<string, string>>({});

    return (
        <AuthLayout title="Team invitation">
            {invitation ? (
                <>
                    <p>
                        Join {invitation.teamName} as {invitation.role} using {invitation.email}.
                    </p>
                    <Acceptance
                        invitation={invitation}
                        user={auth?.user ?? null}
                        verified={verified}
                    />
                    {auth?.user && (
                        <button
                            className="mt-6 block"
                            disabled={switchAccount.processing}
                            onClick={() => switchAccount.post('/invitations/switch-account')}
                        >
                            Use another account
                        </button>
                    )}
                </>
            ) : (
                <p role="alert">
                    This invitation is unavailable or expired. Ask a team owner or admin for a new
                    link.
                </p>
            )}
            <Link className="mt-6 block text-emerald-300" href="/account">
                Your account
            </Link>
        </AuthLayout>
    );
}
