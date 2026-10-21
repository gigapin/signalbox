/** Render cohesive authentication forms with shared validation and submission behavior. */
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthLayout from './AuthLayout';

/** One explicitly declared input in an authentication form. */
export interface AuthField {
    /** Server validation key and HTML input identity. */
    name: string;
    /** Visible and accessible label. */
    label: string;
    /** Input type for browser password/email behavior. */
    type?: 'text' | 'email' | 'password';
    /** Browser autofill hint, including one-time-code for TOTP. */
    autoComplete?: string;
    /** Six-digit codes use a numeric keyboard without dropping leading zeros. */
    numeric?: boolean;
}

/** Shared presentation and request contract for a declared authentication flow. */
interface FormProps {
    /** Screen/document heading. */
    title: string;
    /** Explanation of the current step. */
    description?: string;
    /** Application-owned POST endpoint. */
    action: string;
    /** Explicit form fields in keyboard order. */
    fields: AuthField[];
    /** Server-provided reset email/token, or empty initial data. */
    initial?: Record<string, string>;
    /** Submit button label. */
    submit: string;
    /** Whether to offer a persistent-session checkbox. */
    remember?: boolean;
    /** Named navigation choices beneath the form. */
    links?: { href: string; label: string }[];
}

/** Submit through Inertia so CSRF, redirects and validation use the established web protocol. */
export default function AuthForm({
    title,
    description,
    action,
    fields,
    initial = {},
    submit,
    remember = false,
    links = [],
}: FormProps) {
    /** Controlled values and server validation errors, deliberately never remembered in history. */
    const form = useForm<Record<string, string>>({
        ...Object.fromEntries(fields.map((field) => [field.name, ''])),
        ...initial,
    });
    /** Submit once, then clear all credential inputs after either success or failure. */
    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(action, {
            onFinish: () => {
                /** Credential fields that must not survive an attempted transition. */
                const sensitive = fields
                    .filter(
                        (field) =>
                            field.type === 'password' ||
                            field.name === 'code' ||
                            field.name === 'recovery_code',
                    )
                    .map((field) => field.name);
                form.reset(...sensitive);
                /** First invalid input, if any, receives focus after Inertia updates errors. */
                window.requestAnimationFrame(() =>
                    document.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus(),
                );
            },
        });
    }
    return (
        <AuthLayout title={title} {...(description ? { description } : {})}>
            <form onSubmit={handleSubmit} className="space-y-5">
                {fields.map((field, index) => (
                    <div key={field.name}>
                        <label htmlFor={field.name} className="mb-2 block text-sm font-medium">
                            {field.label}
                        </label>
                        <input
                            id={field.name}
                            name={field.name}
                            type={field.type ?? 'text'}
                            autoComplete={field.autoComplete}
                            inputMode={field.numeric ? 'numeric' : undefined}
                            autoFocus={index === 0}
                            required
                            value={form.data[field.name] ?? ''}
                            onChange={(event) => form.setData(field.name, event.target.value)}
                            aria-invalid={Boolean(form.errors[field.name])}
                            aria-describedby={
                                form.errors[field.name] ? `${field.name}-error` : undefined
                            }
                            className="w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400"
                        />
                        {form.errors[field.name] && (
                            <p
                                id={`${field.name}-error`}
                                role="alert"
                                className="mt-2 text-sm text-red-300"
                            >
                                {form.errors[field.name]}
                            </p>
                        )}
                    </div>
                ))}
                {remember && (
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            name="remember"
                            checked={form.data.remember === '1'}
                            onChange={(event) =>
                                form.setData('remember', event.target.checked ? '1' : '0')
                            }
                        />
                        Remember me
                    </label>
                )}
                <button
                    disabled={form.processing}
                    className="w-full rounded bg-emerald-400 px-4 py-3 font-semibold text-slate-950 disabled:opacity-50"
                >
                    {form.processing ? 'Please wait…' : submit}
                </button>
            </form>
            <nav
                aria-label="Account navigation"
                className="mt-6 flex flex-wrap gap-4 text-sm text-emerald-300"
            >
                {links.map((link) => (
                    <Link key={link.href} href={link.href}>
                        {link.label}
                    </Link>
                ))}
            </nav>
        </AuthLayout>
    );
}
