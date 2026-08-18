<?php

namespace Cultpantry\SquareSync\Square;

use App\Actions\RecordEvent;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Thin wrapper over the Http facade -- deliberately not the Square PHP SDK.
 * Going raw over REST buys us three things the SDK can't: every request and
 * response lands in the app's audit Events table for free, Http::fake()
 * gives trivial tests without a second mocking layer, and we're immune to
 * SDK major-version churn since we only ever touch the handful of endpoints
 * this module actually needs.
 */
final class SquareClient
{
    /**
     * Response bodies larger than this are summarised rather than logged in
     * full -- a full catalog page can run into the hundreds of KB, and the
     * events table is an audit trail, not a cache of Square's data.
     */
    private const MAX_LOGGED_BODY_BYTES = 8192;

    private ?CatalogApi $catalogApi = null;

    private ?InventoryApi $inventoryApi = null;

    private ?LocationsApi $locationsApi = null;

    public function __construct(private readonly RecordEvent $recordEvent) {}

    public function catalog(): CatalogApi
    {
        return $this->catalogApi ??= new CatalogApi($this);
    }

    public function inventory(): InventoryApi
    {
        return $this->inventoryApi ??= new InventoryApi($this);
    }

    public function locations(): LocationsApi
    {
        return $this->locationsApi ??= new LocationsApi($this);
    }

    /**
     * @throws SquareException when Square returns an error that either
     *                         isn't retryable, or survived every retry attempt.
     */
    public function request(string $method, string $path, array $payload = [], ?string $correlationId = null): SquareResponse
    {
        $correlationId ??= (string) Str::uuid();

        $requestEvent = $this->recordEvent->handle(
            type: 'square.request',
            description: "Square {$method} {$path}",
            metadata: $this->redact([
                'method' => $method,
                'path' => $path,
                'payload' => $payload,
            ]),
            severity: 'info',
            direction: 'outbound',
            correlationId: $correlationId,
        );

        $response = Http::withToken(config('square-sync.access_token'))
            ->withHeaders(['Square-Version' => config('square-sync.version')])
            ->baseUrl(config('square-sync.base_url'))
            ->timeout(config('square-sync.timeout'))
            ->retry(
                times: config('square-sync.retry_times'),
                // Signature is (int $attempt, Throwable $exception): int --
                // see Illuminate\Support\helpers.php's retry(), which is what
                // PendingRequest::send() ultimately delegates to.
                sleepMilliseconds: fn (int $attempt, Throwable $exception): int => $this->retryDelayMs($exception),
                when: fn (Throwable $exception): bool => $this->isRetryable($exception),
                throw: false,
            )
            ->send($method, $path, $this->requestOptions($method, $payload));

        $squareResponse = new SquareResponse($response);

        if ($squareResponse->ok()) {
            $this->recordEvent->handle(
                type: 'square.response',
                description: "Square {$method} {$path} -> {$response->status()}",
                metadata: $this->redact([
                    'method' => $method,
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $this->loggableBody($response),
                ]),
                severity: 'info',
                direction: 'inbound',
                correlationId: $correlationId,
                parentEvent: $requestEvent,
            );

            return $squareResponse;
        }

        $this->recordEvent->handle(
            type: 'square.error',
            description: "Square {$method} {$path} failed -> {$response->status()}",
            metadata: $this->redact([
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'body' => $this->loggableBody($response),
                'errors' => $squareResponse->errors(),
            ]),
            severity: 'error',
            direction: 'inbound',
            correlationId: $correlationId,
            parentEvent: $requestEvent,
        );

        throw new SquareException($squareResponse->errors(), $response->status());
    }

    /**
     * GET carries its payload as a query string, everything else as a JSON
     * body -- matches how PendingRequest::get()/post()/delete() build their
     * own $options internally, just generalised over an arbitrary $method.
     */
    private function requestOptions(string $method, array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        return strtoupper($method) === 'GET'
            ? ['query' => $payload]
            : ['json' => $payload];
    }

    /**
     * Only 429 (rate limited) and 5xx (Square-side failure) are worth
     * retrying. A 4xx like a validation error will fail identically on
     * every attempt, so retrying it just delays the caller for nothing.
     */
    private function isRetryable(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    /**
     * Square sends Retry-After on 429s (and sometimes 5xx) telling us
     * exactly how long to back off -- honouring it beats guessing with a
     * fixed delay and risking another 429 immediately after.
     */
    private function retryDelayMs(Throwable $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfter = $exception->response->header('Retry-After');

            if (filled($retryAfter)) {
                return is_numeric($retryAfter)
                    ? (int) round(((float) $retryAfter) * 1000)
                    : max(0, strtotime($retryAfter) - time()) * 1000;
            }
        }

        return config('square-sync.retry_sleep_ms');
    }

    /**
     * Decoded JSON when the body is small enough to be useful in the audit
     * log, otherwise a truncated excerpt -- never the raw multi-hundred-KB
     * catalog dump.
     */
    private function loggableBody(Response $response): array|string
    {
        $body = $response->body();

        if (strlen($body) <= self::MAX_LOGGED_BODY_BYTES) {
            return $response->json() ?? $body;
        }

        return [
            'truncated' => true,
            'excerpt' => substr($body, 0, self::MAX_LOGGED_BODY_BYTES),
            'original_bytes' => strlen($body),
        ];
    }

    /**
     * Belt-and-braces redaction: the Authorization header is never included
     * in logged metadata in the first place, but this also scrubs the raw
     * token value should it ever end up inside a payload or response body
     * (e.g. echoed back in a validation error).
     */
    private function redact(array $metadata): array
    {
        $token = (string) config('square-sync.access_token');

        if ($token === '') {
            return $metadata;
        }

        $encoded = json_encode($metadata);
        if ($encoded === false) {
            return $metadata;
        }

        $decoded = json_decode(str_replace($token, '***REDACTED***', $encoded), true);

        return $decoded ?? $metadata;
    }
}
