<?php

declare(strict_types=1);

/** Return newly issued heartbeat credentials only through explicit authorized non-cacheable JSON mutations. */

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ManageHeartbeatCredentials;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Neither plaintext credentials nor issuance responses are placed in Inertia props or session flash. */
final class HeartbeatCredentialController
{
    /**
     * @param Request $request Authenticated CSRF-protected web request.
     * @param int $team Route tenant established by middleware.
     * @param Monitor $monitor Tenant-bound monitor, reloaded and authorized under lock.
     * @param ManageHeartbeatCredentials $credentials Dedicated credential lifecycle action.
     * @return JsonResponse One-time plaintext bearer credential and its secret-free endpoint.
     */
    public function store(Request $request, int $team, Monitor $monitor, ManageHeartbeatCredentials $credentials): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return response()->json(['token' => $credentials->rotate($actor, $team, $monitor->id), 'endpoint' => route('heartbeats.receive')]);
    }

    /**
     * @param Request $request Authenticated CSRF-protected web request.
     * @param int $team Established route tenant.
     * @param Monitor $monitor Tenant-bound monitor.
     * @param ManageHeartbeatCredentials $credentials Lock-aware immediate revocation action.
     * @return Response Empty success with no credential material.
     */
    public function destroy(Request $request, int $team, Monitor $monitor, ManageHeartbeatCredentials $credentials): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $credentials->revoke($actor, $team, $monitor->id);

        return response()->noContent();
    }
}
