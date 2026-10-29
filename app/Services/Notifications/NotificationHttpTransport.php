<?php

declare(strict_types=1);

/** Send one bounded JSON POST through the shared SSRF and pinned-connection boundary. */

namespace App\Services\Notifications;

use App\Exceptions\ChannelDeliveryFailed;
use App\Exceptions\OutboundDestinationRejected;
use App\Services\Http\BoundedResponseBuffer;
use App\Services\Http\ResponseByteBudget;
use App\Services\Security\GuardedHttpOptions;
use App\Services\Security\OutboundDestinationGuard;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Redirects are refused entirely: POST bodies, bot tokens and signatures never reach another destination. */
final readonly class NotificationHttpTransport
{
    /**
     * @param OutboundDestinationGuard $guard Resolves and checks every address again for each attempt.
     * @param GuardedHttpOptions $options Pins connections and disables redirects, reuse and proxies.
     * @param Repository $config Operational HTTP limits; no request may override security options.
     */
    public function __construct(
        private OutboundDestinationGuard $guard,
        private GuardedHttpOptions $options,
        private Repository $config,
    ) {
    }

    /**
     * @param array<string, mixed> $payload Explicitly selected provider fields.
     * @return string Exact UTF-8 bytes used both for signing and transmission.
     * @throws ChannelDeliveryFailed When content is not valid JSON.
     */
    public function encode(#[\SensitiveParameter] array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new ChannelDeliveryFailed('The notification content could not be encoded.');
        }
    }

    /**
     * @param string $url Validated HTTPS endpoint; authorized again immediately before this attempt.
     * @param string $body Exact encoded JSON; never logged or forwarded across redirects.
     * @param array<string, string> $headers Provider headers, including any webhook signature.
     * @return Response Bounded response to inspect transiently; never persist its body.
     * @throws ChannelDeliveryFailed On blocked destinations, transport errors, excessive responses or non-2xx status.
     */
    public function post(
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] string $body,
        #[\SensitiveParameter] array $headers = [],
    ): Response {
        $buffer = null;

        try {
            $destination = $this->guard->authorizeUrl($url);

            if ($destination->scheme !== 'https') {
                throw new ChannelDeliveryFailed('Notification endpoints must use HTTPS.');
            }

            $budget = new ResponseByteBudget($this->limit('response_max_bytes', 1024, 65536));
            $buffer = new BoundedResponseBuffer($budget);
            $response = Http::withOptions([
                ...$this->options->for($destination),
                'cookies' => false,
                'verify' => true,
                'decode_content' => true,
                'sink' => $buffer->sink(),
            ])
                ->acceptJson()
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->timeout($this->limit('timeout_seconds', 1, 10))
                ->connectTimeout($this->limit('connect_timeout_seconds', 1, 5))
                ->post((string) $destination->url);

            if ($budget->exceeded) {
                throw new ChannelDeliveryFailed('The notification provider response exceeded the size limit.');
            }

            if (!$response->successful()) {
                throw new ChannelDeliveryFailed('The notification provider refused the request (HTTP '.$response->status().').');
            }

            // Detach the bounded content before closing its sink; provider bodies remain transient.
            $response = new Response($response->toPsrResponse()->withBody(\GuzzleHttp\Psr7\Utils::streamFor($buffer->contents())));

            return $response;
        } catch (OutboundDestinationRejected) {
            throw new ChannelDeliveryFailed('The notification destination was refused by outbound security.');
        } catch (ConnectionException|TransferException $exception) {
            // Never chain the transport exception: its message and request may contain credentials.
            throw new ChannelDeliveryFailed($budget->exceeded
                ? 'The notification provider response exceeded the size limit.'
                : 'The notification provider could not be reached within the connection limits.');
        } catch (RuntimeException $exception) {
            if ($exception instanceof ChannelDeliveryFailed) {
                throw $exception;
            }

            throw new ChannelDeliveryFailed('The notification response could not be processed safely.');
        } finally {
            $buffer?->close();
        }
    }

    /**
     * @param string $key HTTP setting name within notifications.http.
     * @param int $minimum Smallest valid value.
     * @param int $maximum Hard ceiling for synchronous verification requests.
     * @return int Configured bounded value.
     * @throws InvalidArgumentException When an operational limit is absent or outside its safe range.
     */
    private function limit(string $key, int $minimum, int $maximum): int
    {
        $value = $this->config->get('notifications.http.'.$key);

        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('The notification HTTP limits are invalid.');
        }

        return $value;
    }
}
