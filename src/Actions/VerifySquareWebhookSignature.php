<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Actions\Exceptions\SquareWebhookVerificationException;
use Illuminate\Http\Request;

/**
 * Verifies Square's webhook HMAC signature. Kept as its own single-purpose
 * action (rather than inlined in the controller) so the signature math --
 * the one part of this integration where a subtle mistake silently accepts
 * forged requests -- has a unit-testable surface independent of routing,
 * the database, or any other side effect.
 *
 * Square's scheme differs from Stripe's in the input it signs: Stripe signs
 * the raw body alone, but Square signs the *concatenation* of the
 * subscribed notification URL and the raw body, in that order. Getting the
 * URL wrong (trailing slash, http vs https, wrong host) makes every
 * signature fail even though the shared key is correct -- see
 * config/square-sync.php's notification_url doc block.
 */
class VerifySquareWebhookSignature
{
    public const SIGNATURE_HEADER = 'x-square-hmacsha256-signature';

    /**
     * @throws SquareWebhookVerificationException
     */
    public function handle(Request $request): void
    {
        $signatureKey = config('square-sync.webhook_signature_key');
        $notificationUrl = config('square-sync.notification_url');

        if (blank($signatureKey) || blank($notificationUrl)) {
            throw new SquareWebhookVerificationException(
                SquareWebhookVerificationException::MISSING_CONFIG,
                'Square webhook signature key or notification URL is not configured.'
            );
        }

        $signature = $request->header(self::SIGNATURE_HEADER);

        if (blank($signature)) {
            throw new SquareWebhookVerificationException(
                SquareWebhookVerificationException::MISSING_HEADER,
                'Missing '.self::SIGNATURE_HEADER.' header.'
            );
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $notificationUrl.$request->getContent(),
            $signatureKey,
            true
        ));

        // hash_equals, not ===, so comparing a forged signature can't leak
        // timing information about how many leading bytes matched.
        if (! hash_equals($expected, $signature)) {
            throw new SquareWebhookVerificationException(
                SquareWebhookVerificationException::INVALID_SIGNATURE,
                'Square webhook signature does not match.'
            );
        }
    }
}
