/** Require a recent password check before displaying or changing second-factor security. */
import AuthForm from '../../components/auth/AuthForm';

/** Reauthenticate without conflating it with a new login or second-factor enrollment. */
export default function ConfirmPassword() {
    return (
        <AuthForm
            title="Confirm your password"
            description="Confirm your password to manage account security. Confirmation lasts 15 minutes."
            action="/user/confirm-password"
            submit="Confirm password"
            fields={[
                {
                    name: 'password',
                    label: 'Current password',
                    type: 'password',
                    autoComplete: 'current-password',
                },
            ]}
            links={[{ href: '/account', label: 'Back to account' }]}
        />
    );
}
