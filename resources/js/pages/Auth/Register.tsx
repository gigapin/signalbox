/** Register an unverified account and its first team in one operation. */
import AuthForm from '../../components/auth/AuthForm';

/** Present the minimal explicit account and team registration inputs. */
export default function Register() {
    return (
        <AuthForm
            title="Create your account"
            description="Start with your first team. Verify your email before entering your account."
            action="/register"
            submit="Create account"
            fields={[
                { name: 'name', label: 'Your name', autoComplete: 'name' },
                { name: 'team_name', label: 'Team name', autoComplete: 'organization' },
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
            links={[{ href: '/login', label: 'Already have an account? Sign in' }]}
        />
    );
}
