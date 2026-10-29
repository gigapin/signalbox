<?php

declare(strict_types=1);

/** Classify known transport failures without reading their potentially private messages. */

namespace App\Services\Monitoring\Checks;

use App\Enums\Monitoring\CheckError;
use App\Exceptions\CheckTransportFailed;
use App\Exceptions\OutboundDestinationRejected;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/** Unknown failures propagate so programming errors are not reported as target outages. */
final class HttpTransportErrors
{
    /**
     * @param Throwable $error Transport or policy failure with potentially sensitive nested messages.
     * @return CheckError Safe classification for recognized failures.
     * @throws Throwable When the failure is not a recognized transport or policy exception.
     */
    public function classify(#[\SensitiveParameter] Throwable $error): CheckError
    {
        if ($error instanceof CheckTransportFailed) {
            return $error->reason;
        }

        if ($error instanceof OutboundDestinationRejected) {
            return CheckError::from($error->reason->value);
        }

        $current = $error;

        do {
            if ($current instanceof RequestException || $current instanceof ConnectException) {
                $errno = $current->getHandlerContext()['errno'] ?? null;

                return match (true) {
                    $errno === 28 => CheckError::Timeout,
                    $errno === 6 => CheckError::ResolutionFailed,
                    in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) => CheckError::Tls,
                    default => CheckError::Connection,
                };
            }

            $current = $current->getPrevious();
        } while ($current !== null);

        if ($error instanceof ConnectionException || $error instanceof TransferException) {
            return CheckError::Connection;
        }

        throw $error;
    }
}
