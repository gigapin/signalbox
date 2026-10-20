/** Request a reset email with a non-enumerating server acknowledgement. */
import AuthForm from '../../components/auth/AuthForm';

/** Request a link without disclosing whether an identity exists. */
export default function ForgotPassword() {
    return (
        <AuthForm
            title="Reset your password"
            description="Enter your email to request a reset link."
            action="/forgot-password"
            submit="Send reset link"
            fields={[{ name: 'email', label: 'Email', type: 'email', autoComplete: 'email' }]}
            links={[{ href: '/login', label: 'Back to sign in' }]}
        />
    );
}
