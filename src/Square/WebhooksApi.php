<?php

namespace Cultpantry\SquareSync\Square;

/**
 * Webhook subscriptions belong to the Square application, not the seller,
 * so these calls only work with the application's personal access token --
 * callers treat a failure here as "can't check", not as a broken sync.
 */
final class WebhooksApi
{
    public function __construct(private readonly SquareClient $client) {}

    /**
     * @return array<int, array{id: string, enabled?: bool, event_types?: array<int, string>, notification_url?: string}>
     */
    public function listSubscriptions(): array
    {
        return $this->client->request('GET', '/v2/webhooks/subscriptions', ['include_disabled' => 'true'])
            ->json('subscriptions') ?? [];
    }

    /**
     * Has Square send a sample event of $eventType to the subscription's
     * notification URL, and reports the HTTP status this app answered with.
     *
     * @return array{status_code?: int, payload?: string}
     */
    public function testSubscription(string $subscriptionId, string $eventType): array
    {
        return $this->client->request('POST', '/v2/webhooks/subscriptions/'.rawurlencode($subscriptionId).'/test', [
            'event_type' => $eventType,
        ])->json('subscription_test_result') ?? [];
    }
}
