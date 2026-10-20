/** Explain mandatory verification and expose resend/logout actions for unverified sessions. */
import { useForm } from '@inertiajs/react';
import AuthLayout from '../../components/auth/AuthLayout';

/** Keep unverified accounts on an explicit verification step. */
export default function VerifyEmail() {
    /** Independent request state prevents duplicate email submissions. */
    const resend = useForm({});
    /** Logout request uses POST with the normal CSRF-protected web protocol. */
    const logout = useForm({});
    return (
        <AuthLayout
            title="Verify your email"
            description="We sent you a verification link. Open it to access your account. The link expires in 60 minutes."
        >
            <div className="flex gap-4">
                <button
                    disabled={resend.processing}
                    onClick={() => resend.post('/email/verification-notification')}
                    className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                >
                    {resend.processing ? 'Sending…' : 'Resend verification email'}
                </button>
                <button
                    disabled={logout.processing}
                    onClick={() => logout.post('/logout')}
                    className="text-emerald-300"
                >
                    Sign out
                </button>
            </div>
        </AuthLayout>
    );
}
