<?php

/**
 * Connect Laravel web responses to the Inertia 2 client application.
 */

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

/** Share product identity and explicit account projections without tenant or credential data. */
final class HandleInertiaRequests extends Middleware
{
    /** @var string Blade view used for the initial HTML response. */
    protected $rootView = 'app';

    /**
     * Combine standard protocol props with product name, minimal account identity and safe status.
     *
     * @param Request $request Incoming web request used by Inertia's base middleware.
     * @return array<string, mixed> Shared props serialized for the current page.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'realtime' => $this->realtime($request),
            // Serialize a deliberate identity projection rather than the Eloquent user or its relationships.
            'auth' => ['user' => $request->user() === null ? null : [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                // Only the viewer's own flag, to show the admin link; the panel re-checks on every request.
                'isPlatformAdmin' => $request->user()->is_platform_admin === true,
            ]],
            'status' => match ($request->session()->get('status')) {
                'team-switched' => 'Current team updated.',
                'member-updated' => 'Member role updated.',
                'member-removed' => 'Membership removed.',
                'ownership-transferred' => 'Primary ownership transferred.',
                'invitation-accepted' => 'You have joined the team.',
                'invitation-sent' => 'Invitation sent. Previous links are invalid after a resend.',
                'invitation-revoked' => 'Invitation revoked.',
                'monitor-created' => 'Monitor created.',
                'monitor-updated' => 'Monitor updated.',
                'monitor-deleted' => 'Monitor deleted.',
                'monitor-paused' => 'Monitor paused.',
                'monitor-resumed' => 'Monitor resumed.',
                'incident-acknowledged' => 'You are responsible for this incident.',
                'incident-update-posted' => 'Timeline update added. It stays private until published.',
                'incident-severity-changed' => 'Severity saved.',
                'incident-root-cause-saved' => 'Declared cause saved.',
                'incident-update-published' => 'Update published.',
                'maintenance-scheduled' => 'Maintenance scheduled.',
                'maintenance-rescheduled' => 'Maintenance rescheduled.',
                'maintenance-deleted' => 'Maintenance deleted.',
                'maintenance-ended' => 'Maintenance ended now.',
                'channel-created' => 'Channel created. Send a verification code to activate it.',
                'channel-updated' => 'Channel saved. A changed destination must be verified again.',
                'channel-deleted' => 'Channel deleted.',
                'channel-code-sent' => 'Verification code sent. Enter it before it expires.',
                'channel-verified' => 'Channel verified and active.',
                'channel-activated' => 'Channel active.',
                'channel-deactivated' => 'Channel paused. It receives no notifications.',
                'channel-quiet-hours-updated' => 'Channel quiet hours updated.',
                'policy-created' => 'Escalation policy created.',
                'policy-updated' => 'Escalation policy saved.',
                'policy-deleted' => 'Escalation policy deleted.',
                'monitor-escalation-saved' => 'Escalation policy for this monitor saved.',
                'plan-override-saved' => 'Plan override saved.',
                'plan-override-cleared' => 'Plan override removed.',
                'platform-admin-two-factor-required' => 'Enable two-factor authentication to use the admin panel.',
                'verification-link-sent' => 'A new verification link has been sent to your email.',
                'two-factor-authentication-enabled' => 'Scan the setup code, then confirm your authenticator.',
                'two-factor-authentication-confirmed' => 'Two-factor authentication is enabled.',
                'two-factor-authentication-disabled' => 'Two-factor authentication is disabled.',
                'recovery-codes-generated' => 'Recovery codes have been replaced. Save the new set.',
                default => $request->session()->get('status'),
            },
        ];
    }

    /**
     * @param Request $request Current browser request; its hostname is the default public WebSocket host.
     * @return array{key: string, host: string, port: int, scheme: string}|null Public connection coordinates for verified sessions only.
     */
    private function realtime(Request $request): ?array
    {
        $actor = $request->user('web');

        if (!$actor instanceof User || !$actor->hasVerifiedEmail() || config('broadcasting.default') !== 'reverb') {
            return null;
        }

        foreach (['key', 'secret', 'app_id'] as $field) {
            $value = config('broadcasting.connections.reverb.'.$field);

            if (!is_string($value) || trim($value) === '') {
                return null;
            }
        }

        $key = config('broadcasting.connections.reverb.key');
        $host = config('broadcasting.browser.host');

        if ($host === null || $host === '') {
            $host = $request->getHost();
        }

        $port = config('broadcasting.browser.port');
        $scheme = config('broadcasting.browser.scheme');

        if (!is_string($key) || !is_string($host) || $host === ''
            || !is_int($port) || $port < 1 || $port > 65535
            || !in_array($scheme, ['http', 'https'], true)
            || ($request->isSecure() && $scheme !== 'https')) {
            return null;
        }

        return ['key' => $key, 'host' => $host, 'port' => $port, 'scheme' => $scheme];
    }
}
