<?php

namespace Cultpantry\SquareSync\Square;

final class OAuthApi
{
    public function __construct(private readonly SquareClient $client) {}

    /**
     * Which merchant the access token belongs to, what it's allowed to do,
     * and when it expires. Works for personal access tokens as well as
     * OAuth tokens, and fails with 401 once a token is revoked or expired.
     *
     * @return array{merchant_id: string|null, scopes: array<int, string>|null, expires_at: string|null}
     */
    public function tokenStatus(): array
    {
        $response = $this->client->request('POST', '/oauth2/token/status');

        return [
            'merchant_id' => $response->json('merchant_id'),
            // Null (not []) when Square doesn't list them, so callers can
            // tell "no scopes" from "unknown".
            'scopes' => $response->json('scopes'),
            'expires_at' => filled($response->json('expires_at')) ? $response->json('expires_at') : null,
        ];
    }
}
