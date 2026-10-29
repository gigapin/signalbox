<?php

declare(strict_types=1);

/** Translate dedicated heartbeat authentication and traffic outcomes into minimal public HTTP responses. */

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ReceiveHeartbeat;
use App\Exceptions\HeartbeatRateLimited;
use App\Http\Requests\Monitoring\HeartbeatRequest;
use Symfony\Component\HttpFoundation\Response;

/** This endpoint never publishes monitor, tenant, plan or configuration details. */
final class HeartbeatController
{
    /**
     * @param HeartbeatRequest $request Authorization-only ingress; all supplied payload values are ignored.
     * @param ReceiveHeartbeat $receive Lock-aware receiver with fresh credential and plan admission.
     * @return Response Empty 204, uniform 404, or a generic retryable 429.
     */
    public function __invoke(HeartbeatRequest $request, ReceiveHeartbeat $receive): Response
    {
        try {
            return $receive->receive($request->credential()) ? response()->noContent() : response()->json(['message' => 'Not found.'], 404);
        } catch (HeartbeatRateLimited $error) {
            return response()->json(['message' => $error->getMessage()], 429, ['Retry-After' => (string) $error->retryAfterSeconds]);
        }
    }
}
