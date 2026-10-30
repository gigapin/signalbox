<?php

declare(strict_types=1);

/** Issue a socket/channel-bound native Reverb grant after verified session and current membership checks. */

namespace App\Http\Controllers\Broadcasting;

use App\Broadcasting\TeamChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Broadcasting\AuthorizeBroadcastRequest;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;

/** Register the one channel handler lazily so unconfigured Reverb credentials never break unrelated bootstrap paths. */
final class BroadcastAuthorizationController extends Controller
{
    /**
     * @param BroadcastManager $broadcasts Resolve the native Reverb signer, independently of the publishing driver.
     * @param Repository $config Refuse incomplete credentials before resolving the native client.
     */
    public function __construct(private readonly BroadcastManager $broadcasts, private readonly Repository $config)
    {
    }

    /**
     * @param AuthorizeBroadcastRequest $request Validated private channel/socket identifiers from an authenticated browser.
     * @return JsonResponse Only the native auth signature; no user data, presence payload or application secret.
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException When signing is unavailable or current membership denies access.
     */
    public function __invoke(AuthorizeBroadcastRequest $request): JsonResponse
    {
        abort_unless(
            $this->config->get('broadcasting.connections.reverb.driver') === 'reverb',
            503,
            'Broadcast authorization is unavailable.',
        );

        foreach (['key', 'secret', 'app_id'] as $field) {
            $value = $this->config->get('broadcasting.connections.reverb.'.$field);

            abort_unless(
                is_string($value) && trim($value) !== '',
                503,
                'Broadcast authorization is unavailable.',
            );
        }

        $broadcaster = $this->broadcasts->connection('reverb');
        abort_unless($broadcaster instanceof PusherBroadcaster, 503, 'Broadcast authorization is unavailable.');
        $broadcaster->channel('team.{teamId}', TeamChannel::class, ['guards' => ['web']]);
        $result = $broadcaster->auth($request);

        abort_unless(
            is_array($result) && isset($result['auth']) && is_string($result['auth']),
            503,
            'Broadcast authorization is unavailable.',
        );

        return response()->json(['auth' => $result['auth']]);
    }
}
