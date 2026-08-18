<?php

namespace Cultpantry\SquareSync\Square;

use Illuminate\Http\Client\Response;

/**
 * Small value object around Illuminate's Http\Client\Response so the rest of
 * this module never has to know Square's response envelope shape (errors
 * array, cursor field) more than once.
 */
final class SquareResponse
{
    public function __construct(private readonly Response $response) {}

    public function ok(): bool
    {
        return $this->response->successful();
    }

    public function json(?string $key = null): mixed
    {
        return $this->response->json($key);
    }

    public function status(): int
    {
        return $this->response->status();
    }

    /**
     * @return array<int, array{category?: string, code?: string, detail?: string}>
     */
    public function errors(): array
    {
        return $this->response->json('errors') ?? [];
    }

    public function cursor(): ?string
    {
        return $this->response->json('cursor');
    }

    /**
     * Escape hatch to the underlying response for callers that need
     * something this wrapper doesn't expose (headers, raw body, etc.).
     */
    public function raw(): Response
    {
        return $this->response;
    }

    /**
     * Combines several chunked responses (see InventoryApi::batchChangeInventory)
     * into one logical SquareResponse: arrays merge key-by-key, "errors"
     * accumulates across chunks, and the status is the worst of the bunch so
     * a single failed chunk still surfaces as a failure to the caller.
     *
     * @param  array<int, self>  $responses
     */
    public static function aggregate(array $responses): self
    {
        if (count($responses) === 1) {
            return $responses[0];
        }

        $status = 200;
        $merged = ['errors' => []];

        foreach ($responses as $response) {
            $status = max($status, $response->status());

            foreach ((array) $response->json() as $key => $value) {
                if ($key === 'errors') {
                    $merged['errors'] = array_merge($merged['errors'], (array) $value);

                    continue;
                }

                $merged[$key] = is_array($value)
                    ? array_merge((array) ($merged[$key] ?? []), $value)
                    : $value;
            }
        }

        $psrResponse = new \GuzzleHttp\Psr7\Response($status, [], json_encode($merged));

        return new self(new Response($psrResponse));
    }
}
