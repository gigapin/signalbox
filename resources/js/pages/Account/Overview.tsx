/** Edit optional private account details without altering team authorization or active email before verification. */
import { Link, useForm, usePage } from '@inertiajs/react';
import { createPortal } from 'react-dom';
import { useEffect, useId, useState, type FormEvent } from 'react';
import type { AuthProps } from '../../components/auth/AuthLayout';
import PrivateContextLayout from '../../components/app/PrivateContextLayout';
import { useFormProtection } from '../../components/app/NavigationGuard';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import InputField from '../../components/ui/InputField';
import SelectField from '../../components/ui/SelectField';
import Button from '../../components/ui/Button';

/** Private owner projection; additional account data never enters global identity props. */
interface ProfileProps extends AuthProps {
    /** Optional fields plus the current active and pending email. */
    profile?: Record<string, string | null>;
    /** Closed ISO country choices. */
    countries?: string[];
    /** Existing invitation entry remains accessible after removing the old cards. */
    hasPendingInvitation?: boolean;
}
/** One removable draft boundary for the current identity. */
function ProfileForm({ actionsTarget }: { actionsTarget: HTMLElement | null }) {
    const {
        profile = {},
        countries = [],
        auth,
        hasPendingInvitation,
    } = usePage<ProfileProps>().props;
    const form = useForm({
        first_name: profile.first_name ?? '',
        last_name: profile.last_name ?? '',
        email: profile.email ?? auth?.user?.email ?? '',
        phone: profile.phone ?? '',
        country: profile.country ?? '',
        company_name: profile.company_name ?? '',
        vat_number: profile.vat_number ?? '',
        avatar: null as File | null,
        remove_avatar: false,
        current_password: '',
    });
    const formId = useId();
    const emailForm = useForm<Record<string, never>>({});
    const mutation = useOwnedMutation();
    useFormProtection(form);
    const [preview, setPreview] = useState<string | null>(null);
    useEffect(() => {
        if (!form.data.avatar) return;
        const reader = new FileReader();
        reader.onload = () => {
            if (typeof reader.result === 'string') setPreview(reader.result);
        };
        reader.readAsDataURL(form.data.avatar);
        return () => reader.abort();
    }, [form.data.avatar]);
    const changedEmail =
        form.data.email.trim().toLowerCase() !== (profile.email ?? auth?.user?.email);
    const names = new Intl.DisplayNames(['en'], { type: 'region' });
    /** Confirm only a user-triggered save; cancellation leaves the complete draft intact. */
    function submit(event: FormEvent) {
        event.preventDefault();
        if (mutation.locked || !form.isDirty || !window.confirm('Save changes to your account?'))
            return;
        mutation.run(
            '/account/profile',
            (options) => form.post('/account/profile', { ...options, forceFormData: true }),
            undefined,
            () => {
                form.setData('email', profile.email ?? auth?.user?.email ?? '');
                form.setData('current_password', '');
                form.setData('avatar', null);
                form.setData('remove_avatar', false);
                form.setDefaults({
                    ...form.data,
                    email: profile.email ?? auth?.user?.email ?? '',
                    current_password: '',
                    avatar: null,
                    remove_avatar: false,
                });
                setPreview(null);
            },
        );
    }
    return (
        <>
            {actionsTarget &&
                createPortal(
                    <Button
                        type="submit"
                        form={formId}
                        disabled={!form.isDirty || mutation.locked}
                        busy={form.processing}
                    >
                        Save changes
                    </Button>,
                    actionsTarget,
                )}
            {hasPendingInvitation && (
                <p>
                    <Link href="/invitations/accept" className="ui-link">
                        Review invitation
                    </Link>
                </p>
            )}
            {profile.pending_email && (
                <div className="ui-alert" role="status">
                    Email change pending: {profile.pending_email}. Your current sign-in email
                    remains active.
                    <div className="ui-actions">
                        <Button
                            disabled={mutation.locked}
                            onClick={() =>
                                mutation.run('/account/email-change/resend', (options) =>
                                    emailForm.post('/account/email-change/resend', options),
                                )
                            }
                        >
                            Resend verification
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={mutation.locked}
                            onClick={() =>
                                mutation.run('/account/email-change', (options) =>
                                    emailForm.delete('/account/email-change', options),
                                )
                            }
                        >
                            Cancel email change
                        </Button>
                    </div>
                </div>
            )}
            {emailForm.errors['email'] && (
                <p role="alert" className="ui-help">
                    {emailForm.errors['email']}
                </p>
            )}
            <form id={formId} onSubmit={submit} className="ui-panel">
                <button
                    type="submit"
                    hidden
                    disabled={!form.isDirty || mutation.locked}
                    aria-hidden="true"
                    tabIndex={-1}
                />
                <fieldset
                    disabled={mutation.locked}
                    className="grid items-start gap-4 md:grid-cols-2"
                >
                    <legend className="ui-section-title">Account details</legend>
                    <InputField
                        label="First name"
                        value={form.data.first_name}
                        maxLength={100}
                        error={form.errors.first_name}
                        onChange={(e) => form.setData('first_name', e.target.value)}
                        autoComplete="given-name"
                    />
                    <InputField
                        label="Last name"
                        value={form.data.last_name}
                        maxLength={100}
                        error={form.errors.last_name}
                        onChange={(e) => form.setData('last_name', e.target.value)}
                        autoComplete="family-name"
                    />
                    <InputField
                        label="Email"
                        type="email"
                        required
                        value={form.data.email}
                        error={form.errors.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        autoComplete="email"
                    />
                    <InputField
                        label="Phone"
                        type="tel"
                        maxLength={40}
                        value={form.data.phone}
                        error={form.errors.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                        autoComplete="tel"
                    />
                    <SelectField
                        label="Country"
                        value={form.data.country}
                        error={form.errors.country}
                        onChange={(e) => form.setData('country', e.target.value)}
                        autoComplete="country"
                    >
                        <option value="">Not specified</option>
                        {countries.map((code) => (
                            <option key={code} value={code}>
                                {names.of(code)}
                            </option>
                        ))}
                    </SelectField>
                    <InputField
                        label="Company name"
                        value={form.data.company_name}
                        maxLength={255}
                        error={form.errors.company_name}
                        onChange={(e) => form.setData('company_name', e.target.value)}
                        autoComplete="organization"
                    />
                    <InputField
                        label="VAT number"
                        value={form.data.vat_number}
                        maxLength={64}
                        error={form.errors.vat_number}
                        onChange={(e) => form.setData('vat_number', e.target.value)}
                    />
                    <InputField
                        label="Logo / avatar"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        hint="Optional JPEG, PNG or WebP. Maximum 2 MiB and 512 × 512 pixels."
                        error={form.errors.avatar}
                        onChange={(e) => {
                            form.setData('avatar', e.target.files?.[0] ?? null);
                            form.setData('remove_avatar', false);
                        }}
                    />
                    {(preview || auth?.user?.avatarUrl) && !form.data.remove_avatar && (
                        <div>
                            <img
                                src={preview ?? auth?.user?.avatarUrl ?? ''}
                                alt="Avatar preview"
                                className="h-24 w-24 rounded-full object-contain"
                            />
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    form.setData('avatar', null);
                                    form.setData('remove_avatar', true);
                                    setPreview(null);
                                }}
                            >
                                Remove image
                            </Button>
                        </div>
                    )}
                    {changedEmail && (
                        <InputField
                            label="Current password"
                            type="password"
                            required
                            value={form.data.current_password}
                            error={form.errors.current_password}
                            hint="Confirm your identity to request a new sign-in email."
                            onChange={(e) => form.setData('current_password', e.target.value)}
                            autoComplete="current-password"
                        />
                    )}
                </fieldset>
            </form>
            {mutation.phase === 'uncertain' && (
                <p role="alert">
                    The result could not be confirmed. Reload this page to check your saved details
                    before trying again.
                </p>
            )}
        </>
    );
}
/** @returns Profile draft disposed whenever the authenticated account changes. */
export default function Overview() {
    const { auth } = usePage<AuthProps>().props;
    const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
    return (
        <PrivateContextLayout
            title="Your account"
            actions={<div ref={setActionsTarget} className="contents" />}
        >
            <ProfileForm key={auth?.user?.email ?? 'none'} actionsTarget={actionsTarget} />
        </PrivateContextLayout>
    );
}
