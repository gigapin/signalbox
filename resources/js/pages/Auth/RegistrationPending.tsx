/** Show the same anonymous acknowledgement after new and existing-account registration requests. */
import { Link } from '@inertiajs/react';
import AuthLayout from '../../components/auth/AuthLayout';

/** No address, identity, delivery state, verification resend or automatic login is exposed here. */
export default function RegistrationPending() {
    return (
        <AuthLayout
            title="Check your email"
            description="Check your inbox for the next steps. To continue, sign in with your password or request a password reset."
        >
            <nav
                aria-label="Registration next steps"
                className="flex flex-wrap gap-4 text-emerald-300"
            >
                <Link href="/login">Sign in</Link>
                <Link href="/forgot-password">Reset your password</Link>
            </nav>
        </AuthLayout>
    );
}
