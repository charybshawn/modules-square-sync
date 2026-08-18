<?php

namespace Cultpantry\SquareSync\Actions\Exceptions;

use RuntimeException;

/**
 * Thrown by VerifySquareWebhookSignature for any verification failure.
 * $reason distinguishes the three distinct failure modes so the controller
 * can map each to the right HTTP status without parsing message text --
 * missing config is an operator error (500, Square will retry forever
 * until it's fixed, but that's correct: we genuinely can't verify), while
 * a missing header or a bad signature might be a malicious or malformed
 * request (400 / 401, and Square won't retry a 400/401 as aggressively as
 * a 5xx).
 */
class SquareWebhookVerificationException extends RuntimeException
{
    public const MISSING_CONFIG = 'missing_config';

    public const MISSING_HEADER = 'missing_header';

    public const INVALID_SIGNATURE = 'invalid_signature';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            self::MISSING_HEADER => 400,
            self::INVALID_SIGNATURE => 401,
            self::MISSING_CONFIG => 500,
            default => 500,
        };
    }
}
