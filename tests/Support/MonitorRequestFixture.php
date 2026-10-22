<?php

declare(strict_types=1);

/** Register monitor write paths only inside test applications, without adding production CRUD. */

namespace Tests\Support;

use App\Data\Monitoring\ValidatedMonitorInput;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Requests\Monitoring\StoreMonitorRequest;
use App\Http\Requests\Monitoring\UpdateMonitorRequest;
use App\Models\Monitor;
use Closure;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/** Capture validated values in memory; HTTP responses never contain secrets or persist monitor input. */
final class MonitorRequestFixture
{
    /** @param Closure(ValidatedMonitorInput, mixed): void $observe Private test observation callback. */
    public static function routes(Closure $observe): void
    {
        Route::middleware(['web', 'auth', 'verified', EstablishTeamContext::class])->group(function () use ($observe): void {
            Route::post('/teams/{team}/monitors', function (StoreMonitorRequest $request, string $team) use ($observe): Response {
                $observe($request->monitorInput(), $request->validated());

                return response()->noContent();
            });
            Route::match(['PUT', 'PATCH'], '/teams/{team}/monitors/{monitor}', function (UpdateMonitorRequest $request, string $team, Monitor $monitor) use ($observe): Response {
                $observe($request->monitorInput(), $request->validated());

                return response()->noContent();
            });
        });
    }
}
