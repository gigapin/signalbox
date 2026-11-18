/** Review a signed email change without allowing link scanners to mutate the account. */
import { useForm, usePage } from '@inertiajs/react';
import PrivateContextLayout from '../../components/app/PrivateContextLayout';
import Button from '../../components/ui/Button';
/** @returns Explicit CSRF-protected confirmation of the currently signed capability URL. */
export default function ConfirmEmail() {
    const { confirmationUrl, pendingEmail } = usePage<{
        confirmationUrl: string;
        pendingEmail: string;
    }>().props;
    const form = useForm({ email: '' });
    return (
        <PrivateContextLayout title="Confirm email change">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(confirmationUrl);
                }}
            >
                <p>Use {pendingEmail} as your new sign-in email?</p>
                {form.errors.email && <p role="alert">{form.errors.email}</p>}
                <Button type="submit" busy={form.processing}>
                    Confirm email change
                </Button>
            </form>
        </PrivateContextLayout>
    );
}
