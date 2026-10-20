/** Render session login without assuming completion of a second-factor challenge. */
import AuthForm from '../../components/auth/AuthForm';

/** Collect credentials and optional persistent-session preference. */
export default function Login() {
    return (
        <AuthForm
            title="Sign in"
            action="/login"
            submit="Sign in"
            remember
            fields={[
                { name: 'email', label: 'Email', type: 'email', autoComplete: 'username' },
                {
                    name: 'password',
                    label: 'Password',
                    type: 'password',
                    autoComplete: 'current-password',
                },
            ]}
            links={[
                { href: '/forgot-password', label: 'Forgot password?' },
                { href: '/register', label: 'Create an account' },
            ]}
        />
    );
}
