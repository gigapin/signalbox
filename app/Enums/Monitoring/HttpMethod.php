<?php

declare(strict_types=1);

/** Restrict HTTP monitor methods to ordinary application request verbs. */

namespace App\Enums\Monitoring;

/** Restrict HTTP monitor methods to ordinary application request verbs. */
enum HttpMethod: string
{
    /** Send an HTTP GET request. */
    case GET = 'GET';

    /** Send an HTTP HEAD request. */
    case HEAD = 'HEAD';

    /** Send an HTTP POST request. */
    case POST = 'POST';

    /** Send an HTTP PUT request. */
    case PUT = 'PUT';

    /** Send an HTTP PATCH request. */
    case PATCH = 'PATCH';

    /** Send an HTTP DELETE request. */
    case DELETE = 'DELETE';

    /** Send an HTTP OPTIONS request. */
    case OPTIONS = 'OPTIONS';
}
