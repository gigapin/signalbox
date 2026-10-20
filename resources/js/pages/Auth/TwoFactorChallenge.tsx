/** Complete the pending login with an authenticator token or one recovery code. */
import { useState } from 'react';
import AuthForm from '../../components/auth/AuthForm';

/** Switching methods remounts the form and clears the previous credential value. */
export default function TwoFactorChallenge() {
    /** Only the selected method is retained; no recovery code is remembered. */
    const [recovery, setRecovery] = useState(false);
    return (
        <>
            <AuthForm
                key={recovery ? 'recovery' : 'totp'}
                title="Two-factor authentication"
                description="Complete your sign-in within five minutes."
                action="/two-factor-challenge"
                submit="Verify and sign in"
                fields={
                    recovery
                        ? [{ name: 'recovery_code', label: 'Recovery code', autoComplete: 'off' }]
                        : [
                              {
                                  name: 'code',
                                  label: 'Authentication code',
                                  autoComplete: 'one-time-code',
                                  numeric: true,
                              },
                          ]
                }
                links={[{ href: '/login', label: 'Back to sign in' }]}
            />
            <div className="fixed inset-x-0 bottom-6 text-center">
                <button
                    onClick={() => setRecovery(!recovery)}
                    className="rounded bg-slate-900 px-4 py-2 text-emerald-300"
                >
                    {recovery ? 'Use an authentication code' : 'Use a recovery code'}
                </button>
            </div>
        </>
    );
}
