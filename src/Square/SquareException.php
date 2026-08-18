<?php

namespace Cultpantry\SquareSync\Square;

use RuntimeException;

/**
 * Thrown by SquareClient::request() for non-retryable API failures (4xx, or
 * a 429/5xx that survived every retry attempt). Carries Square's own error
 * array rather than forcing callers to re-parse the response body.
 */
final class SquareException extends RuntimeException
{
    /**
     * @param  array<int, array{category?: string, code?: string, detail?: string}>  $errors
     */
    public function __construct(
        private readonly array $errors,
        private readonly int $httpStatus,
        ?string $message = null,
    ) {
        parent::__construct($message ?? self::summarize($errors, $httpStatus));
    }

    /**
     * @return array<int, array{category?: string, code?: string, detail?: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    private static function summarize(array $errors, int $status): string
    {
        $detail = $errors[0]['detail'] ?? null;

        return $detail
            ? "Square API error ({$status}): {$detail}"
            : "Square API error ({$status})";
    }
}
