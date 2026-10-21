/** Exchange the reset link's token for a new confirmed password. */
import { usePage } from '@inertiajs/react';
import AuthForm from '../../components/auth/AuthForm';
import type { AuthProps } from '../../components/auth/AuthLayout';

/** Explicit reset link data already present in the browser URL. */
interface ResetProps extends AuthProps {
    /** Opaque password-broker token from the reset link. */
    token: string;
    /** Email address associated with the reset request. */
    email: string;
}

/** Keep reset credentials out of remembered form/history state. */
export default function ResetPassword() {
    /** Reset link values supplied by the server; password fields always start empty. */
    const { token, email } = usePage<ResetProps>().props;
    return (
        <AuthForm
            title="Choose a new password"
            action="/reset-password"
            submit="Reset password"
            initial={{ token, email }}
            fields={[
                { name: 'email', label: 'Email', type: 'email', autoComplete: 'email' },
                {
                    name: 'password',
                    label: 'New password (12–128 characters)',
                    type: 'password',
                    autoComplete: 'new-password',
                },
                {
                    name: 'password_confirmation',
                    label: 'Confirm new password',
                    type: 'password',
                    autoComplete: 'new-password',
                },
            ]}
            links={[{ href: '/login', label: 'Back to sign in' }]}
        />
    );
}
