/** Register an invited account without inventing a personal team or accepting membership. */
import { usePage } from '@inertiajs/react';
import AuthForm from '../../components/auth/AuthForm';
import type { AuthProps } from '../../components/auth/AuthLayout';

/** Capability-proved recipient; editable input is still checked against the invitation on the server. */
interface RegisterProps extends AuthProps {
    /** Intended normalized recipient email. */
    email: string;
}

/** Reuse existing credential handling while omitting the ordinary initial-team input. */
export default function RegisterInvited() {
    const { email } = usePage<RegisterProps>().props;

    return (
        <AuthForm
            title="Create your invited account"
            description="Verify your email, then explicitly accept the invitation. No personal team will be created."
            action="/invitations/register"
            submit="Create account"
            initial={{ email }}
            fields={[
                { name: 'name', label: 'Your name', autoComplete: 'name' },
                { name: 'email', label: 'Email', type: 'email', autoComplete: 'email' },
                {
                    name: 'password',
                    label: 'Password (12–128 characters)',
                    type: 'password',
                    autoComplete: 'new-password',
                },
                {
                    name: 'password_confirmation',
                    label: 'Confirm password',
                    type: 'password',
                    autoComplete: 'new-password',
                },
            ]}
            links={[
                { href: '/login', label: 'Already have an account? Sign in' },
                { href: '/invitations/accept', label: 'Review invitation' },
            ]}
        />
    );
}
